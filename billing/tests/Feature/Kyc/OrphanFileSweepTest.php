<?php

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Actions\SweepOrphanFiles;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\StoredImageReencoder;
use App\Support\Automation\Automation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    Cache::forget(SweepOrphanFiles::CURSOR);
    $this->client = Client::factory()->create();
});

/** Stores a KYC file and sets its modification time $hours ago. */
function kycFileAged(string $path, int $hours): string
{
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put($path, 'encrypted');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());

    return $path;
}

function kycOrphanPath(int $clientId): string
{
    return 'kyc/'.$clientId.'/'.Str::uuid()->toString();
}

test('the sweep is a housekeeping task', function () {
    expect(app(Automation::class)->ordered()['kyc.sweep_orphan_files']['stage'] ?? null)->toBe(Automation::HOUSEKEEPING);
});

test('an unreferenced file older than a day is deleted; referenced, recent and unrecognised files are kept', function () {
    $document = KycDocument::factory()->create([
        'client_id' => $this->client->id,
        'front_path' => kycFileAged(kycOrphanPath($this->client->id), 500),
        'back_path' => kycFileAged(kycOrphanPath($this->client->id), 500),
    ]);
    $orphan = kycFileAged(kycOrphanPath($this->client->id), 25);
    $recent = kycFileAged(kycOrphanPath($this->client->id), 23);
    $unrecognised = kycFileAged('kyc/notes/'.Str::uuid()->toString(), 500);
    $nested = kycFileAged('kyc/'.$this->client->id.'/extra/'.Str::uuid()->toString(), 500);
    Log::spy();

    $this->artisan('automation:run', ['--only' => 'kyc.sweep_orphan_files'])->assertSuccessful();

    $disk = Storage::disk(KycDocument::DISK);
    expect($disk->exists($orphan))->toBeFalse()
        ->and($disk->exists($document->front_path))->toBeTrue()
        ->and($disk->exists($document->back_path))->toBeTrue()
        ->and($disk->exists($recent))->toBeTrue()
        ->and($disk->exists($unrecognised))->toBeTrue()
        ->and($disk->exists($nested))->toBeTrue();
    // Counts and client ids only: paths never reach the log (spec §5).
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'KYC orphan sweep finished.'
        && $context['deleted'] === 1 && $context['client_ids'] === [$this->client->id] && ! str_contains(json_encode($context), 'kyc/'));
});

test('a superseded document still refers to its files, and so does a half-finished rewrite', function () {
    $superseded = KycDocument::factory()->create([
        'client_id' => $this->client->id,
        'front_path' => kycFileAged(kycOrphanPath($this->client->id), 500),
        'back_path' => null,
        'superseded_at' => now()->subDays(3),
    ]);
    $rewriteTarget = kycFileAged(kycOrphanPath($this->client->id), 500);
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => $superseded->id, 'old_path' => $superseded->front_path, 'new_path' => $rewriteTarget, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(SweepOrphanFiles::class)->handle())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->exists($superseded->front_path))->toBeTrue()
        ->and(Storage::disk(KycDocument::DISK)->exists($rewriteTarget))->toBeTrue();
});

test('a file a row starts referring to after the listing is kept by the check under the lock', function () {
    $path = kycFileAged(kycOrphanPath($this->client->id), 500);
    $written = false;
    // Between the per-client read and the delete, another process writes a row for the file.
    DB::listen(function ($query) use (&$written, $path): void {
        if (! $written && str_contains($query->sql, 'for update')) {
            $written = true;
            KycDocument::factory()->create(['client_id' => $this->client->id, 'front_path' => $path, 'back_path' => null]);
        }
    });

    $deleted = app(SweepOrphanFiles::class)->handle();

    expect($deleted)->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->exists($path))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs row locks (PostgreSQL)');

test('a run deletes at most the delete limit; the rest wait for the next run', function () {
    config(['kyc.orphan_delete_limit' => 2]);
    KycDocument::factory()->create(['client_id' => $this->client->id]); // with no documents at all the breaker stops the run
    foreach (range(1, 3) as $i) {
        kycFileAged(kycOrphanPath($this->client->id), 30);
    }

    expect(app(SweepOrphanFiles::class)->handle())->toBe(2)
        ->and(Storage::disk(KycDocument::DISK)->allFiles('kyc'))->toHaveCount(1)
        ->and(app(SweepOrphanFiles::class)->handle())->toBe(1);
});

test('a run looks at most at the scan limit and the next run continues after the last file it looked at', function () {
    config(['kyc.orphan_scan_limit' => 2]);
    KycDocument::factory()->create(['client_id' => $this->client->id]);
    $other = Client::factory()->create();
    $paths = [kycFileAged(kycOrphanPath($this->client->id), 30), kycFileAged(kycOrphanPath($this->client->id), 30), kycFileAged(kycOrphanPath($other->id), 30)];

    expect(app(SweepOrphanFiles::class)->handle())->toBe(2)
        ->and(Cache::get(SweepOrphanFiles::CURSOR))->toBeString()
        ->and(app(SweepOrphanFiles::class)->handle())->toBe(1)
        ->and(collect($paths)->filter(fn (string $path) => Storage::disk(KycDocument::DISK)->exists($path)))->toBeEmpty()
        ->and(Cache::get(SweepOrphanFiles::CURSOR))->toBeNull();
});
