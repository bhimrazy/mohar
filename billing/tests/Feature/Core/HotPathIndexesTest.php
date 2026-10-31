<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Actions\CompleteLimitedServices;
use App\Modules\Services\Actions\GenerateRenewals;
use App\Modules\Services\Actions\ProcessCancellations;
use App\Modules\Services\Actions\SuspendOverdueServices;
use App\Modules\Services\Actions\TerminateOverdueServices;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The billing-day, dashboard and queue statements must stay servable by the indexes built for them (database review
 * 2026-10-31, H3, H4, M1, M11). A partial index serves a query only while the query's WHERE implies the index's
 * predicate, so a new status in a task's filter would silently drop it back to a primary-key walk. Plans depend on
 * statistics, so each statement is checked against its index alone: on an empty temporary copy of the table (which
 * shadows the real one in this session) holding only that index, with sequential scans and sorts disabled, the plan
 * names the index if and only if it can serve the statement (and, for id and newest-first chunks, its order).
 *
 * @return list<array{0: string, 1: array<int, mixed>}> statements on $table matching $pattern while $run runs
 */
function capturedStatements(string $table, string $pattern, Closure $run): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use ($table, $pattern, &$statements): void {
        if (str_contains($query->sql, "from \"{$table}\"") && preg_match($pattern, $query->sql) === 1) {
            $statements[] = [$query->sql, $query->bindings];
        }
    });
    $run();

    return $statements;
}

/**
 * The statements that the planner cannot serve from $index alone, or that still sort when $ordered.
 *
 * @param  list<array{0: string, 1: array<int, mixed>}>  $statements
 * @return list<string>
 */
function unservedBy(string $table, string $index, array $statements, bool $ordered): array
{
    $definition = (string) DB::selectOne('select pg_get_indexdef(?::regclass) as d', [$index])->d;
    DB::statement("create temporary table {$table} (like public.{$table})");
    DB::statement(str_replace(" ON public.{$table} ", " ON pg_temp.{$table} ", $definition));
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_sort = off');

    $unserved = array_values(array_filter(array_map(function (array $statement) use ($index, $ordered): ?string {
        $plan = collect(DB::select('explain '.$statement[0], $statement[1]))->map(fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''))->implode("\n");
        $uses = preg_match('/(Index Only Scan|Index Scan|Index Scan Backward) using '.$index.' |Bitmap Index Scan on '.$index.'\b/', $plan) === 1;

        return $uses && ! ($ordered && preg_match('/^\s*(->\s*)?Sort\b/m', $plan) === 1) ? null : $statement[0];
    }, $statements)));

    DB::statement("drop table pg_temp.{$table}");

    return $unserved;
}

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-31');
    app(Settings::class)->setMany([
        'billing.late_fee.amount' => 10, 'billing.auto_cancel.days_overdue' => 30, 'services.terminate.enabled' => true,
    ]);
});

test('the receivables widget reads open invoices from the covering index and cancelled ones by cancelled_at', function () {
    $statements = capturedStatements('invoices', '/union all.*"status" = \? and "cancelled_at" >= \?/s', fn () => app(Dashboard::class)
        ->resolveWidgets('admin', actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS])));

    expect($statements)->not->toBeEmpty()
        ->and(unservedBy('invoices', 'invoices_open_receivables_index', $statements, ordered: false))->toBe([])
        ->and(unservedBy('invoices', 'invoices_cancelled_at_cancelled_index', $statements, ordered: false))->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Plans are checked on PostgreSQL only');

test('the billing-day invoice tasks walk open invoices by id', function () {
    $automation = app(BillingAutomation::class);
    $statements = capturedStatements('invoices', '/order by "id" asc limit/', function () use ($automation): void {
        $automation->lateFees($this->today);
        $automation->autoCapture($this->today);
        $automation->reminders($this->today);
        $automation->autoCancel($this->today);
    });

    // Late fees, capture, both reminder passes and auto-cancel.
    expect($statements)->toHaveCount(5)
        ->and(unservedBy('invoices', 'invoices_open_id_index', $statements, ordered: true))->toBe([]);

    // The check notices a predicate the index does not cover.
    expect(unservedBy('invoices', 'invoices_open_id_index', [['select * from "invoices" where "status" in (?, ?) order by "id" asc limit 200', ['unpaid', 'draft']]], ordered: true))
        ->toHaveCount(1);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Plans are checked on PostgreSQL only');

test('the service tasks walk live services by id', function () {
    $statements = capturedStatements('services', '/^select \* from "services" .*order by "id" asc limit/', function (): void {
        foreach ([GenerateRenewals::class, SuspendOverdueServices::class, TerminateOverdueServices::class, CompleteLimitedServices::class, ProcessCancellations::class] as $task) {
            app($task)->handle($this->today);
        }
    });

    expect($statements)->toHaveCount(5)
        ->and(unservedBy('services', 'services_live_id_index', $statements, ordered: true))->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Plans are checked on PostgreSQL only');

test('the client invoice list and dashboard read the client\'s invoices newest first from the index', function () {
    [, $client] = actingAsClient();
    Invoice::factory()->for($client)->create(); // paginate() skips the page query when the count is 0
    $statements = capturedStatements('invoices', '/order by "issue_date" desc, "id" desc/', function (): void {
        $this->get(route('client.invoices.index'))->assertOk();
        $this->get(route('client.dashboard'))->assertOk();
    });

    expect($statements)->toHaveCount(2)
        ->and(unservedBy('invoices', 'invoices_client_id_issue_date_id_index', $statements, ordered: true))->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Plans are checked on PostgreSQL only');

test('a database queue pop takes its queue in id order from the index', function () {
    $statements = capturedStatements('jobs', '/for update skip locked/i', fn () => Queue::connection('database')->pop('payments'));

    expect($statements)->toHaveCount(1)
        ->and(unservedBy('jobs', 'jobs_queue_id_index', $statements, ordered: true))->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Plans are checked on PostgreSQL only');
