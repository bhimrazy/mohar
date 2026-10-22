<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\CheckRun;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Review-board probe for #161-#163: stage and check a 100,000-row file against 50,000 existing clients with every
 * duplicate option on, one job at a time, and report each job's time and peak memory (spec §13: jobs under 60 s and
 * 192 MB). Opt-in, because it takes minutes: IMPORT_PERF=1 php artisan test --filter=ReviewPerfProbe
 */
beforeEach(function () {
    if (! getenv('IMPORT_PERF')) {
        $this->markTestSkipped('Set IMPORT_PERF=1 to run the import performance probe.');
    }
});

test('probe: 100,000 rows stage and check within the job budgets against 50,000 clients', function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();

    $now = now();
    foreach (array_chunk(range(1, 50_000), 1000) as $ids) {
        DB::table('clients')->insert(array_map(fn (int $i): array => ['first_name' => 'Existing', 'last_name' => "Client {$i}",
            'company_name' => "Existing Company {$i} Pvt Ltd", 'email' => "client{$i}@example.com", 'tax_id' => "TAX{$i}", 'currency' => 'NPR',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now], $ids));
    }
    DB::statement(DB::connection()->getDriverName() === 'pgsql' ? 'analyze clients' : 'analyze');

    // Every tenth row matches an existing client by email; company words vary so the company lookup does real work.
    $rows = array_map(fn (int $i): array => ClientCsv::row((string) $i, $i % 10 === 0 ? 'client'.($i / 2).'@example.com' : "new{$i}@example.org",
        company: ['Acme', 'Globex', 'Initech', 'Umbrella', 'Hooli'][$i % 5]." {$i} Ltd"), range(1, 100_000));

    Queue::fake();
    $started = microtime(true);
    $run = ClientCsv::upload(ClientCsv::file($rows));
    $report = ['upload' => round(microtime(true) - $started, 2)];

    $measure = function (callable $job) use (&$report): void {
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $started = microtime(true);
        $job();
        $report['jobs'][] = [round(microtime(true) - $started, 2), round((memory_get_peak_usage() - $base) / 1048576)];
    };

    while ($run->refresh()->status !== MigrationRun::MAPPING) {
        $measure(fn () => (new StageCsvRun($run->id))->handle(app(ActivityLogger::class)));
    }
    $report['staging'] = $report['jobs'];
    $report['jobs'] = [];

    $run->forceFill(['status' => MigrationRun::CHECKING, 'generation' => 1, 'options' => ['on_match' => 'update', 'blank_cells' => 'keep',
        'closed_clients' => 'import', 'match_company' => true, 'match_tax_id' => true]])->save();
    $after = 0;
    do {
        $job = (new CheckRun($run->id, 1, $after))->withFakeQueueInteractions();
        $measure(fn () => $job->handle(app(ClientMatcher::class)));
        $after = $run->refresh()->checked_count;
    } while ($run->status === MigrationRun::CHECKING);

    $check = $report['jobs'];
    fwrite(STDERR, json_encode(['driver' => DB::connection()->getDriverName(), 'upload_s' => $report['upload'],
        'staging_jobs' => $report['staging'], 'check_jobs' => count($check),
        'check_total_s' => round(array_sum(array_column($check, 0)), 1), 'check_max_s' => max(array_column($check, 0)),
        'check_max_mb' => max(array_column($check, 1)), 'counts' => $run->only(['create_count', 'update_count', 'unchanged_count', 'decision_count', 'error_count'])])."\n");

    expect(max(array_column([...$report['staging'], ...$check], 0)))->toBeLessThan(60)
        ->and(max(array_column([...$report['staging'], ...$check], 1)))->toBeLessThan(192);
});
