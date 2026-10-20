<?php

use App\Events\Core\AutomationRunCompleted;
use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
use App\Support\Queues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->calls = 0;
    app(Automation::class)->task('test.housekeeping', Automation::HOUSEKEEPING, function () {
        $this->calls++;

        return 'cleaned 2';
    });
});

test('the automation log requires core.automation.view', function () {
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.platform.automation.index'))->assertForbidden();
});

test('the automation log lists runs and registered tasks', function () {
    AutomationRun::query()->create(['run_date' => '2026-10-16', 'task' => 'test.housekeeping', 'status' => 'failed', 'summary' => 'boom', 'duration_ms' => 12]);
    actingAsStaff(['core.automation.view']);

    $this->get(route('admin.platform.automation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/platform/automation/index')
            ->where('runs.data.0.task', 'test.housekeeping')
            ->where('runs.data.0.status', 'failed')
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('key', 'test.housekeeping')['last_status'] === 'failed')
            ->where('can.run', false));

    $this->get(route('admin.platform.automation.index', ['status' => 'success']))->assertInertia(fn (Assert $page) => $page->has('runs.data', 0));
});

test('running automation now requires core.automation.run', function () {
    actingAsStaff(['core.automation.view']);

    $this->post(route('admin.platform.automation.run'))->assertForbidden();
    expect($this->calls)->toBe(0);
});

test('staff can run one task now and force a re-run', function () {
    actingAsStaff(['core.automation.*']);

    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping'])->assertRedirect(route('admin.platform.automation.index'));
    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping'])->assertRedirect();
    expect($this->calls)->toBe(1);

    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping', 'force' => true]);
    expect($this->calls)->toBe(2)
        ->and(AutomationRun::query()->where('task', 'test.housekeeping')->value('summary'))->toBe('cleaned 2');
    $this->assertDatabaseHas('activity_log', ['event' => 'automation.run']);

    $this->post(route('admin.platform.automation.run'), ['task' => 'unknown.task'])->assertSessionHasErrors('task');
});

test('run now is queued on the automation connection, once, and does not force re-runs unless asked', function () {
    Queue::fake();
    actingAsStaff(['core.automation.*']);

    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    // While that run waits or runs, another request is not queued, and the page says so.
    $this->post(route('admin.platform.automation.run'), ['force' => true])
        ->assertRedirect(route('admin.platform.automation.index'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'An automation run is already queued or running. Results appear in the run log.']);

    Queue::assertCount(1);
    Queue::assertPushed(RunAutomationJob::class, fn (RunAutomationJob $job) => $job->task === 'test.housekeeping' && $job->force === false
        && $job->connection === Queues::AUTOMATION && $job->queue === Queues::AUTOMATION);
    expect(ActivityLog::query()->where('event', 'automation.run')->count())->toBe(1);
});

test('automation:run refuses to overlap a run holding the shared lock', function () {
    $lock = Cache::lock(RunAutomation::LOCK, 60);
    $lock->get();

    $this->artisan('automation:run')->assertFailed();
    expect($this->calls)->toBe(0);

    $lock->release();
    $this->artisan('automation:run')->assertSuccessful();
    expect($this->calls)->toBe(1);
});

test('a finished run dispatches AutomationRunCompleted', function () {
    Event::fake([AutomationRunCompleted::class]);

    $this->artisan('automation:run', ['--date' => '2026-10-10'])->assertSuccessful();

    Event::assertDispatched(AutomationRunCompleted::class, fn (AutomationRunCompleted $event) => $event->runDate === '2026-10-10' && ! $event->failed);
});

test('the dashboard flags automation that has not run for over 26 hours and failed tasks', function () {
    actingAsStaff(['core.automation.view']);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('todos', fn ($todos) => collect($todos)->pluck('key')->contains('platform.automation.stale')));

    AutomationRun::query()->create(['run_date' => today(), 'task' => 'test.housekeeping', 'status' => 'failed', 'summary' => 'boom']);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('todos', fn ($todos) => ! collect($todos)->pluck('key')->contains('platform.automation.stale')
            && collect($todos)->firstWhere('key', 'platform.automation.failed')['count'] === 1));
});

test('the scheduler heartbeat is recorded', function () {
    app(AutomationStatus::class)->beat();

    expect(app(AutomationStatus::class)->heartbeatAt()?->isToday())->toBeTrue();
});

