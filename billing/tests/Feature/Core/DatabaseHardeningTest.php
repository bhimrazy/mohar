<?php

use App\Models\User;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\OnlineIndex;
use App\Modules\Support\Database\Seeders\SupportSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('only one currency can be the default', function () {
    Currency::factory()->create();
    Currency::factory()->euro()->create();

    expect(fn () => Currency::factory()->inr()->create(['is_default' => true]))->toThrow(QueryException::class);
});

test('chunked delete removes every matching row in batches and nothing else', function () {
    $rows = collect(range(1, 12))->map(fn (int $day) => ['run_date' => sprintf('2026-10-%02d', $day), 'task' => 'audit.probe', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('automation_runs')->insert($rows->all());
    DB::enableQueryLog();

    $deleted = ChunkedDelete::run(DB::table('automation_runs')->where('run_date', '<', '2026-10-12'), batch: 5);

    expect($deleted)->toBe(11)
        ->and(DB::table('automation_runs')->pluck('run_date')->map(fn ($date) => substr((string) $date, 0, 10))->all())->toBe(['2026-10-12'])
        ->and(collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with(strtolower($query['query']), 'delete'))->count())->toBe(3);
});

test('chunked delete keeps a row that stopped matching after its id was selected', function () {
    DB::table('automation_runs')->insert(collect(['2026-10-01', '2026-10-02', '2026-10-03'])
        ->map(fn (string $date) => ['run_date' => $date, 'task' => 'audit.probe', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()])->all());
    $changed = false;
    // Between the id select and the delete, another process fixes one row (as an extended cart would).
    DB::listen(function (QueryExecuted $query) use (&$changed): void {
        if (! $changed && str_starts_with(strtolower($query->sql), 'select')) {
            $changed = true;
            DB::table('automation_runs')->where('run_date', '2026-10-02')->update(['status' => 'success']);
        }
    });

    $deleted = ChunkedDelete::run(DB::table('automation_runs')->where('status', 'failed'));

    expect($deleted)->toBe(2)
        ->and(DB::table('automation_runs')->pluck('status')->all())->toBe(['success']);
});

test('online indexes are created once and dropped by their default name', function () {
    // Tests run inside a transaction; migrations run outside one and build CONCURRENTLY.
    OnlineIndex::create('automation_runs', ['status', 'id'], allowLocking: true);
    OnlineIndex::create('automation_runs', ['status', 'id'], allowLocking: true);

    expect(Schema::hasIndex('automation_runs', 'automation_runs_status_id_index'))->toBeTrue();

    OnlineIndex::drop('automation_runs', ['status', 'id'], allowLocking: true);

    expect(Schema::hasIndex('automation_runs', 'automation_runs_status_id_index'))->toBeFalse();
});

test('an existing index of the same name is reused only with the same table, columns and uniqueness', function (string $table, array $columns, bool $unique) {
    OnlineIndex::create('automation_runs', ['status'], 'automation_runs_probe_index', allowLocking: true);

    expect(fn () => OnlineIndex::create($table, $columns, 'automation_runs_probe_index', unique: $unique, allowLocking: true))
        ->toThrow(LogicException::class, 'already exists with a different definition');
})->with([
    'other columns' => ['automation_runs', ['task'], false],
    'other table' => ['settings', ['key'], false],
    'unique' => ['automation_runs', ['status'], true],
]);

test('dropping an index by name refuses an index of another table', function () {
    OnlineIndex::create('automation_runs', ['status'], 'automation_runs_probe_index', allowLocking: true);

    expect(fn () => OnlineIndex::drop('settings', 'automation_runs_probe_index', allowLocking: true))->toThrow(LogicException::class, 'belongs to table automation_runs');
});

test('index names longer than PostgreSQL allows are refused', function () {
    expect(fn () => OnlineIndex::create('automation_runs', ['status'], str_repeat('x', 64), allowLocking: true))->toThrow(LogicException::class, 'PostgreSQL allows 63');
});

test('on PostgreSQL an index build inside a transaction is refused instead of blocking writes', function () {
    expect(fn () => OnlineIndex::create('automation_runs', ['status', 'id']))->toThrow(LogicException::class, 'withinTransaction = false');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'CONCURRENTLY exists on PostgreSQL only');

test('demo seeders called one by one refuse to run in production', function (array $options) {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => $this->artisan('db:seed', ['--force' => true, ...$options])->run())->toThrow(RuntimeException::class, 'app:install');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::query()->count())->toBe(0);
})->with([
    'core' => [['--class' => CoreSeeder::class]],
    'support' => [['--class' => SupportSeeder::class]],
]);
