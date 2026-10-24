<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Actions\DeleteOrphanFiles;
use App\Modules\Kyc\Actions\FindOrphanFiles;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Models\KycOrphanFile;
use App\Modules\Kyc\Services\StoredImageReencoder;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    Cache::forget(FindOrphanFiles::CURSOR);
    $this->client = Client::factory()->create();
});

/** Stores a KYC file and sets its modification time $hours ago. */
function kycOrphanAged(string $path, int $hours): string
{
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put($path, 'encrypted');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());

    return $path;
}

function kycOrphanName(int $clientId): string
{
    return 'kyc/'.$clientId.'/'.Str::uuid()->toString();
}

test('the check is a housekeeping task', function () {
    expect(app(Automation::class)->ordered()['kyc.find_orphan_files']['stage'] ?? null)->toBe(Automation::HOUSEKEEPING);
});

test('the check records unreferenced files older than a day and deletes nothing', function () {
    $document = KycDocument::factory()->create([
        'client_id' => $this->client->id,
        'front_path' => kycOrphanAged(kycOrphanName($this->client->id), 500),
        'back_path' => kycOrphanAged(kycOrphanName($this->client->id), 500),
    ]);
    $orphan = kycOrphanAged(kycOrphanName($this->client->id), 25);
    $recent = kycOrphanAged(kycOrphanName($this->client->id), 23);
    kycOrphanAged('kyc/notes/'.Str::uuid()->toString(), 500);
    kycOrphanAged('kyc/'.$this->client->id.'/extra/'.Str::uuid()->toString(), 500);
    // A document on another client than its path names still refers to its file.
    $other = Client::factory()->create();
    $moved = kycOrphanAged(kycOrphanName($other->id), 500);
    KycDocument::factory()->create(['client_id' => $this->client->id, 'front_path' => $moved, 'back_path' => null]);
    // Both paths of a half-finished re-encode belong to the re-encoder.
    $rewrite = kycOrphanAged(kycOrphanName($this->client->id), 500);
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => $document->id, 'old_path' => $document->front_path, 'new_path' => $rewrite, 'created_at' => now(), 'updated_at' => now()]);
    Log::spy();

    $this->artisan('automation:run', ['--only' => 'kyc.find_orphan_files'])->assertSuccessful();

    expect(KycOrphanFile::query()->pluck('path')->all())->toBe([$orphan])
        ->and(Storage::disk(KycDocument::DISK)->allFiles('kyc'))->toHaveCount(8)
        ->and(Storage::disk(KycDocument::DISK)->exists($recent))->toBeTrue();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'KYC orphan check finished.'
        && $context['found'] === 1 && ! str_contains(json_encode($context), 'kyc/'));
});

test('a record is dropped once its file is referenced again, or gone on a full pass', function () {
    $adopted = kycOrphanAged(kycOrphanName($this->client->id), 30);
    $gone = kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    expect(KycOrphanFile::query()->count())->toBe(2);

    KycDocument::factory()->create(['client_id' => $this->client->id, 'front_path' => $adopted, 'back_path' => null]);
    Storage::disk(KycDocument::DISK)->delete($gone);
    app(FindOrphanFiles::class)->handle();

    expect(KycOrphanFile::query()->count())->toBe(0);
});

test('a run looks at most at the scan limit and the next run continues after it', function () {
    config(['kyc.orphan_scan_limit' => 2]);
    foreach (range(1, 3) as $i) {
        kycOrphanAged(kycOrphanName($this->client->id), 30);
    }

    expect(app(FindOrphanFiles::class)->handle())->toBe(2)
        ->and(Cache::get(FindOrphanFiles::CURSOR))->toBeString()
        ->and(app(FindOrphanFiles::class)->handle())->toBe(1)
        ->and(Cache::get(FindOrphanFiles::CURSOR))->toBeNull()
        ->and(KycOrphanFile::query()->count())->toBe(3);
});

test('staff see the list without paths, and it shows under Needs attention', function () {
    $path = kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    $user = actingAsStaff(['kyc.documents.review']);

    $this->get(route('admin.kyc.orphans.index'))
        ->assertOk()
        ->assertDontSee($path)
        ->assertInertia(fn (Assert $page) => $page->component('admin/kyc/orphans')->has('files', 1)
            ->where('files.0.client.name', $this->client->display_name)->missing('files.0.path')->where('digest', fn ($digest) => strlen($digest) === 64));
    $this->get(route('admin.kyc.index'))->assertInertia(fn (Assert $page) => $page->where('orphanFiles', 1));

    expect(collect(app(Dashboard::class)->resolveTodos('admin', $user))->firstWhere('key', 'kyc.orphan_files')['count'] ?? null)->toBe(1);
});

test('only the review permission sees or deletes the list, and deleting needs a recent password', function () {
    kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    $digest = app(DeleteOrphanFiles::class)->snapshot()['digest'];

    actingAsStaff(['kyc.documents.view']);
    $this->get(route('admin.kyc.orphans.index'))->assertForbidden();
    $this->get(route('admin.kyc.index'))->assertInertia(fn (Assert $page) => $page->where('orphanFiles', null));

    actingAsStaff(['kyc.documents.review']);
    $this->delete(route('admin.kyc.orphans.destroy'), ['digest' => $digest])->assertRedirect();
    expect(KycOrphanFile::query()->count())->toBe(1);
});

test('staff delete the listed files after a step-up; each is checked again first, and the delete is audited', function () {
    $orphan = kycOrphanAged(kycOrphanName($this->client->id), 30);
    $adopted = kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    $digest = app(DeleteOrphanFiles::class)->snapshot()['digest'];
    // Between the page and the confirm, a document starts referring to one of them.
    KycDocument::factory()->create(['client_id' => $this->client->id, 'front_path' => $adopted, 'back_path' => null]);
    actingAsStaff(['kyc.documents.review']);
    confirmPassword();

    $this->delete(route('admin.kyc.orphans.destroy'), ['digest' => $digest])->assertRedirect(route('admin.kyc.orphans.index'));

    expect(Storage::disk(KycDocument::DISK)->exists($orphan))->toBeFalse()
        ->and(Storage::disk(KycDocument::DISK)->exists($adopted))->toBeTrue()
        ->and(KycOrphanFile::query()->count())->toBe(0);
    $entry = ActivityLog::query()->where('event', 'kyc.orphans.deleted')->sole();
    expect($entry->properties)->toBe(['count' => 1, 'client_ids' => [$this->client->id]]);
});

test('a confirm for a list that changed since the page was shown deletes nothing', function () {
    $first = kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    $digest = app(DeleteOrphanFiles::class)->snapshot()['digest'];
    $second = kycOrphanAged(kycOrphanName($this->client->id), 30);
    app(FindOrphanFiles::class)->handle();
    actingAsStaff(['kyc.documents.review']);
    confirmPassword();

    $this->delete(route('admin.kyc.orphans.destroy'), ['digest' => $digest])->assertSessionHasErrors('digest');

    expect(Storage::disk(KycDocument::DISK)->exists($first))->toBeTrue()
        ->and(Storage::disk(KycDocument::DISK)->exists($second))->toBeTrue()
        ->and(KycOrphanFile::query()->count())->toBe(2);
});
