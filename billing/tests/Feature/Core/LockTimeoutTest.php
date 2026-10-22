<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Connection;
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
            ->toThrow(RuntimeException::class, "DDL on table automation_runs did not get its lock within 200ms in 3 attempts, so it stopped instead of queueing in front of the table's reads and writes. In the way: sessions holding a lock on automation_runs: pid {$pid} (idle in transaction for");
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
