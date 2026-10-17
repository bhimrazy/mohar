<?php

use App\Modules\Core\Models\AutomationRun;
use App\Support\Automation\Automation;
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
