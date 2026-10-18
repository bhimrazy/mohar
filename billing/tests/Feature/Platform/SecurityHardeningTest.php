<?php

use App\Events\Core\ImpersonationEnded;
use App\Events\Core\SettingsUpdated;
use App\Events\Core\StaffInvited;
use App\Models\User;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ImpersonationSession;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('no module or area wildcard reaches a sensitive permission; "*" and explicit grants do', function () {
    $registry = app(PermissionRegistry::class);
    $sensitive = $registry->sensitive();
    expect($sensitive)->toContain('core.clients.impersonate', 'core.clients.merge', 'core.clients.close', 'core.staff.view', 'core.staff.manage', 'core.roles.manage', 'core.settings.security');

    $wildcards = collect($registry->all())->flatMap(fn (array $permissions) => array_keys($permissions))
        ->flatMap(function (string $key) {
            $parts = explode('.', $key);

            return [$parts[0].'.*', $parts[0].'.'.$parts[1].'.*'];
        })->unique()->values()->all();
    $wildcardRole = Role::factory()->withPermissions($wildcards)->make();

    foreach ($sensitive as $permission) {
        expect($wildcardRole->allows($permission))->toBeFalse("{$permission} must not be wildcard-granted")
            ->and(Role::factory()->withPermissions(['*'])->make()->allows($permission))->toBeTrue()
            ->and(Role::factory()->withPermissions([$permission])->make()->allows($permission))->toBeTrue();
    }

    expect($wildcardRole->allows('core.clients.view'))->toBeTrue();
});

test('seeding keeps system roles explicit and rewrites stale wildcard grants', function () {
    Role::query()->forceCreate(['key' => 'billing', 'name' => 'Billing', 'permissions' => ['core.clients.*', 'billing.*'], 'is_system' => true]);

    $this->seed(CoreSeeder::class);
    $this->seed(CoreSeeder::class);

    $billing = Role::query()->where('name', 'Billing')->sole();
    expect($billing->permissions)->not->toContain('core.clients.*')
        ->and($billing->allows('core.clients.update'))->toBeTrue()
        ->and($billing->allows('core.clients.impersonate'))->toBeFalse()
        ->and($billing->allows('core.clients.merge'))->toBeFalse();
});

test('the legacy core.clients.manage permission is migrated to explicit create/update', function () {
    $role = Role::query()->create(['name' => 'Legacy', 'permissions' => ['core.clients.view', 'core.clients.manage']]);

    (require base_path('app/Modules/Platform/Database/Migrations/2026_10_17_900200_replace_legacy_client_permissions.php'))->up();

    expect($role->fresh()->permissions)->toEqualCanonicalizing(['core.clients.view', 'core.clients.create', 'core.clients.update']);
});

test('general settings permission cannot change security settings', function () {
    actingAsStaff(['core.settings.view', 'core.settings.update']);
    confirmPassword();

    $this->put(route('admin.platform.settings.update', ['section' => 'security']), ['security' => ['staff_2fa_required' => false]])->assertForbidden();
    expect(app(Settings::class)->get('security.staff_2fa_required'))->toBeFalse(); // phpunit default, unchanged by the request
    $this->assertDatabaseMissing('settings', ['key' => 'security.staff_2fa_required']);
});

test('security settings need a recent password and notify every full administrator', function () {
    Notification::fake();
    Event::fake([SettingsUpdated::class]);
    $otherAdmin = User::factory()->staff(['*'])->create();
    actingAsStaff(['core.settings.view', 'core.settings.security']);
    $payload = ['security' => ['staff_2fa_required' => true, 'login_max_attempts' => 5, 'login_lockout_minutes' => 15, 'login_ip_allowlist' => ''], 'platform' => ['impersonation_minutes' => 15, 'invitation_expiry_days' => 3, 'activity_log_retention_days' => 365]];

    $this->get(route('admin.platform.settings.edit', ['section' => 'security']))->assertRedirect(route('password.confirm'));
    $this->put(route('admin.platform.settings.update', ['section' => 'security']), $payload)->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->get(route('admin.platform.settings.edit', ['section' => 'security']))->assertOk();
    $this->put(route('admin.platform.settings.update', ['section' => 'security']), $payload)->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('platform.impersonation_minutes'))->toBe(15);
    Notification::assertSentTo($otherAdmin, SecurityNotice::class);
    Event::assertDispatched(SettingsUpdated::class, fn (SettingsUpdated $event) => in_array('platform.impersonation_minutes', $event->keys, true));
});

