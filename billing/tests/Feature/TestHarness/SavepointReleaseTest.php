<?php

// Review probes for PR #91: Tests\TestCase releases the savepoint of each committed nested transaction. These pin
// down that the release really happens (the PostgreSQL speed-up depends on it) and that rollback semantics inside
// the RefreshDatabase transaction are unchanged, on SQLite and PostgreSQL.

use Illuminate\Support\Facades\DB;

/** @return list<string> */
function probeRows(): array
{
    return DB::table('probe_savepoints')->orderBy('name')->pluck('name')->all();
}

beforeEach(function () {
    DB::statement('CREATE TABLE probe_savepoints (name VARCHAR(20) NOT NULL)');
});

it('releases the savepoint of a committed nested transaction', function () {
    DB::transaction(fn () => DB::table('probe_savepoints')->insert(['name' => 'a']));

    // trans2 was the nested transaction's savepoint; once released, rolling back to it is an error.
    expect(fn () => DB::connection()->getPdo()->exec('ROLLBACK TO SAVEPOINT trans2'))->toThrow(PDOException::class);
})->skip(fn () => DB::connection()->getDriverName() === 'pgsql', 'on PostgreSQL the failed statement aborts the test transaction; covered by the next test');

it('leaves no open savepoint behind a committed nested transaction on PostgreSQL', function () {
    DB::transaction(fn () => DB::table('probe_savepoints')->insert(['name' => 'a']));

    // A savepoint of our own around the probe, so the expected error does not abort the test transaction.
    $pdo = DB::connection()->getPdo();
    $pdo->exec('SAVEPOINT probe');

    try {
        $pdo->exec('RELEASE SAVEPOINT trans2');
        $released = false;
    } catch (PDOException) {
        $released = true;
    }

    $pdo->exec('ROLLBACK TO SAVEPOINT probe');

    expect($released)->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

it('keeps a committed sibling when a later nested transaction rolls back', function () {
    DB::transaction(fn () => DB::table('probe_savepoints')->insert(['name' => 'kept']));

    try {
        DB::transaction(function () {
            DB::table('probe_savepoints')->insert(['name' => 'undone']);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(probeRows())->toBe(['kept']);
});

it('undoes a committed inner transaction when its outer nested transaction rolls back', function () {
    try {
        DB::transaction(function () {
            DB::table('probe_savepoints')->insert(['name' => 'outer']);
            DB::transaction(fn () => DB::table('probe_savepoints')->insert(['name' => 'inner']));

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(probeRows())->toBe([]);
});

it('keeps working over many released savepoints and a manual three-level stack', function () {
    for ($i = 0; $i < 300; $i++) {
        DB::transaction(fn () => DB::table('probe_savepoints')->insert(['name' => "r{$i}"]));
    }

    DB::beginTransaction();
    DB::table('probe_savepoints')->insert(['name' => 'l2']);
    DB::beginTransaction();
    DB::table('probe_savepoints')->insert(['name' => 'l3']);
    DB::commit();
    DB::rollBack();

    expect(DB::table('probe_savepoints')->count())->toBe(300)
        ->and(DB::transactionLevel())->toBe(1);
});

it('runs an after-commit callback outside a nested transaction immediately (control for SeededDatabaseTest)', function () {
    $ran = false;
    DB::afterCommit(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});
