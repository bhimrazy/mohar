<?php

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Role;
use App\Support\Install\ReferenceDataRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
            ->mapWithKeys(fn (Currency $currency) => [$currency->code => $currency->only(['name', 'prefix', 'suffix', 'decimals', 'format', 'exchange_rate', 'is_auto_rate', 'is_default'])])->all(),
        'roles' => Role::query()->where('is_system', true)->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->only(['description', 'permissions'])])->all(),
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
    preg_match('/^\s{2}(\S{24})$/m', $output, $match);

    expect($admin->only(['name', 'email', 'is_staff', 'is_active', 'must_change_password']))
        ->toBe(['name' => 'Olga Owner', 'email' => 'owner@example.test', 'is_staff' => true, 'is_active' => true, 'must_change_password' => true])
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->role?->name)->toBe('Full Administrator')
        ->and($match)->toHaveCount(2)
        ->and(Hash::check($match[1], $admin->password))->toBeTrue();

    expect(Currency::query()->orderBy('code')->pluck('is_default', 'code')->all())->toBe(['INR' => false, 'NPR' => false, 'USD' => true])
        ->and(Role::query()->where('is_system', true)->orderBy('name')->pluck('name')->all())->toBe(['Billing', 'Full Administrator', 'Sales', 'Support'])
        ->and(Role::query()->count())->toBe(4)
        ->and(tablesWithRows())->toBe(['activity_log', 'currencies', 'migrations', 'roles', 'users']);

    $log = ActivityLog::query()->sole();
    expect($log->event)->toBe('staff.created')
        ->and($log->user_id)->toBeNull()
        ->and(json_encode($log->properties))->not->toContain($match[1]);

    Event::assertDispatched(StaffCreated::class, fn (StaffCreated $event) => $event->userId === $admin->id);
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
        ->and(tablesWithRows())->toBe(['activity_log', 'currencies', 'migrations', 'roles', 'users']);
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

test('USD does not take the default from a currency staff already made the default', function () {
    Currency::factory()->euro()->create(['is_default' => true]);

    app(ReferenceDataInstaller::class)->install();

    expect(Currency::query()->orderBy('code')->pluck('is_default', 'code')->all())
        ->toBe(['EUR' => true, 'INR' => false, 'NPR' => false, 'USD' => false]);
});

test('an existing currency keeps the rate and symbol staff set', function () {
    Currency::factory()->inr()->create(['exchange_rate' => 88.5, 'prefix' => 'Rs ']);

    $results = app(ReferenceDataInstaller::class)->install();

    expect(Currency::query()->where('code', 'INR')->sole()->only(['exchange_rate', 'prefix']))->toBe(['exchange_rate' => '88.500000', 'prefix' => 'Rs '])
        ->and($results['core_currencies']['result'])->toEqual(new ReferenceDataResult(created: 2, unchanged: 1));
});

test('a system role edited away from its definition is restored and reported as updated', function () {
    app(ReferenceDataInstaller::class)->install();
    Role::query()->where('name', 'Support')->update(['permissions' => json_encode(['support.*', 'billing.refunds.manage'])]);

    $results = app(ReferenceDataInstaller::class)->install();

    expect(Role::query()->where('name', 'Support')->sole()->permissions)->toBe(['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view'])
        ->and($results['core_roles']['result'])->toEqual(new ReferenceDataResult(updated: 1, unchanged: 3));
});
