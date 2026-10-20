<?php

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Events\Core\RoleUpdated;
use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Install\AdministratorRefused;
use App\Modules\Core\Install\FirstAdministrator;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Support\Install\ReferenceDataRegistry;
use App\Support\Queues;
use App\Support\Security\PasswordPolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

const STRONG_PASSWORD = 'Plum-Orbit-73-Lantern';

beforeEach(function () {
    Storage::fake('objects');
    Http::preventStrayRequests();
});

/**
 * Tables that hold at least one row, sorted.
 *
 * @return list<string>
 */
function tablesWithRows(): array
{
    return collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table): bool => DB::table($table)->exists())
        ->sort()->values()->all();
}

/**
 * The reference rows an install owns: the three default currencies and every system role, without ids or timestamps.
 *
 * @return array{currencies: array<string, array<string, mixed>>, roles: array<string, array<string, mixed>>}
 */
function referenceSnapshot(): array
{
    return [
        'currencies' => Currency::query()->whereIn('code', ['USD', 'INR', 'NPR'])->orderBy('code')->get()
            ->mapWithKeys(fn (Currency $currency) => [$currency->code => $currency->only(['name', 'prefix', 'suffix', 'decimals', 'format', 'exchange_rate', 'price_rounding', 'price_buffer_percent', 'is_default'])])->all(),
        'roles' => Role::query()->where('is_system', true)->orderBy('key')->get()
            ->mapWithKeys(fn (Role $role) => [$role->key => $role->only(['name', 'description', 'permissions'])])->all(),
    ];
}

