<?php

/*
 * Review probes for PR #306 (review board). Tests marked "finding" fail at e965f2d4 and pass once fixed;
 * the others pin behaviour the review verified.
 */

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

function reviewSession(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);

    return DB::connection($name);
}

/** A raw libpq connection, so a statement can run asynchronously beside the code under test. */
function reviewRawConnection(): PgSql\Connection
{
    $c = config('database.connections.'.config('database.default'));

    return pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s'", $c['host'], $c['port'], $c['database'], $c['username'], $c['password']), PGSQL_CONNECT_FORCE_NEW);
}

function reviewIndexOid(string $name): ?int
{
    $row = DB::selectOne('select oid from pg_class where relname = ?', [$name]);

    return $row === null ? null : (int) $row->oid;
}

test('finding: the last failed concurrent attempt leaves no INVALID unique index that still rejects writes', function () {
    Sleep::fake();
    config(['database.migrations.concurrent_lock_timeout' => '300ms']);
    $testConnection = config('database.default');
    reviewSession('pgsql_probe_ddl');
    DB::setDefaultConnection('pgsql_probe_ddl');
    // An unrelated old transaction (another table): a concurrent build waits for its snapshot.
    $old = reviewRawConnection();
    pg_query($old, 'begin isolation level repeatable read');
    pg_query($old, 'select count(*) from currencies');

    try {
        expect(fn () => OnlineIndex::create('automation_runs', ['task', 'status'], 'automation_runs_probe_unique', unique: true))
            ->toThrow(RuntimeException::class, 'in 3 attempts');

        // The deploy failed, so the old release keeps writing rows the new index was never meant to constrain yet.
        DB::table('automation_runs')->insert(['run_date' => '2026-01-01', 'task' => 'probe', 'status' => 'done']);
        $duplicate = fn () => DB::table('automation_runs')->insert(['run_date' => '2026-01-02', 'task' => 'probe', 'status' => 'done']);

        // At e965f2d4 this throws: duplicate key value violates unique constraint "automation_runs_probe_unique".
        expect($duplicate)->not->toThrow(QueryException::class);

        $left = DB::selectOne("select i.indisvalid as valid, i.indisready as ready from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'automation_runs_probe_unique'");

        // At e965f2d4: {valid: false, ready: true}. A ready unique index enforces uniqueness on every insert from
        // the still-live old release, after the deploy has stopped and reported nothing changed.
        expect($left)->toBeNull();
    } finally {
        pg_query($old, 'rollback');
        pg_close($old);
        DB::statement('drop index if exists automation_runs_probe_unique');
        DB::table('automation_runs')->where('task', 'probe')->delete();
        DB::setDefaultConnection($testConnection);
        DB::purge('pgsql_probe_ddl');
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

test('finding: an index another session is still building is not dropped once it has become valid', function () {
    config(['database.migrations.concurrent_lock_timeout' => '20s']);
    $testConnection = config('database.default');
    reviewSession('pgsql_probe_ddl');
    DB::setDefaultConnection('pgsql_probe_ddl');
    $old = reviewRawConnection();
    $builder = reviewRawConnection();

    try {
        // An old snapshot for 3s holds the other session's build in its last phase (INVALID, ready).
        pg_send_query($old, 'begin isolation level repeatable read; select count(*) from currencies; select pg_sleep(3); commit;');
        usleep(300_000);
        pg_send_query($builder, 'create index concurrently automation_runs_probe_race on automation_runs (task, id)');

        $oid = null;
        for ($i = 0; $i < 50 && $oid === null; $i++) {
            usleep(100_000);
            $oid = reviewIndexOid('automation_runs_probe_race');
        }
        expect($oid)->not->toBeNull();

        // Same name, same definition: what a second migrator (no `migrate --isolated`, no deploy lock) or a re-run
        // after a killed deploy does. dropIfInvalid sees INVALID, the DROP waits, the build finishes, the DROP runs.
        OnlineIndex::create('automation_runs', ['task', 'id'], 'automation_runs_probe_race');

        $result = pg_get_result($builder);
        expect(pg_result_status($result))->toBe(PGSQL_COMMAND_OK)
            // At e965f2d4 the oid differs: the other session's finished, valid index was dropped and rebuilt.
            ->and(reviewIndexOid('automation_runs_probe_race'))->toBe($oid);
    } finally {
        while (pg_get_result($old) !== false);
        while (pg_get_result($builder) !== false);
        pg_close($old);
        pg_close($builder);
        DB::statement('drop index if exists automation_runs_probe_race');
        DB::setDefaultConnection($testConnection);
        DB::purge('pgsql_probe_ddl');
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

test('finding: a predicate that qualifies its columns with the table name is compared, not a SQL error', function () {
    OnlineIndex::create('automation_runs', ['run_date', 'id'], 'automation_runs_probe_index', where: "automation_runs.status = 'failed'", allowLocking: true);

    OnlineIndex::create('automation_runs', ['run_date', 'id'], 'automation_runs_probe_index', where: "automation_runs.status = 'failed'", allowLocking: true);

    expect(DB::selectOne("select count(*) as n from pg_class where relname = 'automation_runs_probe_index'")->n)->toBe(1);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

test('finding: on SQLite a partial index written without a space before WHERE is not taken for a full one', function () {
    DB::statement("create index automation_runs_probe_index on automation_runs (run_date, id)where status = 'failed'");

    expect(fn () => OnlineIndex::create('automation_runs', ['run_date', 'id'], 'automation_runs_probe_index'))
        ->toThrow(LogicException::class, 'different predicate');
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'SQLite only');

test('the savepoint path puts back a lock_timeout the outer transaction set, after success and after failure', function () {
    Sleep::fake();
    DB::select("select set_config('lock_timeout', '7s', true)");

    LockTimeout::run('automation_runs', fn () => DB::statement('select 1'), timeout: '200ms');
    expect(DB::selectOne("select current_setting('lock_timeout') as v")->v)->toBe('7s');

    $blocker = reviewSession('pgsql_probe_blocker');
    $blocker->beginTransaction();
    $blocker->select('select count(*) from automation_runs');

    try {
        expect(fn () => LockTimeout::run('automation_runs', fn () => DB::statement('alter table automation_runs add column probe_x int'), timeout: '200ms', attempts: 2))
            ->toThrow(RuntimeException::class);
    } finally {
        $blocker->rollBack();
        DB::purge('pgsql_probe_blocker');
    }

    expect(DB::selectOne("select current_setting('lock_timeout') as v")->v)->toBe('7s')
        ->and(DB::selectOne('select 1 as ok')->ok)->toBe(1);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

test('an error other than a lock timeout is thrown at once, without a retry', function () {
    Sleep::fake();
    $calls = 0;

    expect(function () use (&$calls) {
        LockTimeout::run('automation_runs', function () use (&$calls) {
            $calls++;
            DB::statement('select 1 / 0');
        });
    })->toThrow(QueryException::class, 'division by zero');

    expect($calls)->toBe(1);
    Sleep::assertNeverSlept();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');
