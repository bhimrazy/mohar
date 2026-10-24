<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\FindOrphanFiles;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Models\KycOrphanFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Re-review probes of #460, adopted. The breaker probes now assert the CTO's design: the daily check never deletes an
 * identity file, whatever a run finds, so a database that does not own the bucket only lists files for staff.
 */

function rr460File(string $path, int $hours): string
{
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put($path, 'x');
    touch($disk->path($path), now()->subHours($hours)->getTimestamp());

    return $path;
}

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    Cache::forget(FindOrphanFiles::CURSOR);
});

test('a bucket shared with a database copy that lacks the newest documents loses no file over many runs', function () {
    $old = Client::factory()->create();
    foreach (range(1, 50) as $i) {
        KycDocument::factory()->create(['client_id' => $old->id, 'front_path' => rr460File(sprintf('kyc/%d/old%02d', $old->id, $i), 500), 'back_path' => null]);
    }
    foreach (range(1, 5) as $day) {
        $owner = Client::factory()->create();
        foreach (range(1, 10) as $i) {
            rr460File(sprintf('kyc/%d/front%02d', $owner->id, $i), 30);
            rr460File(sprintf('kyc/%d/back%02d', $owner->id, $i), 30);
        }
        app(FindOrphanFiles::class)->handle();
    }

    expect(Storage::disk(KycDocument::DISK)->allFiles('kyc'))->toHaveCount(150)
        ->and(KycOrphanFile::query()->count())->toBe(100);
});

test('foreign files spread thinly across scan windows are listed, never deleted', function () {
    config(['kyc.orphan_scan_limit' => 100]);
    $client = Client::factory()->create();
    foreach (range(1, 400) as $i) {
        $path = rr460File(sprintf('kyc/%d/f%03d', $client->id, $i), 30);
        if ($i % 10 !== 0) {
            KycDocument::factory()->create(['client_id' => $client->id, 'front_path' => $path, 'back_path' => null]);
        }
    }
    foreach (range(1, 5) as $run) {
        app(FindOrphanFiles::class)->handle();
    }

    expect(Storage::disk(KycDocument::DISK)->allFiles('kyc'))->toHaveCount(400)
        ->and(KycOrphanFile::query()->count())->toBe(40);
});

test('automation log prune boundary: rows on the oldest runnable date stay, the day before goes', function () {
    app(Settings::class)->set('platform.automation_log_retention_days', 120);
    $today = app(CompanyClock::class)->calendarDate();
    foreach ([121, 120, 1] as $days) {
        DB::table('automation_runs')->insert(['run_date' => $today->addDays(-$days)->toString(), 'task' => 'probe.x', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->artisan('automation:run', ['--only' => 'platform.prune_automation_log'])->assertSuccessful();

    expect(DB::table('automation_runs')->where('task', 'probe.x')->orderBy('run_date')->pluck('run_date')->map(fn ($d) => substr((string) $d, 0, 10))->all())
        ->toBe([$today->addDays(-120)->toString(), $today->addDays(-1)->toString()]);
});

test('a future --date prunes the automation log no further than today does', function () {
    app(Settings::class)->set('platform.automation_log_retention_days', 120);
    $today = app(CompanyClock::class)->calendarDate();
    foreach ([100, 1] as $days) {
        DB::table('automation_runs')->insert(['run_date' => $today->addDays(-$days)->toString(), 'task' => 'probe.y', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->artisan('automation:run', ['--only' => 'platform.prune_automation_log', '--date' => $today->addDays(400)->toString()])->assertSuccessful();

    expect(DB::table('automation_runs')->where('task', 'probe.y')->count())->toBe(2);
});

test('raising the automation log retention after a prune does not reopen the pruned dates', function () {
    app(Settings::class)->set('platform.automation_log_retention_days', 90);
    $today = app(CompanyClock::class)->calendarDate();
    $date = $today->addDays(-100)->toString();
    DB::table('automation_runs')->insert(['run_date' => $date, 'task' => 'billing.gateway_log_prune', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('automation_runs')->insert(['run_date' => $today->addDays(-1)->toString(), 'task' => 'billing.gateway_log_prune', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_automation_log'])->assertSuccessful();
    expect(DB::table('automation_runs')->where('task', 'billing.gateway_log_prune')->whereDate('run_date', $date)->exists())->toBeFalse();

    app(Settings::class)->set('platform.automation_log_retention_days', 365);
    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => $date])->assertFailed();
    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => $today->addDays(-91)->toString()])->assertFailed();
    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => $today->addDays(-90)->toString()])->assertSuccessful();

    expect(DB::table('automation_runs')->where('task', 'billing.gateway_log_prune')->whereDate('run_date', $date)->exists())->toBeFalse();
});

test('the billing section no longer saves the gateway log retention', function () {
    actingAsStaff(['billing.settings.manage']);
    confirmPassword();
    $before = app(Settings::class)->get('billing.gateways.log_retention_days');

    $this->put(route('admin.platform.settings.update', 'billing'), ['billing' => ['gateways' => ['log_retention_days' => 90]]]);

    expect(app(Settings::class)->get('billing.gateways.log_retention_days'))->toBe($before);
});