test('the impersonation time limit setting is enforced', function () {
    Currency::factory()->create();
    app(Settings::class)->set('platform.impersonation_minutes', 5);
    $client = Client::factory()->withOwner()->create();
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    confirmPassword();

    $url = (string) $this->post(route('admin.platform.clients.impersonate', $client), [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    $this->get($url)->assertRedirect(route('client.dashboard'));
    $this->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page->has('impersonation'));

    $this->travel(6)->minutes();
    $this->get(route('client.dashboard'))->assertRedirect(route('admin.clients.show', $client));
    $this->assertAuthenticatedAs($staff);
});

test('the invitation validity setting sets the expiry', function () {
    Notification::fake();
    Event::fake([StaffInvited::class]);
    app(Settings::class)->set('platform.invitation_expiry_days', 2);
    actingAsStaff();
    confirmPassword();
    $role = Role::factory()->withPermissions(['support.*'])->create();

    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'agent@example.com', 'role_id' => $role->id])->assertRedirect();

    $invitation = StaffInvitation::query()->sole();
    expect($invitation->expires_at->isSameDay(now()->addDays(2)))->toBeTrue();
    Event::assertDispatched(StaffInvited::class, fn (StaffInvited $event) => $event->invitationId === $invitation->id);
});

test('the automation page shows the configured run time', function () {
    app(Settings::class)->set('core.automation_time', '04:45');
    actingAsStaff(['core.automation.view']);

    $this->get(route('admin.platform.automation.index'))->assertInertia(fn (Assert $page) => $page->where('scheduledAt', '04:45'));
});

test('changing a colleague\'s email needs a recent password, notifies the old address and requires re-verification', function () {
    Notification::fake();
    actingAsStaff();
    $agent = User::factory()->staff(['support.*'])->create(['email' => 'old@example.com']);
    $payload = ['name' => $agent->name, 'email' => 'new@example.com', 'role_id' => $agent->role_id, 'is_active' => true];

    $this->put(route('admin.platform.staff.update', $agent), $payload)->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->put(route('admin.platform.staff.update', $agent), $payload)->assertRedirect();

    expect($agent->fresh())->email->toBe('new@example.com')->email_verified_at->toBeNull();
    Notification::assertSentOnDemand(SecurityNotice::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['old@example.com', 'new@example.com']);
});

test('a staff 2FA reset emails the affected staff member', function () {
    Notification::fake();
    actingAsStaff();
    confirmPassword();
    $agent = User::factory()->staff(['support.*'])->withTwoFactor()->create();

    $this->post(route('admin.platform.staff.reset-two-factor', $agent))->assertRedirect();

    Notification::assertSentOnDemand(SecurityNotice::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === [$agent->email]);
});

test('after an admin email change, a 2FA reset also notifies the previous address', function () {
    Notification::fake();
    actingAsStaff();
    confirmPassword();
    $agent = User::factory()->staff(['support.*'])->withTwoFactor()->create(['email' => 'real.owner@example.com']);

    $this->put(route('admin.platform.staff.update', $agent), ['name' => $agent->name, 'email' => 'attacker@evil.test', 'role_id' => $agent->role_id, 'is_active' => true])->assertRedirect();
    $this->post(route('admin.platform.staff.reset-two-factor', $agent))->assertRedirect();

    Notification::assertSentOnDemand(SecurityNotice::class, fn (SecurityNotice $notification, $channels, $notifiable) => $notification->subject === 'Your two-factor authentication was reset'
        && in_array('real.owner@example.com', (array) $notifiable->routes['mail'], true)
        && in_array('attacker@evil.test', (array) $notifiable->routes['mail'], true));
});

