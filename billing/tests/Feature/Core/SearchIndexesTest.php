<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Models\Service;
use App\Support\Search\GlobalSearch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Contains searches on the large tables must be servable by the trigram indexes (PostgreSQL): every OR branch
 * needs an index whose expression matches the query, or PostgreSQL scans the whole table. With sequential scans
 * disabled, a plan that still scans one of these tables means a searched column or expression has no index.
 */
function planScansTable(string $sql, array $bindings): ?string
{
    $plan = collect(DB::select('explain '.$sql, $bindings))->map(fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''))->implode("\n");

    return preg_match('/Seq Scan on (invoices|services|clients|support_tickets)\b/', $plan, $match) ? $match[1] : null;
}

test('admin search and list searches on invoices, services, clients and tickets use an index on PostgreSQL', function () {
    $user = actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/^select .* from "(invoices|services|clients|support_tickets)" where/i', $query->sql)) {
            $queries[] = [$query->sql, $query->bindings];
        }
    });

    foreach (['acme', '1042'] as $term) {
        app(GlobalSearch::class)->search($term, $user);

        foreach ([Invoice::query(), Service::query(), Client::query()] as $query) {
            /** @var Builder $query */
            $query->search($term)->limit(25)->get();
        }
    }

    DB::statement('set local enable_seqscan = off');
    $scans = collect($queries)->map(fn (array $query): ?string => planScansTable(...$query) === null ? null : $query[0])->filter()->unique()->values();

    expect($queries)->not->toBeEmpty()
        ->and($scans->all())->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Trigram indexes exist on PostgreSQL only');
