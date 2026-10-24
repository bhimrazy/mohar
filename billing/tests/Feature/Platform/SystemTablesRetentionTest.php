<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Support\CalendarDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => inCompanyTimezone('Asia/Kathmandu'));

function automationRunOn(string $date, string $task): void
{
    DB::table('automation_runs')->insert(['run_date' => $date, 'task' => $task, 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
}

test('the automation log keeps 365 days, and each task keeps its latest run however old', function () {
    automationRunOn('2025-10-23', 'probe.daily');
    automationRunOn('2025-10-24', 'probe.daily');
    automationRunOn('2026-10-23', 'probe.daily');
    // A task that last ran two years ago keeps that run: the automation page shows each task's last run.
    automationRunOn('2024-01-01', 'probe.retired');
    automationRunOn('2024-01-02', 'probe.retired');

    $this->artisan('automation:run', ['--only' => 'platform.prune_automation_log', '--date' => '2026-10-24'])->assertSuccessful();

    expect(DB::table('automation_runs')->where('task', 'like', 'probe.%')->orderBy('id')->pluck('run_date')->map(fn ($date) => substr((string) $date, 0, 10))->all())
        ->toBe(['2025-10-24', '2026-10-23', '2024-01-02']);
});

test('failed jobs and finished batches older than 30 days are deleted; unfinished batches are kept', function () {
    [$start] = app(CompanyClock::class)->dayBounds(CalendarDate::parse('2026-10-24')->addDays(-30));
    foreach (['old' => $start->subSecond(), 'kept' => $start] as $name => $at) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $name, 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at]);
    }
    $batch = fn (string $id, ?int $finished, ?int $cancelled = null) => DB::table('job_batches')->insert([
        'id' => $id, 'name' => $id, 'total_jobs' => 1, 'pending_jobs' => $finished === null ? 1 : 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]',
        'created_at' => $start->subDays(60)->getTimestamp(), 'finished_at' => $finished, 'cancelled_at' => $cancelled,
    ]);
    $batch('finished-old', $start->subSecond()->getTimestamp());
    $batch('finished-kept', $start->getTimestamp());
    $batch('cancelled-old', null, $start->subSecond()->getTimestamp());
    $batch('unfinished-old', null);

    $this->artisan('automation:run', ['--only' => 'platform.prune_queue_tables', '--date' => '2026-10-24'])->assertSuccessful();

    expect(DB::table('failed_jobs')->pluck('queue')->all())->toBe(['kept'])
        ->and(DB::table('job_batches')->orderBy('id')->pluck('id')->all())->toBe(['finished-kept', 'unfinished-old']);
});

test('stored retentions below the floors are read as the floors', function () {
    app(Settings::class)->set('platform.automation_log_retention_days', 1);
    app(Settings::class)->set('platform.failed_jobs_retention_days', 1);
    automationRunOn('2026-07-26', 'probe.daily'); // 90 days before the run date: kept
    automationRunOn('2026-10-23', 'probe.daily');
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'kept', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(3)]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_automation_log', '--date' => '2026-10-24'])->assertSuccessful();
    $this->artisan('automation:run', ['--only' => 'platform.prune_queue_tables', '--date' => '2026-10-24'])->assertSuccessful();

    expect(DB::table('automation_runs')->where('task', 'probe.daily')->count())->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

test('expired database cache entries and locks are deleted; live ones are kept', function () {
    $now = now()->getTimestamp();
    DB::table('cache')->insert([
        ['key' => 'probe-expired', 'value' => 'x', 'expiration' => $now - 1],
        ['key' => 'probe-live', 'value' => 'x', 'expiration' => $now + 60],
    ]);
    DB::table('cache_locks')->insert([
        ['key' => 'probe-lock-expired', 'owner' => 'a', 'expiration' => $now - 1],
        ['key' => 'probe-lock-held', 'owner' => 'b', 'expiration' => $now + 60],
    ]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_cache', '--date' => '2026-10-24'])->assertSuccessful();

    expect(DB::table('cache')->where('key', 'like', 'probe-%')->pluck('key')->all())->toBe(['probe-live'])
        ->and(DB::table('cache_locks')->where('key', 'like', 'probe-%')->pluck('key')->all())->toBe(['probe-lock-held']);
});

test('the retention settings are validated', function () {
    actingAsStaff(['core.settings.security']);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('admin.platform.settings.update', 'security'), ['platform.automation_log_retention_days' => 30, 'platform.failed_jobs_retention_days' => 3])
        ->assertSessionHasErrors(['platform.automation_log_retention_days', 'platform.failed_jobs_retention_days']);
});

test('the activity log page runs no count over the log', function () {
    foreach (range(1, 26) as $i) {
        ActivityLog::query()->create(['description' => "Entry {$i}"]);
    }
    actingAsStaff(['core.activity_log.view']);
    DB::enableQueryLog();

    $this->get(route('admin.platform.activity.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 25)
            ->missing('entries.total')
            ->where('entries.next_page_url', fn ($url) => is_string($url)));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains(strtolower($sql), 'count(') && str_contains($sql, 'activity_log')))->toBeEmpty();
});