test('activity-log retention is a security setting', function () {
    actingAsStaff(['core.settings.view', 'core.settings.update']);
    confirmPassword();

    $this->put(route('admin.platform.settings.update', ['section' => 'security']), ['platform' => ['activity_log_retention_days' => 30]])->assertForbidden();
    $this->assertDatabaseMissing('settings', ['key' => 'platform.activity_log_retention_days']);
});

test('five failed sign-ins lock the email and IP for the lockout period, even with the right password', function () {
    $user = User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'victim@example.com', 'password' => 'wrong-'.$attempt]);
    }

    $this->travel(2)->minutes(); // past Fortify's per-minute limiter
    $this->post(route('login.store'), ['email' => 'victim@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(14)->minutes();
    $this->post(route('login.store'), ['email' => 'victim@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('allow-listed IPs are exempt from the lockout', function () {
    app(Settings::class)->set('security.login_ip_allowlist', "10.0.0.0/8\n192.0.2.1");
    User::factory()->create(['email' => 'office@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'office@example.com', 'password' => 'wrong'], ['REMOTE_ADDR' => '10.1.2.3']);
    }

    $this->travel(2)->minutes();
    $this->post(route('login.store'), ['email' => 'office@example.com', 'password' => 'password'], ['REMOTE_ADDR' => '10.1.2.3']);
    $this->assertAuthenticated();
});

test('a failed sign-in with a huge email is logged safely', function () {
    $email = str_repeat('a', 400)."\n\t@example.com";

    $this->post(route('login.store'), ['email' => $email, 'password' => 'x'])->assertSessionHasErrors();

    $entry = ActivityLog::query()->where('event', 'auth.login_failed')->sole();
    expect($entry->description)->toBe('Failed sign-in attempt')
        ->and(mb_strlen($entry->properties['email']))->toBeLessThanOrEqual(1000)
        ->and($entry->properties['email'])->not->toContain("\n");
});

test('activity log entries are append-only', function () {
    $entry = ActivityLog::query()->create(['description' => 'Original']);

    expect(fn () => $entry->update(['description' => 'Tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and($entry->fresh()->description)->toBe('Original');
});

test('signing out while impersonating ends the impersonation, not the customer\'s sessions', function () {
    Event::fake([ImpersonationEnded::class]);
    Currency::factory()->create();
    $client = Client::factory()->withOwner()->create();
    $owner = $client->users()->sole();
    $rememberToken = $owner->remember_token;
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    confirmPassword();

    $url = (string) $this->post(route('admin.platform.clients.impersonate', $client), [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    $this->get($url);

    $this->post(route('logout'))->assertRedirect(route('admin.clients.show', $client));

    $this->assertAuthenticatedAs($staff);
    expect(ImpersonationSession::query()->sole()->end_reason)->toBe('logout')
        ->and($owner->fresh()->remember_token)->toBe($rememberToken);
    $this->assertDatabaseHas('activity_log', ['event' => 'impersonation.ended', 'client_id' => $client->id]);
    Event::assertDispatched(ImpersonationEnded::class, fn (ImpersonationEnded $event) => $event->reason === 'logout');
});

test('staff signed in as a client cannot change the account email', function () {
    Currency::factory()->create();
    $client = Client::factory()->withOwner()->create(['email' => 'billing@client.test']);
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    confirmPassword();
    $url = (string) $this->post(route('admin.platform.clients.impersonate', $client), [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    $this->get($url);
    $details = ['first_name' => 'A', 'last_name' => 'B', 'address_1' => '1 Main St', 'city' => 'Town', 'postcode' => '1000', 'country' => 'US'];

    $this->put(route('client.account.update'), [...$details, 'email' => 'attacker@example.com'])->assertSessionHasErrors('email');
    $this->put(route('client.account.update'), [...$details, 'email' => 'billing@client.test'])->assertSessionHasNoErrors();

    expect($client->fresh()->email)->toBe('billing@client.test');
});

test('an array email on sign-in is a validation error, not a server error', function () {
    $this->post(route('login.store'), ['email' => ['x@example.com'], 'password' => 'secret'])->assertSessionHasErrors('email');
    $this->postJson(route('login.store'), ['email' => ['x@example.com'], 'password' => 'secret'])->assertUnprocessable();
});
