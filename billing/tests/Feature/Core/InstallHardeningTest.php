<?php

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Models\User;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Console\Commands\InstallCommand;
use App\Modules\Core\Install\FirstAdministrator;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Role;
use App\Support\Install\ReferenceDataRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Install follow-ups (#60, #216): one install at a time, a plain message when two collide anyway, and a one-time
 * administrator password that stops working after FirstAdministrator::ONE_TIME_PASSWORD_HOURS.
 */
beforeEach(function () {
    Storage::fake('objects');
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function hardenedInstall(string $command = 'app:install', array $options = []): array
{
    $status = Artisan::call($command, ['--no-interaction' => true, ...$options]);

    return [$status, Artisan::output()];
}

/**
 * Install with a known generated password and return the administrator.
 */
function installWithOneTimePassword(string $password): User
{
    FirstAdministrator::generatePasswordsUsing(fn (): string => $password);

    try {
        [$status] = hardenedInstall(options: ['--admin-email' => 'owner@example.test']);
    } finally {
        FirstAdministrator::generatePasswordsUsing(null);
    }

    expect($status)->toBe(0);

    return User::query()->where('email', 'owner@example.test')->sole();
}

test('an install refuses to start while another holds the install lock, and writes nothing', function () {
    $other = Cache::lock(InstallCommand::LOCK, 60);
    expect($other->get())->toBeTrue();

    try {
        [$status, $output] = hardenedInstall(options: ['--admin-email' => 'owner@example.test']);
    } finally {
        $other->release();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Another app:install is running')
        ->and(User::query()->count())->toBe(0)
        ->and(TaxRule::query()->count())->toBe(0);

    [$status] = hardenedInstall(options: ['--admin-email' => 'owner@example.test']);

    expect($status)->toBe(0)
        ->and(TaxRule::query()->count())->toBe(1)
        ->and(Cache::lock(InstallCommand::LOCK, 1)->get())->toBeTrue();
});

test('a unique-key clash with an install the lock could not see ends with a plain message, not a stack trace', function () {
    app(ReferenceDataRegistry::class)->register('zz_race', fn (): InstallsReferenceData => new class implements InstallsReferenceData
    {
        public function key(): string
        {
            return 'zz_race';
        }

        public function label(): string
        {
            return 'Race';
        }

        public function install(): ReferenceDataResult
        {
            throw new UniqueConstraintViolationException('pgsql', 'insert into "roles"', [], new PDOException('duplicate key value violates unique constraint "roles_key_unique"'));
        }
    }, order: 999);

    [$status, $output] = hardenedInstall(options: ['--admin-email' => 'owner@example.test']);

    expect($status)->toBe(1)
        ->and($output)->toContain('Another install wrote the same rows at the same time')
        ->and(User::query()->count())->toBe(0);
});

test('a generated one-time password signs in for 24 hours, then is refused with how to get a new one', function () {
    $password = 'Ab1!one-time-'.Str::random(8);
    $admin = installWithOneTimePassword($password);

    expect($admin->password_expires_at?->toIso8601String())->toBe(now()->addHours(FirstAdministrator::ONE_TIME_PASSWORD_HOURS)->toIso8601String());

    $this->travel(FirstAdministrator::ONE_TIME_PASSWORD_HOURS)->hours();
    $this->travel(-1)->seconds();
    $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => $password]);
    $this->assertAuthenticatedAs($admin);
    auth()->logout();

    $this->travel(2)->seconds();
    $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => $password])
        ->assertSessionHasErrors(['email' => __('This one-time password has expired. Use "Forgot password", or ask the operator to run php artisan app:admin-password on the server.')]);
    $this->assertGuest();
});

test('choosing a new password ends the expiry, and a password chosen at the prompt never expires', function () {
    $admin = installWithOneTimePassword('Ab1!one-time-'.Str::random(8));

    $admin->forceFill(['password' => 'Plum-Orbit-73-Lantern'])->save();
    expect($admin->refresh()->password_expires_at)->toBeNull()
        ->and($admin->must_change_password)->toBeFalse();

    $this->travel(3)->days();
    $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => 'Plum-Orbit-73-Lantern']);
    $this->assertAuthenticatedAs($admin);

    $chosen = app(FirstAdministrator::class)->create('Second', 'second@example.test', 'Plum-Orbit-73-Lantern', temporary: false, additional: true);
    expect($chosen->password_expires_at)->toBeNull();
});

test('app:admin-password gives a full administrator a new one-time password and refuses anyone else', function () {
    $old = 'Ab1!one-time-'.Str::random(8);
    $admin = installWithOneTimePassword($old);
    $this->travel(2)->days();
    $staff = User::factory()->staff(['core.clients.view'])->create(['email' => 'support@example.test']);

    [$status, $output] = hardenedInstall('app:admin-password', ['email' => 'support@example.test']);
    expect($status)->toBe(1)->and($output)->toContain('not an active full administrator');

    [$status, $output] = hardenedInstall('app:admin-password', ['email' => 'nobody@example.test']);
    expect($status)->toBe(1)->and($output)->toContain('No account uses nobody@example.test');

    $new = 'Cd2?new-one-'.Str::random(8);
    FirstAdministrator::generatePasswordsUsing(fn (): string => $new);

    try {
        [$status, $output] = hardenedInstall('app:admin-password', ['email' => 'Owner@Example.test']);
    } finally {
        FirstAdministrator::generatePasswordsUsing(null);
    }

    $admin->refresh();
    expect($status)->toBe(0)
        ->and($output)->toContain("\n  {$new}\n")
        ->and(Hash::check($new, $admin->password))->toBeTrue()
        ->and($admin->must_change_password)->toBeTrue()
        ->and($admin->password_expires_at?->toIso8601String())->toBe(now()->addHours(FirstAdministrator::ONE_TIME_PASSWORD_HOURS)->toIso8601String())
        ->and($staff->refresh()->password_expires_at)->toBeNull();

    $log = ActivityLog::query()->where('event', 'staff.password_reissued')->sole();
    expect($log->subject_id)->toBe($admin->id)
        ->and(json_encode($log->properties))->not->toContain($new);

    $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => $new]);
    $this->assertAuthenticatedAs($admin);
});

test('a system role left without a key is adopted when its permissions match a missing one, otherwise made a custom role', function () {
    app(ReferenceDataInstaller::class)->install();
    // A Billing role renamed before the roles.key backfill (#60): the install then created a keyed "Billing" beside it.
    Role::query()->where('key', 'billing')->delete();
    $renamed = Role::query()->forceCreate(['name' => 'Accounts', 'permissions' => SystemRoles::permissions('billing'), 'is_system' => true]);
    $edited = Role::query()->forceCreate(['name' => 'Old helpdesk', 'permissions' => ['support.*'], 'is_system' => true]);
    $member = User::factory()->create(['is_staff' => true, 'role_id' => $edited->id]);

    (require base_path('app/Modules/Core/Database/Migrations/2026_10_24_000110_key_orphaned_system_roles.php'))->up();

    expect($renamed->refresh()->only(['key', 'name', 'is_system']))->toBe(['key' => 'billing', 'name' => 'Accounts', 'is_system' => true])
        ->and($edited->refresh()->only(['key', 'is_system', 'permissions']))->toBe(['key' => null, 'is_system' => false, 'permissions' => ['support.*']])
        ->and($member->refresh()->role_id)->toBe($edited->id);

    $results = app(ReferenceDataInstaller::class)->install();
    expect($results['core_roles']['result'])->toEqual(new ReferenceDataResult(unchanged: 4))
        ->and(Role::query()->whereNull('key')->where('is_system', true)->count())->toBe(0);
});
