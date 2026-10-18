<?php

use App\Models\User;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Http\Middleware\EnforceStaffSecurity;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, array<string, mixed>>
 */
function dashboardTodos(): array
{
    return collect(test()->get(route('admin.dashboard'))->viewData('page')['props']['todos'])->keyBy('key')->all();
}

test('staff without 2FA are a to-do only while 2FA is required', function () {
    actingAsStaff(['core.staff.view']); // the acting staff member has no 2FA either
    User::factory()->staff(['support.*'])->withTwoFactor()->create();

    expect(dashboardTodos())->not->toHaveKey('platform.staff.no_2fa');

    app(Settings::class)->set('security.staff_2fa_required', true);
    $this->withoutMiddleware(EnforceStaffSecurity::class);

    expect(dashboardTodos()['platform.staff.no_2fa'])->toMatchArray(['count' => 1, 'tone' => 'warning']);
});

test('invitations expiring within 48 hours are a to-do', function () {
    actingAsStaff(['core.staff.view']);
    StaffInvitation::factory()->create(['expires_at' => now()->addHours(20)]);
    StaffInvitation::factory()->create(['expires_at' => now()->addDays(5)]);
    StaffInvitation::factory()->expired()->create();

    expect(dashboardTodos()['platform.invitations.expiring']['count'])->toBe(1);
});

test('to-dos are hidden without the matching permission', function () {
    actingAsStaff(['core.clients.view']);
    StaffInvitation::factory()->create(['expires_at' => now()->addHours(20)]);

    expect(dashboardTodos())->toBe([]);
});

test('the automation health check summarises each task\'s latest run, and sign-ins list the latest state', function () {
    $agent = User::factory()->staff(['support.*'])->create(['name' => 'Sam Support']);
    $agent->forceFill(['last_login_at' => now()->subHour()])->save();
    AutomationRun::query()->create(['run_date' => today()->subDay(), 'task' => 'platform.prune_invitations', 'status' => 'failed']);
    AutomationRun::query()->create(['run_date' => today(), 'task' => 'platform.prune_invitations', 'status' => 'success']);
    actingAsStaff(['core.automation.view', 'core.staff.view']);
    $tasks = count(app(Automation::class)->ordered());

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('widgets', function ($widgets) {
            $widgets = collect($widgets)->keyBy('key');

            return ! $widgets->has('platform.automation')
                && $widgets['platform.staff.logins']['items'][0]['title'] === 'Sam Support'
                && $widgets['platform.staff.logins']['group'] === 'activity';
        })
        ->loadDeferredProps(fn (Assert $page) => $page->where('health', function ($health) use ($tasks) {
            $automation = collect($health)->firstWhere('key', 'platform.automation');

            return $automation['detail'] === "{$tasks} tasks: 1 succeeded, ".($tasks - 1).' not run yet · last run '.now()->diffForHumans()
                && $automation['value'] === '1 / '.$tasks
                && $automation['tone'] === 'warning';
        })));
});

test('a failed task makes the automation health check fail, and the queue check reports failed jobs', function () {
    AutomationRun::query()->create(['run_date' => today(), 'task' => 'platform.prune_invitations', 'status' => 'failed']);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    $health = collect(app(Dashboard::class)->resolveHealth('admin', actingAsStaff(['core.automation.view'])))->keyBy('key');

    expect($health['platform.automation'])->toMatchArray(['value' => '1 failed', 'tone' => 'danger'])
        ->and($health['platform.queue'])->toMatchArray(['value' => '1 failed', 'tone' => 'danger'])
        ->and(collect(app(Dashboard::class)->resolveHealth('admin', actingAsStaff(['core.clients.view'])))->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'platform.')))->toBeEmpty();
});
