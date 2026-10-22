<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * A second session on the same PostgreSQL database, standing in for a long transaction in production.
 */
function lockTimeoutSession(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);

    return DB::connection($name);
}

function addProbeNote(): void
{
    Schema::table('automation_runs', fn (Blueprint $table) => $table->string('probe_note')->nullable());
}

test('on SQLite the DDL runs as it is', function () {
    LockTimeout::run('automation_runs', addProbeNote(...), timeout: '100ms');

    expect(Schema::hasColumn('automation_runs', 'probe_note'))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'SQLite only');

test('an ALTER behind an open transaction gives up after its retries and names the blocking session', function () {
    Sleep::fake();
    $blocker = lockTimeoutSession('pgsql_lock_blocker');
    $blocker->beginTransaction();
    $blocker->select('select count(*) from automation_runs');
    $pid = (int) $blocker->selectOne('select pg_backend_pid() as pid')->pid;

    try {
        expect(fn () => LockTimeout::run('automation_runs', addProbeNote(...), timeout: '200ms', attempts: 3))
            ->toThrow(RuntimeException::class, "DDL on table automation_runs did not get its lock within 200ms in 3 attempts, so it stopped instead of waiting on (a waiting ALTER holds up every later read and write of the table). In the way: sessions holding a lock on automation_runs: pid {$pid} (idle in transaction for");
    } finally {
        $blocker->rollBack();
        DB::purge('pgsql_lock_blocker');
    }

    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
    expect(Schema::hasColumn('automation_runs', 'probe_note'))->toBeFalse()
        ->and(DB::selectOne("select current_setting('lock_timeout') as value")->value)->toBe('0');

    // The surrounding transaction survived the failed attempts, and the same DDL goes through once the lock is free.
    LockTimeout::run('automation_runs', addProbeNote(...), timeout: '200ms');

    expect(Schema::hasColumn('automation_runs', 'probe_note'))->toBeTrue()
        ->and(DB::selectOne("select current_setting('lock_timeout') as value")->value)->toBe('0');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'lock_timeout exists on PostgreSQL only');

test('a concurrent index build behind a lock gives up, restores the session timeout and leaves no invalid index', function () {
    Sleep::fake();
    config(['database.migrations.concurrent_lock_timeout' => '200ms']);
    $testConnection = config('database.default');
    // The test's own connection is inside a transaction, where CONCURRENTLY cannot run: build on a fresh session.
    lockTimeoutSession('pgsql_lock_ddl');
    DB::setDefaultConnection('pgsql_lock_ddl');
    $blocker = lockTimeoutSession('pgsql_lock_blocker');
    $blocker->beginTransaction();
    $blocker->statement('lock table automation_runs in share update exclusive mode');

    try {
        expect(fn () => OnlineIndex::create('automation_runs', ['task', 'status'], 'automation_runs_lock_probe_index'))
            ->toThrow(RuntimeException::class, 'DDL on table automation_runs did not get its lock within 200ms in 3 attempts');
        Sleep::assertSleptTimes(2);
        expect(DB::selectOne("select current_setting('lock_timeout') as value")->value)->toBe('0')
            ->and(DB::selectOne("select count(*) as n from pg_class where relname = 'automation_runs_lock_probe_index'")->n)->toBe(0);

        $blocker->rollBack();
        OnlineIndex::create('automation_runs', ['task', 'status'], 'automation_runs_lock_probe_index');

        expect(DB::selectOne("select i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'automation_runs_lock_probe_index'")?->valid)->toBeTrue();
    } finally {
        if ($blocker->transactionLevel() > 0) {
            $blocker->rollBack();
        }

        // Built outside the test transaction, so it would outlive the test.
        OnlineIndex::drop('automation_runs', 'automation_runs_lock_probe_index');
        DB::setDefaultConnection($testConnection);
        DB::purge('pgsql_lock_blocker');
        DB::purge('pgsql_lock_ddl');
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'CONCURRENTLY exists on PostgreSQL only');

test('a statement that needs to run outside a transaction is refused inside one', function () {
    expect(fn () => LockTimeout::run('automation_runs', addProbeNote(...), transaction: false))
        ->toThrow(LogicException::class, 'withinTransaction = false');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

test('a retried concurrent build drops the INVALID index a failed build left and builds a valid one', function () {
    $testConnection = config('database.default');
    lockTimeoutSession('pgsql_lock_ddl');
    DB::setDefaultConnection('pgsql_lock_ddl');

    try {
        // A unique build over duplicate rows fails half way and leaves its index INVALID, as an interrupted deploy does.
        DB::table('automation_runs')->insert([
            ['run_date' => '2026-01-01', 'task' => 'invalid-probe', 'status' => 'done'],
            ['run_date' => '2026-01-02', 'task' => 'invalid-probe', 'status' => 'done'],
        ]);
        expect(fn () => DB::statement('create unique index concurrently automation_runs_invalid_probe_unique on automation_runs (task)'))
            ->toThrow(QueryException::class, 'could not create unique index');
        $left = DB::selectOne("select c.oid, i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'automation_runs_invalid_probe_unique'");
        expect($left?->valid)->toBeFalse();

        DB::table('automation_runs')->where('task', 'invalid-probe')->where('run_date', '2026-01-02')->delete();
        OnlineIndex::create('automation_runs', ['task'], 'automation_runs_invalid_probe_unique', unique: true);

        $rebuilt = DB::selectOne("select c.oid, i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'automation_runs_invalid_probe_unique'");
        expect($rebuilt?->valid)->toBeTrue()
            ->and($rebuilt?->oid)->not->toBe($left->oid);
    } finally {
        DB::statement('drop index if exists automation_runs_invalid_probe_unique');
        DB::table('automation_runs')->where('task', 'invalid-probe')->delete();
        DB::setDefaultConnection($testConnection);
        DB::purge('pgsql_lock_ddl');
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'CONCURRENTLY exists on PostgreSQL only');

test('a migration with its own connection gets the lock timeout on that connection', function () {
    $testConnection = config('database.default');
    lockTimeoutSession('pgsql_lock_own');
    $directory = storage_path('framework/testing/lock-timeout-'.uniqid());
    mkdir($directory, 0755, true);
    file_put_contents($directory.'/2099_01_01_000000_lock_timeout_probe.php', <<<'PHP'
        <?php

        use App\Modules\Core\Support\LockTimeout;
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        return new class extends Migration
        {
            protected $connection = 'pgsql_lock_own';

            public function up(): void
            {
                LockTimeout::run('automation_runs', function (): void {
                    $GLOBALS['lockTimeoutProbe'] = [
                        'default' => DB::connection()->getName(),
                        'own' => DB::connection('pgsql_lock_own')->selectOne("select current_setting('lock_timeout') as value")->value,
                    ];
                }, timeout: '250ms');
            }
        };
        PHP);

    try {
        app('migrator')->run([$directory]);

        // Laravel makes the migration's connection the default while up() runs, so the helper sets it there.
        expect($GLOBALS['lockTimeoutProbe'])->toBe(['default' => 'pgsql_lock_own', 'own' => '250ms'])
            ->and(DB::connection($testConnection)->selectOne("select current_setting('lock_timeout') as value")->value)->toBe('0');
    } finally {
        unset($GLOBALS['lockTimeoutProbe']);
        DB::setDefaultConnection($testConnection);
        DB::table('migrations')->where('migration', '2099_01_01_000000_lock_timeout_probe')->delete();
        DB::purge('pgsql_lock_own');
        array_map('unlink', glob($directory.'/*.php') ?: []);
        rmdir($directory);
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'lock_timeout exists on PostgreSQL only');
