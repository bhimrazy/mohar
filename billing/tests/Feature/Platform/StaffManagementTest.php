<?php

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => confirmPassword());

test('staff list requires core.staff.view', function () {
    actingAsStaff(['core.clients.view']);
    $this->get(route('admin.platform.staff.index'))->assertForbidden();

    $viewer = actingAsStaff(['core.staff.view']);
    $this->get(route('admin.platform.staff.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/platform/staff/index')
            ->where('staff.data', fn ($staff) => collect($staff)->pluck('email')->contains($viewer->email))
            ->where('can.manage', false));
});

test('an admin invites staff by email and the invitee sets a password', function () {
    Notification::fake();
    actingAsStaff();
    $role = Role::factory()->withPermissions(['support.*'])->create();

    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'New.Agent@Example.com', 'name' => 'New Agent', 'role_id' => $role->id])
        ->assertRedirect(route('admin.platform.staff.index'));

    $invitation = StaffInvitation::query()->sole();
    expect($invitation->email)->toBe('new.agent@example.com')
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    $token = null;
    Notification::assertSentOnDemand(StaffInvitationNotification::class, function (StaffInvitationNotification $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    auth()->logout();
    $this->get(route('platform.invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation')->where('state', 'valid')->where('email', 'new.agent@example.com'));

    $this->post(route('platform.invitations.accept', $token), ['name' => 'New Agent', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42'])
        ->assertRedirect(route('admin.dashboard'));

    $user = User::query()->where('email', 'new.agent@example.com')->sole();
    expect($user)->is_staff->toBeTrue()->role_id->toBe($role->id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('an invitation older than 7 days cannot be accepted but can be resent', function () {
    Notification::fake();
    $admin = actingAsStaff();
    $invitation = StaffInvitation::factory()->withToken('old-token')->create(['created_at' => now()->subDays(8), 'expires_at' => now()->subDay(), 'invited_by' => $admin->id]);

    auth()->logout();
    $this->get(route('platform.invitations.show', 'old-token'))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    $this->post(route('platform.invitations.accept', 'old-token'), ['name' => 'Late', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42'])
        ->assertSessionHasErrors('token');
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();

    $this->actingAs($admin);
    $this->post(route('admin.platform.invitations.resend', $invitation))->assertRedirect();

    expect($invitation->fresh()->isExpired())->toBeFalse();
    Notification::assertSentOnDemandTimes(StaffInvitationNotification::class, 1);
});

test('used or unknown invitation tokens are rejected', function () {
    StaffInvitation::factory()->withToken('used-token')->create(['accepted_at' => now()]);

    $this->get(route('platform.invitations.show', 'used-token'))->assertInertia(fn (Assert $page) => $page->where('state', 'used'));
    $this->get(route('platform.invitations.show', 'nope'))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    $this->post(route('platform.invitations.accept', 'nope'), ['name' => 'X', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42'])->assertNotFound();
});

test('staff created with a password must change it before using the admin area', function () {
    actingAsStaff();
    $role = Role::factory()->withPermissions(['core.clients.view'])->create();

    $this->post(route('admin.platform.staff.store'), ['mode' => 'password', 'name' => 'Pat', 'email' => 'pat@example.com', 'role_id' => $role->id, 'password' => 'short'])
        ->assertSessionHasErrors('password');
    $this->post(route('admin.platform.staff.store'), ['mode' => 'password', 'name' => 'Pat', 'email' => 'pat@example.com', 'role_id' => $role->id, 'password' => 'temporary-pass-1'])
        ->assertRedirect();

    $pat = User::query()->where('email', 'pat@example.com')->sole();
    expect($pat->must_change_password)->toBeTrue();

    $this->actingAs($pat)->get(route('admin.clients.index'))->assertRedirect(route('security.edit'));

    $pat->update(['password' => 'a-new-password-2']);
    expect($pat->fresh()->must_change_password)->toBeFalse();
    $this->actingAs($pat->fresh())->get(route('admin.clients.index'))->assertOk();
});

test('a core.* or core.staff.* wildcard does not grant sensitive staff management', function () {
    actingAsStaff(['core.*']);
    $this->get(route('admin.platform.staff.index'))->assertForbidden();

    actingAsStaff(['core.staff.*']);
    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'x@example.com', 'role_id' => Role::factory()->withPermissions(['support.*'])->create()->id])->assertForbidden();
});

test('staff cannot assign a role with permissions they do not hold', function () {
    actingAsStaff(['core.staff.view', 'core.staff.manage', 'support.*']);
    $fullAdmin = Role::factory()->withPermissions(['*'])->create();
    $billing = Role::factory()->withPermissions(['billing.*'])->create();
    $support = Role::factory()->withPermissions(['support.tickets.view'])->create();

    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'a@example.com', 'role_id' => $fullAdmin->id])->assertSessionHasErrors('role_id');
    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'b@example.com', 'role_id' => $billing->id])->assertSessionHasErrors('role_id');
    $this->post(route('admin.platform.staff.store'), ['mode' => 'invite', 'email' => 'c@example.com', 'role_id' => $support->id])->assertSessionHasNoErrors();

    $this->get(route('admin.platform.staff.create'))->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => collect($roles)->pluck('id')->contains($support->id)
        && ! collect($roles)->pluck('id')->contains($fullAdmin->id)
        && ! collect($roles)->pluck('id')->contains($billing->id)));
});

test('staff cannot edit a colleague whose role exceeds their own permissions', function () {
    actingAsStaff(['core.staff.view', 'core.staff.manage']);
    $admin = User::factory()->staff(['*'])->create();

    $this->put(route('admin.platform.staff.update', $admin), ['name' => 'Hijacked', 'email' => 'me@example.com', 'role_id' => $admin->role_id, 'is_active' => true])
        ->assertSessionHasErrors('role_id');

    expect($admin->fresh()->email)->not->toBe('me@example.com');
});

test('staff cannot deactivate themselves or change their own role', function () {
    $me = actingAsStaff();
    User::factory()->staff()->create();
    $other = Role::factory()->withPermissions(['*'])->create();

    $this->put(route('admin.platform.staff.update', $me), ['name' => $me->name, 'email' => $me->email, 'role_id' => $me->role_id, 'is_active' => false])
        ->assertSessionHasErrors('is_active');
    $this->put(route('admin.platform.staff.update', $me), ['name' => $me->name, 'email' => $me->email, 'role_id' => $other->id, 'is_active' => true])
        ->assertSessionHasErrors('role_id');

    expect($me->fresh())->is_active->toBeTrue()->role_id->toBe($me->role_id);
});

test('the only active full administrator cannot be disabled or demoted (422)', function () {
    $admin = actingAsStaff();
    $support = Role::factory()->withPermissions(['support.*'])->create();

    $this->putJson(route('admin.platform.staff.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role_id' => $admin->role_id, 'is_active' => false])
        ->assertUnprocessable();
    $this->putJson(route('admin.platform.staff.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role_id' => $support->id, 'is_active' => true])
        ->assertUnprocessable();

    $colleague = User::factory()->staff(['*'])->create();
    $this->putJson(route('admin.platform.staff.update', $colleague), ['name' => $colleague->name, 'email' => $colleague->email, 'role_id' => $support->id, 'is_active' => true])
        ->assertRedirect(route('admin.platform.staff.index'));

    expect($admin->fresh())->is_active->toBeTrue()
        ->and($colleague->fresh()->role_id)->toBe($support->id);
});

test('a disabled staff member is signed out with 401 on the next request', function () {
    actingAsStaff();
    User::factory()->staff()->create();
    $agent = User::factory()->staff(['core.clients.view'])->create();

    $this->put(route('admin.platform.staff.update', $agent), ['name' => $agent->name, 'email' => $agent->email, 'role_id' => $agent->role_id, 'is_active' => false])
        ->assertRedirect(route('admin.platform.staff.index'));

    $this->actingAs($agent->fresh())->get(route('admin.clients.index'))->assertUnauthorized();
    $this->assertGuest();
    $this->assertDatabaseHas('activity_log', ['event' => 'staff.disabled', 'subject_id' => $agent->id]);
});

test('staff with 2FA required are sent to enrolment before any admin route', function () {
    app(Settings::class)->set('security.staff_2fa_required', true);
    $staff = actingAsStaff();

    $this->get(route('admin.dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('admin.clients.index'))->assertRedirect(route('security.edit'));

    $staff->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['code'])), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($staff->fresh())->get(route('admin.dashboard'))->assertOk();
});

test('staff 2FA can be reset by a manager but not for oneself', function () {
    $me = actingAsStaff();
    $agent = User::factory()->staff(['support.*'])->withTwoFactor()->create();

    $this->post(route('admin.platform.staff.reset-two-factor', $me))->assertSessionHasErrors('two_factor');
    $this->post(route('admin.platform.staff.reset-two-factor', $agent))->assertSessionHasNoErrors();

    expect($agent->fresh()->two_factor_confirmed_at)->toBeNull();
    $this->assertDatabaseHas('activity_log', ['event' => 'staff.two_factor_reset', 'subject_id' => $agent->id]);
});

test('signing in records last login time and ip', function () {
    $staff = User::factory()->staff()->create();

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'], ['REMOTE_ADDR' => '203.0.113.9']);

    expect($staff->fresh())->last_login_ip->toBe('203.0.113.9')->last_login_at->not->toBeNull();
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.login', 'user_id' => $staff->id]);
});
