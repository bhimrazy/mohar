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
 * needs an index whose expression matches the query, or PostgreSQL reads the whole table. With sequential and plain
 * index scans disabled (a newest-first search would otherwise walk the primary key and filter every row), the only
 * plan that avoids a full read is a bitmap scan over the searched columns' trigram indexes, so each one must appear.
 */
const SEARCH_TRIGRAM_INDEXES = [
    'invoices' => ['invoices_number_trgm_index', 'invoices_proforma_number_trgm_index'],
    'services' => ['services_domain_trgm_index', 'services_username_trgm_index', 'services_product_name_trgm_index', 'services_dedicated_ip_trgm_index'],
    'clients' => ['clients_first_name_trgm_index', 'clients_last_name_trgm_index', 'clients_company_name_trgm_index', 'clients_email_trgm_index'],
    'support_tickets' => ['support_tickets_number_trgm_index', 'support_tickets_subject_trgm_index'],
];

/**
 * @return list<array{0: string, 1: array<int, mixed>}> the admin searches' queries on the tables above
 */
function searchQueries(): array
{
    $user = actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/^select .* from "(invoices|services|clients|support_tickets)" where .*like/i', $query->sql)) {
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

    return $queries;
}

/**
 * The queries whose plan reads a table without its trigram indexes, as "table: sql".
 *
 * @param  list<array{0: string, 1: array<int, mixed>}>  $queries
 * @return list<string>
 */
function fullReads(array $queries): array
{
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_indexscan = off');

    return array_values(array_filter(array_map(function (array $query): ?string {
        preg_match('/ from "(\w+)"/', $query[0], $table);
        $plan = collect(DB::select('explain '.$query[0], $query[1]))->map(fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''))->implode("\n");
        $missing = array_filter(SEARCH_TRIGRAM_INDEXES[$table[1]], fn (string $index): bool => ! str_contains($plan, "Bitmap Index Scan on {$index}"));

        return $missing === [] && ! str_contains($plan, 'Seq Scan') ? null : $table[1].': '.$query[0];
    }, $queries)));
}

test('admin search and list searches on invoices, services, clients and tickets use their trigram indexes on PostgreSQL', function () {
    $queries = searchQueries();

    expect(collect($queries)->map(fn (array $query): string => preg_replace('/^.* from "(\w+)".*$/s', '$1', $query[0]))->unique()->sort()->values()->all())
        ->toBe(['clients', 'invoices', 'services', 'support_tickets'])
        ->and(fullReads($queries))->toBe([]);

    // The check notices a missing index: without the ticket indexes, the newest-first ticket search reads the table.
    DB::statement('drop index support_tickets_number_trgm_index');
    DB::statement('drop index support_tickets_subject_trgm_index');

    expect(fullReads($queries))->not->toBeEmpty()
        ->each->toStartWith('support_tickets: ');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Trigram indexes exist on PostgreSQL only');
