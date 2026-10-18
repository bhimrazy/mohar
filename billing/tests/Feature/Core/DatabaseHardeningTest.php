<?php

use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\OnlineIndex;
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

test('online indexes are created once and dropped by their default name', function () {
    OnlineIndex::create('automation_runs', ['status', 'id']);
    OnlineIndex::create('automation_runs', ['status', 'id']);

    expect(Schema::hasIndex('automation_runs', 'automation_runs_status_id_index'))->toBeTrue();

    OnlineIndex::drop('automation_runs', ['status', 'id']);

    expect(Schema::hasIndex('automation_runs', 'automation_runs_status_id_index'))->toBeFalse();
});

test('the database seeder creates no demo accounts in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::query()->count())->toBe(0);
});
