<?php

use App\Events\Core\ClientClosed;
use App\Events\Core\ClientReopened;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\PruneDocuments;
use App\Modules\Kyc\Listeners\ScheduleDocumentRetention;
use App\Modules\Kyc\Models\KycDocument;
use App\Support\Automation\Automation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    CarbonImmutable::setTestNow('2026-10-21 20:00:00'); // 01:45 on 22 October in Kathmandu
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id]);
    foreach ($this->document->paths() as $path) {
        Storage::disk(KycDocument::DISK)->put($path, 'encrypted');
    }
    $this->close = function (): void {
        $this->client->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        ClientClosed::dispatch($this->client->id);
    };
});

test('the retention task is registered in housekeeping', function () {
    expect(app(Automation::class)->ordered())->toHaveKey('kyc.prune_documents')
        ->and(app(Automation::class)->ordered()['kyc.prune_documents']['stage'])->toBe(Automation::HOUSEKEEPING);
});

test('documents of a closed account are deleted 90 days after the local closing date, not before, once', function () {
    ($this->close)();
    expect($this->document->fresh()->delete_after->toDateString())->toBe('2027-01-20');

    $prune = app(PruneDocuments::class);
    expect($prune->handle(CarbonImmutable::parse('2027-01-19')))->toBe(0)
        ->and(KycDocument::query()->count())->toBe(1);

    expect($prune->handle(CarbonImmutable::parse('2027-01-20')))->toBe(1)
        ->and(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([])
        ->and($prune->handle(CarbonImmutable::parse('2027-01-20')))->toBe(0);
});

test('a reopened account keeps its documents, whatever order the close and reopen jobs run in', function () {
    ($this->close)();
    $this->client->forceFill(['status' => 'active', 'closed_at' => null])->save();
    ClientReopened::dispatch($this->client->id);
    expect($this->document->fresh()->delete_after)->toBeNull();

    app(ScheduleDocumentRetention::class)->handle(new ClientClosed($this->client->id)); // a late close job
    expect($this->document->fresh()->delete_after)->toBeNull()
        ->and(app(PruneDocuments::class)->handle(CarbonImmutable::parse('2030-01-01')))->toBe(0);
});

test('one document that cannot be deleted is kept for the next run and never stops the rest', function () {
    $second = KycDocument::factory()->create(['client_id' => $this->client->id]);
    KycDocument::query()->update(['delete_after' => '2026-10-01']);
    $disk = Mockery::mock(Storage::disk(KycDocument::DISK))->makePartial();
    $disk->shouldReceive('delete')->andReturnUsing(fn (string $path): bool => $path === $this->document->front_path ? throw new RuntimeException('storage down') : true);
    Storage::set(KycDocument::DISK, $disk);

    expect(app(PruneDocuments::class)->handle(CarbonImmutable::parse('2026-10-22')))->toBe(1)
        ->and(KycDocument::query()->pluck('id')->all())->toBe([$this->document->id])
        ->and($second->fresh())->toBeNull();
});

test('a run deletes at most the batch size; the rest wait for the next run', function () {
    config(['kyc.prune_batch' => 2]);
    KycDocument::factory()->count(2)->create(['client_id' => $this->client->id]);
    KycDocument::query()->update(['delete_after' => '2026-10-01']);

    expect(app(PruneDocuments::class)->handle(CarbonImmutable::parse('2026-10-22')))->toBe(2)
        ->and(KycDocument::query()->count())->toBe(1)
        ->and(app(PruneDocuments::class)->handle(CarbonImmutable::parse('2026-10-22')))->toBe(1);
});