test('housekeeping prunes old invitations and client-less activity entries only', function () {
    StaffInvitation::factory()->create(['expires_at' => now()->subDays(40)]);
    $recentExpired = StaffInvitation::factory()->create(['expires_at' => now()->subDays(3)]);
    StaffInvitation::factory()->create(['accepted_at' => now()->subDays(100)]);
    Currency::factory()->create();
    $client = Client::factory()->create();
    DB::table('activity_log')->insert([
        ['description' => 'Old system entry', 'client_id' => null, 'created_at' => now()->subDays(400)],
        ['description' => 'Old client entry', 'client_id' => $client->id, 'created_at' => now()->subDays(400)],
        ['description' => 'Recent entry', 'client_id' => null, 'created_at' => now()->subDays(10)],
    ]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_invitations'])->assertSuccessful();
    $this->artisan('automation:run', ['--only' => 'platform.prune_activity_log'])->assertSuccessful();

    expect(StaffInvitation::query()->pluck('id')->all())->toBe([$recentExpired->id])
        ->and(ActivityLog::query()->orderBy('id')->pluck('description')->all())->toBe(['Old client entry', 'Recent entry']);
});

test('the automation page summarises the pipeline: success rate, failures, runs per day and items processed', function () {
    $this->travelTo('2026-10-18 12:00:00');
    app(Automation::class)->task('test.renewals', Automation::RENEWALS, fn () => 'done');
    AutomationRun::query()->create(['run_date' => '2026-10-18', 'task' => 'test.renewals', 'status' => 'success', 'summary' => '12 invoices', 'duration_ms' => 400]);
    AutomationRun::query()->create(['run_date' => '2026-10-18', 'task' => 'test.housekeeping', 'status' => 'failed', 'summary' => 'boom', 'duration_ms' => 100]);
    AutomationRun::query()->create(['run_date' => '2026-10-17', 'task' => 'test.renewals', 'status' => 'success', 'summary' => '3', 'duration_ms' => 300]);
    AutomationRun::query()->create(['run_date' => '2026-10-17', 'task' => 'test.housekeeping', 'status' => 'success', 'summary' => 'cleaned 2', 'duration_ms' => 100]);
    app(AutomationStatus::class)->beat();
    actingAsStaff(['core.automation.view']);
    $tasks = count(app(Automation::class)->ordered());

    $this->get(route('admin.platform.automation.index'))->assertInertia(fn (Assert $page) => $page
        ->where('insights.hasRuns', true)
        ->where('insights.scheduler.state', 'ok')
        ->where('insights.successRate.value', 75)
        ->where('insights.successRate.total', 4)
        ->where('insights.failed24h', 1)
        ->where('insights.nextRunAt', fn (string $at) => str_starts_with($at, '2026-10-19T'.app(Settings::class)->get('core.automation_time', config('core.automation_time'))))
        ->has('insights.perDay', 30)
        ->where('insights.perDay.29', ['x' => '2026-10-18', 'success' => 1, 'failed' => 1, 'missing' => $tasks - 2])
        ->where('insights.perDay.0.missing', $tasks)
        ->where('insights.processedToday', [['stage' => Automation::RENEWALS, 'count' => 12, 'tasks' => 1]])
        ->where('insights.duration.averageMs', 450)
        ->where('insights.stageDurations', [['stage' => Automation::RENEWALS, 'averageMs' => 350], ['stage' => Automation::HOUSEKEEPING, 'averageMs' => 100]])
        ->where('insights.cronCommand', fn (string $command) => str_contains($command, 'php artisan schedule:run')));
});

test('the scheduler state follows the heartbeat, and a fresh install has no runs', function () {
    actingAsStaff(['core.automation.view']);
    $state = fn () => $this->get(route('admin.platform.automation.index'))->viewData('page')['props']['insights'];

    expect($state()['scheduler']['state'])->toBe('never')
        ->and($state()['hasRuns'])->toBeFalse();

    Cache::forever(AutomationStatus::HEARTBEAT_KEY, now()->subMinutes(20)->toIso8601String());
    expect($state()['scheduler']['state'])->toBe('late');

    Cache::forever(AutomationStatus::HEARTBEAT_KEY, now()->subHours(3)->toIso8601String());
    expect($state()['scheduler']['state'])->toBe('stopped');
});

test('before today\'s scheduled run, today does not count as missed', function () {
    app(Settings::class)->set('core.automation_time', '03:00');
    $this->travelTo('2026-10-18 01:00:00');
    actingAsStaff(['core.automation.view']);

    $perDay = $this->get(route('admin.platform.automation.index'))->viewData('page')['props']['insights']['perDay'];

    expect($perDay[29])->toMatchArray(['x' => '2026-10-18', 'missing' => 0])
        ->and($perDay[28]['missing'])->toBe(count(app(Automation::class)->ordered()));
});

test('tasks and runs are shown by name and description, with a readable fallback instead of a key', function () {
    AutomationRun::query()->create(['run_date' => '2026-10-16', 'task' => 'test.housekeeping', 'status' => 'success', 'summary' => '2']);
    AutomationRun::query()->create(['run_date' => '2026-10-15', 'task' => 'retired.old_task', 'status' => 'success']);
    actingAsStaff(['core.automation.view']);

    $page = $this->get(route('admin.platform.automation.index'))->viewData('page')['props'];
    $tasks = collect($page['tasks'])->keyBy('key');
    $runs = collect($page['runs']['data'])->keyBy('task');

    expect($tasks['billing.auto_capture'])->toMatchArray(['label' => 'Charge saved cards'])
        ->and($tasks['billing.auto_capture']['description'])->toStartWith('Charges saved payment methods')
        ->and($tasks['test.housekeeping']['label'])->toBe('Housekeeping (test)')
        ->and($runs['test.housekeeping']['task_label'])->toBe('Housekeeping (test)')
        ->and($runs['retired.old_task']['task_label'])->toBe('Old task (retired)')
        ->and($tasks->every(fn (array $task): bool => $task['label'] !== $task['key']))->toBeTrue();
});
