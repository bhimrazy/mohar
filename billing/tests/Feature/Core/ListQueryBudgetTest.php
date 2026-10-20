<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * The lists the load test visits must not query per row: a page of 12 rows costs what a page of 2 costs, within a
 * ceiling (measured on 2026-10-20 at 5–14 queries per page, including session, layout and navigation badges).
 */
const LIST_QUERY_CEILING = 24;

function listQueries(string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('admin lists cost the same number of queries for 2 rows as for 12', function (string $route, Closure $rows) {
    Currency::factory()->create();
    actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    $rows(2);
    listQueries(route($route)); // warm: settings, navigation badges and the session are read once
    $few = listQueries(route($route));
    $rows(10);
    $many = listQueries(route($route));

    expect($many)->toBe($few)->toBeLessThanOrEqual(LIST_QUERY_CEILING);
})->with([
    'invoices' => ['admin.billing.invoices.index', fn (int $n) => Invoice::factory()->count($n)->create()],
    'clients' => ['admin.clients.index', fn (int $n) => Client::factory()->count($n)->create()],
    'services' => ['admin.services.index', fn (int $n) => Service::factory()->count($n)->create()],
    'tickets' => ['admin.support.tickets.index', fn (int $n) => Ticket::factory()->count($n)->create(['department_id' => Department::query()->value('id') ?? Department::factory()])],
]);

test('client invoice and service lists cost the same number of queries for 2 rows as for 12', function (string $route, string $model) {
    [, $client] = actingAsClient();
    $rows = fn (int $n) => $model::factory()->count($n)->create(['client_id' => $client->id]);
    $rows(2);
    listQueries(route($route));
    $few = listQueries(route($route));
    $rows(10);
    $many = listQueries(route($route));

    expect($many)->toBe($few)->toBeLessThanOrEqual(LIST_QUERY_CEILING);
})->with([
    'invoices' => ['client.invoices.index', Invoice::class],
    'services' => ['client.services.index', Service::class],
]);
