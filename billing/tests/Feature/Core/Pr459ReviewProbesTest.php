<?php

use App\Models\User;
use App\Modules\Core\Install\FirstAdministrator;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

beforeEach(function () {
    Storage::fake('objects');
    Http::preventStrayRequests();
});

test('probe A: app:admin-password rotates the remember-me token of the old password', function () {
    app(ReferenceDataInstaller::class)->install();
    $admin = app(FirstAdministrator::class)->create('Owner', 'owner@example.test', 'Plum-Orbit-73-Lantern', temporary: false);
    $admin->forceFill(['remember_token' => 'stolen-remember-token'])->save();

    expect(Artisan::call('app:admin-password', ['email' => 'owner@example.test']))->toBe(0);

    // As a Fortify password reset does (CompletePasswordReset).
    expect($admin->refresh()->remember_token)->not->toBe('stolen-remember-token');
});

test('probe B: a 2FA challenge started before expiry is refused after it', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['password_expires_at' => now()->addMinute()])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->travel(10)->minutes();
    expect($user->refresh()->passwordExpired())->toBeTrue();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('probe C: an expired one-time password is refused when 2FA is enabled, before the challenge', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['password_expires_at' => now()->subMinute()])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(session('login.id'))->toBeNull();
});

test('probe D: renamed full-admin orphan with the admin attached becomes custom when the installer already added a keyed one', function () {
    app(ReferenceDataInstaller::class)->install();
    $orphan = Role::query()->forceCreate(['name' => 'Owner', 'permissions' => ['*'], 'is_system' => true]);
    $owner = User::factory()->create(['is_staff' => true, 'is_active' => true, 'role_id' => $orphan->id]);
    $orphan2 = Role::query()->forceCreate(['name' => 'Accounts', 'permissions' => array_reverse(SystemRoles::permissions('billing')), 'is_system' => true]);
    Role::query()->where('key', 'billing')->delete();

    (require base_path('app/Modules/Core/Database/Migrations/2026_10_24_000110_key_orphaned_system_roles.php'))->up();

    expect($orphan->refresh()->is_system)->toBeFalse()
        ->and($orphan->key)->toBeNull()
        ->and(app(FirstAdministrator::class)->isFullAdministrator($owner->refresh()))->toBeTrue()
        ->and($orphan2->refresh()->key)->toBe('billing');
});

test('probe E: rerun of the role migration is a no-op', function () {
    app(ReferenceDataInstaller::class)->install();
    Role::query()->forceCreate(['name' => 'X', 'permissions' => ['support.*'], 'is_system' => true]);
    $m = require base_path('app/Modules/Core/Database/Migrations/2026_10_24_000110_key_orphaned_system_roles.php');
    $m->up();
    $before = DB::table('roles')->orderBy('id')->get()->toArray();
    $m->up();
    expect(DB::table('roles')->orderBy('id')->get()->toArray())->toEqual($before);
});
