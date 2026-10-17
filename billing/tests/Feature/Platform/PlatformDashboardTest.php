<?php

use App\Models\User;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Http\Middleware\EnforceStaffSecurity;
use App\Modules\Platform\Models\StaffInvitation;
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

test('the automation health and staff sign-in widgets list the latest state', function () {
    $agent = User::factory()->staff(['support.*'])->create(['name' => 'Sam Support']);
    $agent->forceFill(['last_login_at' => now()->subHour()])->save();
    AutomationRun::query()->create(['run_date' => today()->subDay(), 'task' => 'platform.prune_invitations', 'status' => 'failed']);
    AutomationRun::query()->create(['run_date' => today(), 'task' => 'platform.prune_invitations', 'status' => 'success']);
    actingAsStaff(['core.automation.view', 'core.staff.view']);

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('widgets', function ($widgets) {
            $widgets = collect($widgets)->keyBy('key');
            $automation = collect($widgets['platform.automation']['items'])->keyBy('title');

            return $widgets['platform.automation']['type'] === 'list'
                && $automation['platform.prune_invitations']['status'] === 'success'
                && $automation['platform.prune_activity_log']['status'] === 'pending'
                && $widgets['platform.staff.logins']['items'][0]['title'] === 'Sam Support';
        }));
});
