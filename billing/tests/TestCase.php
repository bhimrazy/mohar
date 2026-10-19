<?php

namespace Tests;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Load only these modules (the MODULES env value, e.g. "Core" or "Services") for the tests of one file, to prove
     * behaviour without sibling modules. Set it in beforeAll() and reset it in afterAll(); null loads every module.
     * Files that set it live in tests/Extensions, which does not refresh the database: a module subset must never
     * migrate a database the other tests share.
     */
    public static ?string $onlyModules = null;

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
    }

    /**
     * Release each nested transaction's savepoint when it commits. Laravel only decrements its counter, so inside the
     * test's outer transaction (RefreshDatabase) every DB::transaction() leaves a savepoint open. PostgreSQL keeps all
     * of them on its subtransaction stack and checks the whole stack for every row the test has written, so a test
     * that commits thousands of nested transactions (the demo seeders) slows down quadratically: BillingSeederTest
     * took about 4x longer on PostgreSQL than on SQLite (docs/devops/ci.md). Rollback behaviour is unchanged: only
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
