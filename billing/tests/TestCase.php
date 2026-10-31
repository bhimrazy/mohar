<?php

namespace Tests;

use App\Modules\Core\Install\PhpSecurityRelease;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Load only these modules (the MODULES env value, e.g. "Core" or "Services") for the tests of one file, to prove
     * behaviour without sibling modules. Set it in beforeAll() and reset it in afterAll(); null loads every module.
     * Files that set it live in tests/Extensions, which does not refresh the database: a module subset must never
     * migrate a database the other tests share.
     */
    public static ?string $onlyModules = null;

    /** @var list<string> Databases useSeededDatabase() has created and seeded in this process. */
    private static array $seededDatabases = [];

    /** @var array<string, string> The migrated template of this process, per test database (seededTemplate()). */
    private static array $seededTemplates = [];

    /** @var array{0: string, 1: string}|null The seeded connection this test uses and the default it replaced. */
    private ?array $seededConnection = null;

    public function createApplication()
    {
        if (self::$onlyModules === null) {
            return parent::createApplication();
        }

        $previous = [$_ENV['MODULES'] ?? null, $_SERVER['MODULES'] ?? null];
        $_ENV['MODULES'] = $_SERVER['MODULES'] = self::$onlyModules;

        try {
            return parent::createApplication();
        } finally {
            [$env, $server] = $previous;

            if ($env === null) {
                unset($_ENV['MODULES']);
            } else {
                $_ENV['MODULES'] = $env;
            }

            if ($server === null) {
                unset($_SERVER['MODULES']);
            } else {
                $_SERVER['MODULES'] = $server;
            }
        }
    }

    /**
     * Skips the simulator suite as a whole class off Linux (simulatorHostProblem()): skipped here, no beforeEach or
     * afterEach of the file runs, so none of them fails on what the other never set up.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (preg_match('/^P\\\\Tools\\\\simulators\\\\tests\\\\/', static::class) === 1 && ($reason = static::simulatorHostProblem()) !== null) {
            static::markTestSkipped($reason);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Page components are asserted via inertia.testing.ensure_pages_exist; no built assets needed.
        $this->withoutVite();

        $this->releaseCommittedSavepoints();
        $this->refuseDatabaseInDatabaseFreeSuites();
        $this->forgetTrustedHostsAndProxies();
        $this->assumePatchedPhp();
        $this->shortenWaits();

        if (! $this->allowsStrayHttp()) {
            Http::preventStrayRequests();
        }
    }

    /**
     * An outbound request no Http::fake() answers throws instead of leaving the machine. Only a test that talks to a
     * real local process opts out: the simulator harness (tests/Simulators) overrides this to return true. Do not
     * override it to silence a failing test; fake the request.
     */
    protected function allowsStrayHttp(): bool
    {
        return false;
    }

    /**
     * Waits that really sleep in a test, shortened here only (production config has no way to lower them):
     * - auth.timebox_duration 0: the SessionGuard timebox (validate() and attempt(): password confirmation, step-up)
     *   otherwise takes 200 ms per check. Fortify's sign-in form keeps its own fixed 200 ms. Guards resolved while
     *   booting are forgotten, so the next one reads the new value.
     * - lookup retries with no delay (Guzzle's MockHandler sleeps for it under Http::fake), keeping the number of
     *   attempts production makes.
     * - a short mail test send (platform.mail_test.*, 10 s and 20 s in production).
     * A test about the real wait sets the production value again.
     */
    private function shortenWaits(): void
    {
        foreach (['payments_nepal', 'payments_cybersource', 'payments_stripe', 'payments_razorpay'] as $module) {
            $key = "{$module}.http.lookup_retry_delays_ms";
            config([$key => array_fill(0, count((array) config($key)), 0)]);
        }

        config([
            'auth.timebox_duration' => 0,
            'platform.mail_test.timeout_seconds' => 2,
            'platform.mail_test.deadline_seconds' => 4,
        ]);
        $this->app['auth']->forgetGuards();
    }

    /**
     * Why the simulator suite (tools/simulators/tests) cannot run on this host, or null on Linux. The simulators start
     * their processes with setsid(1) and find them through /proc, which macOS lacks, so there every one of those tests
     * would error; scripts/test.sh runs the suite through scripts/linux.sh instead. Only the OS decides: on Linux a
     * missing setsid still fails the tests loudly rather than skipping them in CI.
     */
    public static function simulatorHostProblem(string $os = PHP_OS_FAMILY): ?string
    {
        return $os === 'Linux' ? null : "The simulators need Linux (setsid, /proc), not {$os}: run tools/simulators/tests through scripts/linux.sh (scripts/test.sh does).";
    }

    /**
     * The install check and the dashboard report a vulnerable PHP build (PhpSecurityRelease); development machines and
     * CI run older 8.4 builds, so outcomes would depend on the runner. Tests of that check bind their own version.
     */
    private function assumePatchedPhp(): void
    {
        $this->app->instance(PhpSecurityRelease::class, new PhpSecurityRelease(PhpSecurityRelease::PATCHED['8.4']));
    }

    /**
     * Symfony keeps trusted hosts and proxies in static properties, so a test that runs TrustHosts or TrustProxies
     * outside the testing environment leaks them to every later test in the same worker: a request for a foreign
     * host then fails with 400 depending on test order (CI on #94). Start every test from Symfony's defaults.
     */
    private function forgetTrustedHostsAndProxies(): void
    {
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], -1);
    }

    /**
     * tests/Unit and tests/Arch run only in the SQLite step of CI, not again on PostgreSQL (docs/devops/ci.md), so a
     * test there must never touch the database. This fails one that does; move it to tests/Feature instead.
     */
    private function refuseDatabaseInDatabaseFreeSuites(): void
    {
        if (preg_match('/(^|\\\\)Tests\\\\(Unit|Arch)\\\\/', static::class) !== 1) {
            return;
        }

        $message = static::class.' is in a database-free suite (tests/Unit, tests/Arch), which CI does not run on PostgreSQL; move it to tests/Feature.';
        $databaseTraits = [RefreshDatabase::class, LazilyRefreshDatabase::class, DatabaseMigrations::class, DatabaseTransactions::class, DatabaseTruncation::class];

        if (array_intersect($databaseTraits, class_uses_recursive($this)) !== []) {
            throw new LogicException($message);
        }

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) use ($message): void {
            throw new LogicException($message.' Query: '.$query->sql);
        });
    }

    /**
     * A PostgreSQL identifier of at most $max bytes (63 is the server limit). A name that fits is returned unchanged; a
     * longer one keeps its first ($max - 9) bytes, "_", and the first 8 hex digits of its SHA-256, so names that differ
     * only at the end stay distinct. scripts/pg.sh applies the same rule (name_fit) to its database names.
     */
    public static function pgsqlIdentifier(string $name, int $max = 63): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 9).'_'.substr(hash('sha256', $name), 0, 8);
    }

    /**
     * Run this test on a database that $seed fills once per worker process, instead of seeding inside every test:
     * for seeds that take tens of seconds, such as the demo billing history. The database is created the first time a
     * process asks for it (<database>_<name> on PostgreSQL, a temporary file on SQLite), and each test runs inside a
     * transaction on it that is rolled back afterwards, so tests stay isolated. The test's default connection points at
     * it until tearDown(). PostgreSQL sequences are not rolled back, so a test on a seeded database must not assert ids
     * or sequence values.
     *
     * The database starts as a copy of a schema this process migrates once (seededTemplate()); $freshMigrate runs the
     * migrations on it instead, for a test about what migrating does.
     */
    protected function useSeededDatabase(string $name, Closure $seed, bool $freshMigrate = false): void
    {
        $default = (string) config('database.default');
        $connection = $this->prepareSeededDatabase($name, $seed, $freshMigrate);

        // Like RefreshDatabase: a test-aware transaction manager, so after-commit callbacks registered outside a nested
        // transaction (DB::afterCommit, ->afterCommit() jobs, ShouldDispatchAfterCommit events) run immediately.
        $this->app->instance('db.transactions', $manager = new DatabaseTransactionsManager([$connection]));
        $seeded = DB::connection($connection);
        $seeded->setTransactionManager($manager);
        $seeded->beginTransaction();
        config(['database.default' => $connection]);
        $this->seededConnection = [$connection, $default];
    }

    /**
     * Create and seed the database useSeededDatabase() runs on, once per process, and return its connection name (no
     * transaction is opened here).
     */
    protected function prepareSeededDatabase(string $name, Closure $seed, bool $freshMigrate = false): string
    {
        if ($name === 'template' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new LogicException("Seeded database name [{$name}] must be letters, digits and _, and not [template].");
        }

        $default = (string) config('database.default');
        /** @var array<string, mixed> $config */
        $config = config("database.connections.{$default}");
        $database = $this->seededDatabaseName($config, $name);
        $connection = "seeded_{$name}";

        config([
            "database.connections.{$connection}" => ['database' => $database] + $config,
            // A connection outside the test's transaction: PostgreSQL refuses CREATE DATABASE inside one.
            "database.connections.{$connection}_admin" => $config,
        ]);

        if (in_array($name, self::$seededDatabases, true)) {
            return $connection;
        }

        $admin = Schema::connection("{$connection}_admin");
        $admin->dropDatabaseIfExists($database);

        if ($config['driver'] === 'sqlite') {
            register_shutdown_function(fn () => @unlink($database));
        }

        if ($freshMigrate) {
            $admin->createDatabase($database);
        } else {
            $this->copySeededTemplate($config, "{$connection}_admin", $database);
        }

        // A failing seed must leave the test's own database as the default; otherwise RefreshDatabase's teardown
        // rolls back the wrong connection and every later test in this worker fails.
        config(['database.default' => $connection]);

        try {
            if ($freshMigrate) {
                Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
            }

            $seed();
        } finally {
            config(['database.default' => $default]);
        }

        self::$seededDatabases[] = $name;

        return $connection;
    }

    /**
     * <database>_<name> on PostgreSQL (seededPgsqlName()), a temporary file per process on SQLite.
     *
     * @param  array<string, mixed>  $config
     */
    private function seededDatabaseName(array $config, string $name): string
    {
        return $config['driver'] === 'sqlite'
            ? sys_get_temp_dir().DIRECTORY_SEPARATOR."mohar-test-{$name}-".getmypid().'.sqlite'
            : static::seededPgsqlName((string) $config['database'], $name);
    }

    /**
     * <database>_<name> when it fits PostgreSQL's 63 bytes. Otherwise the slug's database (<database> without the
     * worker's _test_<n>, at most the 55 bytes scripts/pg.sh gives it), "_" and a hash of the full name: the name still
     * starts with the slug's database, so pg.sh --drop finds it, and stays distinct per worker and name.
     */
    public static function seededPgsqlName(string $database, string $name): string
    {
        $full = "{$database}_{$name}";

        if (strlen($full) <= 63) {
            return $full;
        }

        $base = substr((string) preg_replace('/_test_[0-9]+$/', '', $database), 0, 55);

        return $base.'_'.substr(hash('sha256', $full), 0, min(8, 63 - strlen($base) - 1));
    }

    /**
     * Create $database as a copy of the migrated template: CREATE DATABASE … TEMPLATE on PostgreSQL (about 0.1 s
     * against about 5 s for migrating), a file copy on SQLite.
     *
     * @param  array<string, mixed>  $config
     */
    private function copySeededTemplate(array $config, string $admin, string $database): void
    {
        $template = $this->seededTemplate($config);

        if ($config['driver'] === 'sqlite') {
            if (! copy($template, $database)) {
                throw new LogicException("Could not copy the seeded template {$template} to {$database}.");
            }

            return;
        }

        DB::connection($admin)->statement(sprintf('CREATE DATABASE "%s" TEMPLATE "%s"', $database, $template));
    }

    /**
     * The migrated schema every seeded database of this process is copied from: <database>_template, created and
     * migrated the first time it is needed. PostgreSQL copies a template only while no session is connected to it, so
     * it is never the worker's own test database (which the test holds open) and its connection is closed after the
     * migration.
     *
     * @param  array<string, mixed>  $config
     */
    private function seededTemplate(array $config): string
    {
        $key = $config['driver'].':'.$config['database'];

        if (isset(self::$seededTemplates[$key])) {
            return self::$seededTemplates[$key];
        }

        $template = $this->seededDatabaseName($config, 'template');
        $connection = 'seeded_template';
        $default = (string) config('database.default');
        config([
            "database.connections.{$connection}" => ['database' => $template] + $config,
            "database.connections.{$connection}_admin" => $config,
        ]);

        $admin = Schema::connection("{$connection}_admin");
        $admin->dropDatabaseIfExists($template);
        $admin->createDatabase($template);

        if ($config['driver'] === 'sqlite') {
            register_shutdown_function(fn () => @unlink($template));
        }

        // Migrations that use DB:: directly run on the default connection.
        config(['database.default' => $connection]);

        try {
            Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
        } finally {
            config(['database.default' => $default]);
            DB::purge($connection);
        }

        return self::$seededTemplates[$key] = $template;
    }

    protected function tearDown(): void
    {
        // Before the application is destroyed: RefreshDatabase then rolls back the default connection, which must be
        // the test database again, not the seeded one.
        if ($this->seededConnection !== null) {
            [$connection, $default] = $this->seededConnection;
            $this->seededConnection = null;
            DB::connection($connection)->rollBack();
            config(['database.default' => $default]);
        }

        parent::tearDown();
        $this->assertTrackedTestingPlaceholderKept();
    }

    /**
     * storage/framework/testing/.gitignore is tracked (#54). A test that wipes the directory instead of its own
     * subdirectory deletes it; put it back and fail that test, so a clean checkout stays clean.
     */
    private function assertTrackedTestingPlaceholderKept(): void
    {
        // Not storage_path(): the application is gone after parent::tearDown().
        $file = dirname(__DIR__).'/storage/framework/testing/.gitignore';

        if (file_exists($file)) {
            return;
        }

        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, "*\n!.gitignore\n");
        $this->fail('This test deleted the tracked storage/framework/testing/.gitignore; clean only its own subdirectory.');
    }

    /**
     * Release each nested transaction's savepoint when it commits. Laravel only decrements its counter, so inside the
     * test's outer transaction (RefreshDatabase) every DB::transaction() leaves a savepoint open. PostgreSQL keeps all
     * of them on its subtransaction stack and checks the whole stack for every row the test has written, so a test
     * that commits thousands of nested transactions (the demo seeders) slows down quadratically: BillingSeederTest
     * took about 5x longer on PostgreSQL than on SQLite (docs/devops/ci.md). Rollback behaviour is unchanged: only
     * committed savepoints are released. The statement goes straight to PDO so query listeners and counts miss it.
     */
    private function releaseCommittedSavepoints(): void
    {
        $this->app['events']->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $level = $event->connection->transactionLevel();

            if ($level > 0) {
                $event->connection->getPdo()->exec('RELEASE SAVEPOINT trans'.($level + 1));
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
