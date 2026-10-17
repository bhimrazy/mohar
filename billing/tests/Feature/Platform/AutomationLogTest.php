<?php

use App\Events\Core\AutomationRunCompleted;
use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
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

test('run now is queued and does not force re-runs unless asked', function () {
    Queue::fake();
    actingAsStaff(['core.automation.*']);

    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping'])->assertRedirect();

    Queue::assertPushed(RunAutomationJob::class, fn (RunAutomationJob $job) => $job->task === 'test.housekeeping' && $job->force === false);
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
        ->where('todos', fn ($todos) => collect($todos)->pluck('key')->contains('platform.automation.stale'))
        ->where('automation.stale', true));

    AutomationRun::query()->create(['run_date' => today(), 'task' => 'test.housekeeping', 'status' => 'failed', 'summary' => 'boom']);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('todos', fn ($todos) => ! collect($todos)->pluck('key')->contains('platform.automation.stale')
            && collect($todos)->firstWhere('key', 'platform.automation.failed')['count'] === 1)
        ->where('automation.failed_today', 1));
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
