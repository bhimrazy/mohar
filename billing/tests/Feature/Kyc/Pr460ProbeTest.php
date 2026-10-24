<?php

use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\SweepOrphanFiles;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\UsageSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\Services\ServicesTestKit;

/*
 * Review probes of #460, adopted: A (cursor with the delete limit), B (no documents at all), C (a future --date), plus
 * the circuit breaker, the path-only re-check and the automation-log window the review asked for.
 */

function pr460File(string $path, int $hours): string
{
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put($path, 'x');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());

    return $path;
}

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    Cache::forget(SweepOrphanFiles::CURSOR);
});

test('probe A: the delete limit leaves the cursor before the first orphan it did not delete', function () {
    $client = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $client->id]);
    config(['kyc.orphan_scan_limit' => 3, 'kyc.orphan_delete_limit' => 1]);
    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        pr460File("kyc/{$client->id}/{$name}", 30);
    }
    $left = fn () => collect(['a', 'b', 'c', 'd', 'e'])->filter(fn ($name) => Storage::disk(KycDocument::DISK)->exists("kyc/{$client->id}/{$name}"))->values()->all();

    app(SweepOrphanFiles::class)->handle();
    expect(Cache::get(SweepOrphanFiles::CURSOR))->toBe("kyc/{$client->id}/a");

    app(SweepOrphanFiles::class)->handle();
    expect($left())->toBe(['c', 'd', 'e']);
});

test('probe B: with no documents at all the sweep deletes nothing and reports it', function () {
    Exceptions::fake();
    Log::spy();
    $client = Client::factory()->create();
    $front = pr460File("kyc/{$client->id}/front", 500);
    $back = pr460File("kyc/{$client->id}/back", 500);

    expect(app(SweepOrphanFiles::class)->handle())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->exists($front))->toBeTrue()
        ->and(Storage::disk(KycDocument::DISK)->exists($back))->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'circuit breaker') && $context['unreferenced'] === 2 && ! str_contains(json_encode($context), 'kyc/'));
});

test('more unreferenced files than the breaker allows: nothing is deleted and the cursor stays', function () {
    Exceptions::fake();
    config(['kyc.orphan_breaker' => 2]);
    $client = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $client->id]);
    Cache::forever(SweepOrphanFiles::CURSOR, 'kyc/0');
    $paths = array_map(fn (string $name) => pr460File("kyc/{$client->id}/{$name}", 30), ['a', 'b', 'c']);

    expect(app(SweepOrphanFiles::class)->handle())->toBe(0)
        ->and(collect($paths)->every(fn (string $path) => Storage::disk(KycDocument::DISK)->exists($path)))->toBeTrue()
        ->and(Cache::get(SweepOrphanFiles::CURSOR))->toBe('kyc/0');
    Exceptions::assertReported(RuntimeException::class);

    config(['kyc.orphan_breaker' => 3]);
    expect(app(SweepOrphanFiles::class)->handle())->toBe(3);
});

test('a document on another client than its path names still keeps its file', function () {
    $owner = Client::factory()->create();
    $other = Client::factory()->create();
    $path = pr460File("kyc/{$other->id}/moved", 500);
    KycDocument::factory()->create(['client_id' => $owner->id, 'front_path' => $path, 'back_path' => null]);

    expect(app(SweepOrphanFiles::class)->handle())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->exists($path))->toBeTrue();
});

test('probe C: a future --date does not widen any retention cutoff', function () {
    ServicesTestKit::bind();
    DB::table('gateway_log')->insert(['gateway' => 'esewa', 'direction' => 'response', 'result' => 'ten days old', 'payload' => '{}', 'created_at' => now()->subDays(10)]);
    DB::table('activity_log')->insert(['description' => 'Ten days old', 'client_id' => null, 'created_at' => now()->subDays(40)]);
    $service = Service::factory()->create();
    UsageSnapshot::query()->create(['service_id' => $service->id, 'disk_used_mb' => 1, 'bw_used_mb' => 0, 'captured_at' => now()->subDays(10)]);
    UsageSnapshot::query()->create(['service_id' => $service->id, 'disk_used_mb' => 2, 'bw_used_mb' => 0, 'captured_at' => now()]);
    $future = now()->addYears(2)->toDateString();

    foreach (['billing.gateway_log_prune', 'services.usage_prune', 'platform.prune_activity_log'] as $task) {
        $this->artisan('automation:run', ['--only' => $task, '--date' => $future])->assertSuccessful();
    }

    expect(GatewayLog::query()->count())->toBe(1)
        ->and(UsageSnapshot::query()->count())->toBe(2)
        ->and(DB::table('activity_log')->where('description', 'Ten days old')->count())->toBe(1);
});

test('a run date older than the automation log keeps is refused, so a pruned date never runs twice', function () {
    app(Settings::class)->set('platform.automation_log_retention_days', 120);

    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => now()->subDays(121)->toDateString()])->assertFailed();
    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => now()->subDays(120)->toDateString()])->assertSuccessful();
});