/**
 * Run the install without prompts and return its output.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runInstall(array $options = []): array
{
    $status = Artisan::call('app:install', ['--no-interaction' => true, ...$options]);

    return [$status, Artisan::output()];
}

test('an install on an empty database creates the reference data and one full administrator, and nothing else', function () {
    Event::fake([StaffCreated::class]);

    [$status, $output] = runInstall(['--admin-email' => 'Owner@Example.test', '--admin-name' => 'Olga Owner']);

    expect($status)->toBe(0);

    $admin = User::query()->sole();
    preg_match('/one-time password\..*\R+  (\S{24})\R/', $output, $match);

    expect($admin->only(['name', 'email', 'is_staff', 'is_active', 'must_change_password']))
        ->toBe(['name' => 'Olga Owner', 'email' => 'owner@example.test', 'is_staff' => true, 'is_active' => true, 'must_change_password' => true])
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->role?->key)->toBe('full_administrator')
        ->and($match)->toHaveCount(2)
        ->and(Hash::check($match[1], $admin->password))->toBeTrue();

    expect(Currency::query()->orderBy('code')->pluck('is_default', 'code')->all())->toBe(['INR' => false, 'NPR' => false, 'USD' => true])
        ->and(Role::query()->where('is_system', true)->orderBy('key')->pluck('name', 'key')->all())->toBe(['billing' => 'Billing', 'full_administrator' => 'Full Administrator', 'sales' => 'Sales', 'support' => 'Support'])
        ->and(Role::query()->count())->toBe(4)
        ->and(tablesWithRows())->toBe(['activity_log', 'currencies', 'email_template_translations', 'email_templates', 'migrations', 'roles', 'users']);

    $log = ActivityLog::query()->sole();
    expect($log->event)->toBe('staff.created')
        ->and($log->user_id)->toBeNull()
        ->and(json_encode($log->properties))->not->toContain($match[1]);

    Event::assertDispatched(StaffCreated::class, fn (StaffCreated $event) => $event->userId === $admin->id);
});

test('the next steps print the worker pools that install.md documents, the automation worker included', function () {
    [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    $guide = (string) file_get_contents(base_path('docs/devops/install.md'));

    expect($status)->toBe(0)
        ->and(Queues::workerCommands())->toContain('php artisan queue:work automation --queue=automation --tries=1 --timeout='.Queues::AUTOMATION_TIMEOUT_SECONDS);

    foreach (Queues::workerCommands() as $command) {
        expect($output)->toContain($command)
            ->and($guide)->toContain($command);
    }
});

test('a second run changes nothing and reports every row unchanged', function () {
    runInstall(['--admin-email' => 'owner@example.test']);
    $snapshot = fn (): array => [
        'currencies' => Currency::query()->orderBy('id')->get()->toArray(),
        'roles' => Role::query()->orderBy('id')->get()->toArray(),
        'users' => User::query()->orderBy('id')->get()->makeVisible('password')->toArray(),
        'activity' => ActivityLog::query()->count(),
    ];
    $before = $snapshot();
    $this->travel(5)->minutes();

    [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);

    expect($status)->toBe(0)
        ->and($output)->toMatch('/Currencies\s*\|\s*0\s*\|\s*0\s*\|\s*3/')
        ->and($output)->toMatch('/System roles\s*\|\s*0\s*\|\s*0\s*\|\s*4/')
        ->and($output)->toContain('owner@example.test is already a full administrator; nothing changed.')
        ->and($snapshot())->toBe($before);

    [$status, $output] = runInstall();

    expect($status)->toBe(0)
        ->and($output)->toContain('1 full administrator(s) already exist, so none was created.')
        ->and($snapshot())->toBe($before);
});

test('a second full administrator is created only when asked for', function () {
    runInstall(['--admin-email' => 'owner@example.test']);

    [$status, $output] = runInstall(['--admin-email' => 'second@example.test']);

    expect($status)->toBe(1)
        ->and($output)->toContain('A full administrator already exists, so none was created. Pass --additional-admin')
        ->and(User::query()->pluck('email')->all())->toBe(['owner@example.test']);

    [$status] = runInstall(['--admin-email' => 'second@example.test', '--additional-admin' => true]);

    expect($status)->toBe(0)
        ->and(User::query()->orderBy('email')->pluck('email')->all())->toBe(['owner@example.test', 'second@example.test']);
});

test('an existing account that is not a full administrator is never promoted', function () {
    $client = User::factory()->create(['email' => 'taken@example.test']);

    [$status, $output] = runInstall(['--admin-email' => 'taken@example.test']);

    expect($status)->toBe(1)
        ->and($output)->toContain('already belongs to an account that is not a full administrator')
        ->and($client->refresh()->is_staff)->toBeFalse()
        ->and($client->role_id)->toBeNull();
});

test('without prompts or an email the install stops after the reference data with instructions', function () {
    [$status, $output] = runInstall();

    expect($status)->toBe(1)
        ->and($output)->toContain('pass --admin-email=<address>')
        ->and(Currency::query()->count())->toBe(3)
        ->and(User::query()->count())->toBe(0);
});

test('the interactive install creates the administrator with the chosen password', function () {
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response("0000000000000000000000000000000000A:3\n")]);

    $this->artisan('app:install')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'Ada@Example.test')
        ->expectsQuestion('Password', STRONG_PASSWORD)
        ->expectsQuestion('Confirm password', STRONG_PASSWORD)
        ->expectsOutputToContain('Full administrator ada@example.test created.')
        ->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->email)->toBe('ada@example.test')
        ->and($admin->must_change_password)->toBeFalse()
        ->and(Hash::check(STRONG_PASSWORD, $admin->password))->toBeTrue();
});

test('the interactive install applies the production password rules in every environment', function (string $password, string $message) {
    expect(app()->isProduction())->toBeFalse();

    $this->artisan('app:install')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'ada@example.test')
        ->expectsQuestion('Password', $password)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['Ab1!short', 'at least 12 characters'],
    'no symbol' => ['PlumOrbit73Lantern', 'at least one symbol'],
    'no upper case' => ['plum-orbit-73-lantern', 'at least one uppercase and one lowercase letter'],
]);

test('the interactive install rejects a password found in a known breach', function () {
    $hash = strtoupper(sha1(STRONG_PASSWORD));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":42\n")]);

    $this->artisan('app:install')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'ada@example.test')
        ->expectsQuestion('Password', STRONG_PASSWORD)
        ->expectsOutputToContain('appeared in a data leak')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('the interactive install rejects a confirmation that does not match', function () {
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('')]);

    $this->artisan('app:install')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'ada@example.test')
        ->expectsQuestion('Password', STRONG_PASSWORD)
        ->expectsQuestion('Confirm password', STRONG_PASSWORD.'x')
        ->expectsOutputToContain('The passwords do not match.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('a missing APP_KEY stops the install before anything is written', function () {
    config(['app.key' => '']);

    [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);

    expect($status)->toBe(1)
        ->and($output)->toContain('APP_KEY is not set. Run `php artisan key:generate`')
        ->and($output)->toContain('Install stopped.')
        ->and(Currency::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

test('a pending migration stops the install and names it', function () {
    $directory = sys_get_temp_dir().'/install-pending-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/2099_01_01_000000_pending_probe.php', "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void {}\n};\n");
    app('migrator')->path($directory);

    try {
        [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        unlink($directory.'/2099_01_01_000000_pending_probe.php');
        rmdir($directory);
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('1 pending (first: 2099_01_01_000000_pending_probe). Run `php artisan migrate --force`.')
        ->and(Role::query()->count())->toBe(0);
});

test('an unreachable database stops the install with the connection error', function () {
    $default = config('database.default');
    config(['database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/install-probe.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
    config(['database.default' => 'unreachable']);

    try {
        [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        config(['database.default' => $default]);
        DB::purge('unreachable');
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Cannot connect:')
        ->and($output)->toContain('Check the DB_* settings in .env.');
});

test('in production db:seed installs the reference data and no demo data', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('Production: reference data installed, demo data skipped.')
            ->assertSuccessful();

        expect(fn () => $this->artisan('db:seed', ['--force' => true, '--class' => CoreSeeder::class])->run())->toThrow(RuntimeException::class, 'app:install');

        [$status] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($status)->toBe(0)
        ->and(User::query()->pluck('email')->all())->toBe(['owner@example.test'])
        ->and(tablesWithRows())->toBe(['activity_log', 'currencies', 'email_template_translations', 'email_templates', 'migrations', 'roles', 'users']);
});

test('the demo seed installs exactly the reference data an install creates, through the same registry', function () {
    app(ReferenceDataInstaller::class)->install();
    $installed = referenceSnapshot();
    Role::query()->delete();
    Currency::query()->delete();

    $probe = new class implements InstallsReferenceData
    {
        public int $runs = 0;

        public function key(): string
        {
            return 'test_probe';
        }

        public function label(): string
        {
            return 'Probe';
        }

        public function install(): ReferenceDataResult
        {
            $this->runs++;

            return new ReferenceDataResult(unchanged: 1);
        }
    };
    app(ReferenceDataRegistry::class)->register('test_probe', fn () => $probe);

    $this->seed(DatabaseSeeder::class);

    expect(referenceSnapshot())->toBe($installed)
        ->and($probe->runs)->toBe(1)
        ->and(User::query()->where('email', 'admin@example.com')->exists())->toBeTrue();
});

test('currencies are created on the first install only, so an existing list is left as staff made it', function () {
    Currency::factory()->euro()->create(['is_default' => true]);

    $results = app(ReferenceDataInstaller::class)->install();

    expect(Currency::query()->pluck('is_default', 'code')->all())->toBe(['EUR' => true])
        ->and($results['core_currencies']['result'])->toEqual(new ReferenceDataResult(unchanged: 1));
});

test('a currency staff deleted is not re-created by a later run, and an edited one keeps its rate', function () {
    app(ReferenceDataInstaller::class)->install();
    Currency::query()->where('code', 'INR')->delete();
    Currency::query()->where('code', 'NPR')->update(['exchange_rate' => 140.25]);

    app(ReferenceDataInstaller::class)->install();

    expect(Currency::query()->orderBy('code')->pluck('exchange_rate', 'code')->all())->toBe(['NPR' => '140.250000000000', 'USD' => '1.000000000000']);
});

test('a system role edited away from its definition gets its permissions back, with an audit entry and an event', function () {
    app(ReferenceDataInstaller::class)->install();
    $support = Role::query()->where('key', 'support')->sole();
    $support->forceFill(['permissions' => ['billing.refunds.manage', 'support.*']])->save();
    Event::fake([RoleUpdated::class]);

    $results = app(ReferenceDataInstaller::class)->install();

    expect($support->refresh()->permissions)->toBe(['core.clients.view', 'domains.domains.view', 'services.services.view', 'support.*'])
        ->and($results['core_roles']['result'])->toEqual(new ReferenceDataResult(updated: 1, unchanged: 3));

    $log = ActivityLog::query()->where('event', 'role.updated')->sole();
    expect($log->subject_id)->toBe($support->id)
        ->and($log->properties)->toBe(['role' => 'Support', 'added' => ['core.clients.view', 'domains.domains.view', 'services.services.view'], 'removed' => ['billing.refunds.manage']]);

    Event::assertDispatched(RoleUpdated::class, fn (RoleUpdated $event) => $event->roleId === $support->id);
});

test('a renamed system role keeps its name and is still reset, and a custom role with a system name is never touched', function () {
    app(ReferenceDataInstaller::class)->install();
    $billing = Role::query()->where('key', 'billing')->sole();
    $billing->forceFill(['name' => 'Accounts', 'permissions' => ['billing.*']])->save();
    $custom = Role::factory()->create(['name' => 'Billing', 'permissions' => ['core.clients.view']]);

    $results = app(ReferenceDataInstaller::class)->install();

    expect($billing->refresh()->only(['name', 'is_system', 'permissions']))->toBe(['name' => 'Accounts', 'is_system' => true, 'permissions' => SystemRoles::permissions('billing')])
        ->and($custom->refresh()->only(['key', 'is_system', 'permissions']))->toBe(['key' => null, 'is_system' => false, 'permissions' => ['core.clients.view']])
        ->and(Role::query()->count())->toBe(5)
        ->and($results['core_roles']['result'])->toEqual(new ReferenceDataResult(updated: 1, unchanged: 3));
});

test('a missing system role whose name a custom role took is created under a free name', function () {
    $custom = Role::factory()->create(['name' => 'Sales', 'permissions' => ['orders.orders.view']]);

    app(ReferenceDataInstaller::class)->install();

    expect(Role::query()->where('key', 'sales')->sole()->name)->toBe('Sales (system)')
        ->and($custom->refresh()->only(['key', 'permissions']))->toBe(['key' => null, 'permissions' => ['orders.orders.view']]);
});

test('a generated password with console markup characters is printed exactly as it was hashed', function () {
    $password = 'Ab1\\<</><info>x</info>\\Zz9!';
    FirstAdministrator::generatePasswordsUsing(fn (): string => $password);

    try {
        [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        FirstAdministrator::generatePasswordsUsing(null);
    }

    expect($status)->toBe(0)
        ->and($output)->toContain("\n  {$password}\n")
        ->and(Hash::check($password, User::query()->sole()->password))->toBeTrue();
});

test('creating a full administrator re-checks under the role lock and refuses a second one unless asked', function () {
    app(ReferenceDataInstaller::class)->install();
    $administrators = app(FirstAdministrator::class);
    $administrators->create('First', 'first@example.test', STRONG_PASSWORD, temporary: false);

    expect(fn () => $administrators->create('Second', 'second@example.test', STRONG_PASSWORD, temporary: false))
        ->toThrow(AdministratorRefused::class, 'A full administrator already exists')
        ->and(fn () => $administrators->create('Again', 'first@example.test', STRONG_PASSWORD, temporary: false, additional: true))
        ->toThrow(AdministratorRefused::class, 'already belongs to an account')
        ->and(User::query()->pluck('email')->all())->toBe(['first@example.test']);

    $administrators->create('Second', 'second@example.test', STRONG_PASSWORD, temporary: false, additional: true);

    expect($administrators->count())->toBe(2);
});

test('two installs started at the same time create exactly one full administrator', function () {
    $config = config('database.connections.'.config('database.default'));
    config(['database.connections.install_race' => $config]);
    $race = DB::connection('install_race');
    $env = [
        'APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => (string) $config['database'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'],
        'OBJECTS_DRIVER' => 'local', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'XDEBUG_MODE' => 'off',
    ];
    $install = fn (string ...$options): array => ['php', 'artisan', 'app:install', '--no-interaction', ...$options];

    try {
        // Committed reference data first (the test's own transaction is invisible to other processes).
        Process::path(base_path())->env($env)->run($install());

        $results = Process::pool(fn (Pool $pool) => [
            $pool->as('a')->path(base_path())->env($env)->command($install('--admin-email=a@race.test')),
            $pool->as('b')->path(base_path())->env($env)->command($install('--admin-email=b@race.test')),
        ])->start()->wait();

        $exitCodes = [$results['a']->exitCode(), $results['b']->exitCode()];
        sort($exitCodes);

        expect($exitCodes)->toBe([0, 1])
            ->and($results['a']->output().$results['b']->output())->toContain('A full administrator already exists')
            ->and($race->table('users')->where('is_staff', true)->count())->toBe(1);
    } finally {
        // Everything the committed installs wrote, so later tests in this process start from an empty database.
        foreach (['activity_log', 'users', 'roles', 'currencies', 'email_template_translations', 'email_templates'] as $table) {
            $race->table($table)->delete();
        }

        DB::purge('install_race');
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Row locks need PostgreSQL; other processes cannot see an in-memory SQLite database');

test('in production the default password rule is the production policy', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(Password::defaults())->toEqual(PasswordPolicy::production());
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

test('a chosen password is accepted when the breach API cannot be reached', function () {
    Http::fake(['api.pwnedpasswords.com/range/*' => fn () => throw new ConnectionException('unreachable')]);
    Exceptions::fake();

    $this->artisan('app:install')
        ->expectsQuestion('Administrator name', 'Ada Admin')
        ->expectsQuestion('Administrator email', 'ada@example.test')
        ->expectsQuestion('Password', STRONG_PASSWORD)
        ->expectsQuestion('Confirm password', STRONG_PASSWORD)
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
    Exceptions::assertReported(ConnectionException::class);
});

test('in production the pre-flight warns when staff two-factor authentication is not required', function () {
    app(Settings::class)->set('security.staff_2fa_required', false);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        [$status, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('Two-factor authentication is not required for staff.');
});

test('in production the pre-flight warns when APP_URL is not the public https address', function (string $url, bool $warns) {
    config(['app.url' => $url]);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        [, $output] = runInstall(['--admin-email' => 'owner@example.test']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(str_contains($output, 'set the public https:// address'))->toBe($warns);
})->with([
    'http' => ['http://billing.example.test', true],
    'localhost' => ['https://localhost', true],
    'public https' => ['https://billing.example.test', false],
]);
