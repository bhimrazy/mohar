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

    protected function setUp(): void
    {
        parent::setUp();

        // Page components are asserted via inertia.testing.ensure_pages_exist; no built assets needed.
        $this->withoutVite();

        $this->releaseCommittedSavepoints();
        $this->refuseDatabaseInDatabaseFreeSuites();
        $this->forgetTrustedHostsAndProxies();
        $this->assumePatchedPhp();
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
     * for seeds that take tens of seconds, such as the demo billing history. The database is created and migrated
     * fresh the first time a process asks for it (<database>_<name> on PostgreSQL, a temporary file on SQLite), and
     * each test runs inside a transaction on it that is rolled back afterwards, so tests stay isolated. The test's
     * default connection points at it until tearDown(). PostgreSQL sequences are not rolled back, so a test on a
     * seeded database must not assert ids or sequence values.
     */
    protected function useSeededDatabase(string $name, Closure $seed): void
    {
        $default = (string) config('database.default');
        /** @var array<string, mixed> $config */
        $config = config("database.connections.{$default}");
        $database = $config['driver'] === 'sqlite'
            ? sys_get_temp_dir().DIRECTORY_SEPARATOR."mohar-test-{$name}-".getmypid().'.sqlite'
            : static::pgsqlIdentifier($config['database'].'_'.$name);
        $connection = "seeded_{$name}";

        config([
            "database.connections.{$connection}" => ['database' => $database] + $config,
            // A connection outside the test's transaction: PostgreSQL refuses CREATE DATABASE inside one.
            "database.connections.{$connection}_admin" => $config,
        ]);

        if (! in_array($name, self::$seededDatabases, true)) {
            $admin = Schema::connection("{$connection}_admin");
            $admin->dropDatabaseIfExists($database);
            $admin->createDatabase($database);

            if ($config['driver'] === 'sqlite') {
                register_shutdown_function(fn () => @unlink($database));
            }

            // A failing seed must leave the test's own database as the default; otherwise RefreshDatabase's teardown
            // rolls back the wrong connection and every later test in this worker fails.
            config(['database.default' => $connection]);

            try {
                Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
                $seed();
            } finally {
                config(['database.default' => $default]);
            }

            self::$seededDatabases[] = $name;
        }

        // Like RefreshDatabase: a test-aware transaction manager, so after-commit callbacks registered outside a nested
        // transaction (DB::afterCommit, ->afterCommit() jobs, ShouldDispatchAfterCommit events) run immediately.
        $this->app->instance('db.transactions', $manager = new DatabaseTransactionsManager([$connection]));
        $seeded = DB::connection($connection);
        $seeded->setTransactionManager($manager);
        $seeded->beginTransaction();
        config(['database.default' => $connection]);
        $this->seededConnection = [$connection, $default];
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
