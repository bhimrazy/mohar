<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../Billing/helpers.php';

/** Ceilings for one admin dashboard visit, cold cache, every module and permission (measured: ~50 and ~24). */
const FIRST_PAINT_QUERIES = 56; // measured 47-51; an eager `health` prop (+13) must fail this
const DEFERRED_QUERIES = 28; // measured 20-24

function dashboardSample(int $rows): void
{
    $usd = Client::query()->where('currency', 'USD')->first() ?? Client::factory()->create(['currency' => 'USD']);
    $inr = Client::query()->where('currency', 'INR')->first() ?? Client::factory()->create(['currency' => 'INR', 'country' => 'IN']);
    $products = [Product::factory()->create(['type' => ProductType::SharedHosting]), Product::factory()->create(['type' => ProductType::Server])];

    for ($i = 0; $i < $rows; $i++) {
        $invoice = issueInvoice($i % 2 ? $usd : $inr, [10_000 + $i], dueDate: now()->toImmutable()->subDays($i % 20));

        if ($i % 3 === 0) {
            payInvoice($invoice, $invoice->total);
        }

        $order = Order::factory()->create(['created_at' => now()->subDays($i % 14)]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $products[$i % 2]->id]);
        Service::factory()->create(['recurring_amount' => 1_000 + $i]);
        AutomationRun::query()->create(['run_date' => today()->subDays($i % 30), 'task' => "sample.{$rows}.{$i}", 'status' => 'success', 'duration_ms' => 10]);
    }
}

/**
 * @return array{0: int, 1: TestResponse<Response>}
 */
function queriesFor(Closure $request): array
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $response];
}

test('a dashboard visit stays within its query budget, and the deferred request does not rebuild first-paint props', function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    Currency::factory()->inr()->create();
    actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    $counts = [];

    foreach ([10, 30] as $rows) {
        dashboardSample($rows);
        $this->flushHeaders();
        [$firstPaint, $response] = queriesFor(fn () => $this->get(route('admin.dashboard')));
        [$deferred] = queriesFor(fn () => $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) ($response->viewData('page')['version'] ?? ''),
            'X-Inertia-Partial-Component' => 'admin/dashboard',
            'X-Inertia-Partial-Data' => 'deferredWidgets,health',
        ])->get(route('admin.dashboard'))->assertOk()->assertJsonMissingPath('props.widgets')->assertJsonMissingPath('props.todos'));
        $counts[$rows] = [$firstPaint, $deferred];
    }

    expect($counts[30][0])->toBeLessThanOrEqual(FIRST_PAINT_QUERIES)
        ->and($counts[30][1])->toBeLessThanOrEqual(DEFERRED_QUERIES)
        // No per-row queries: tripling the data adds at most a handful (new distinct products, currencies).
        ->and($counts[30][0] - $counts[10][0])->toBeLessThanOrEqual(4)
        ->and($counts[30][1] - $counts[10][1])->toBeLessThanOrEqual(8);
});

test('dashboard figures are summed in the database: no query reads all rows of a growing table into PHP', function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    Currency::factory()->inr()->create();
    actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    dashboardSample(6);
    Cache::flush();
    DB::enableQueryLog();

    $response = $this->get(route('admin.dashboard'))->assertOk();
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) ($response->viewData('page')['version'] ?? ''),
        'X-Inertia-Partial-Component' => 'admin/dashboard',
        'X-Inertia-Partial-Data' => 'deferredWidgets,health',
    ])->get(route('admin.dashboard'))->assertOk();

    // Tables that grow with clients and history. A read is fine when it aggregates, is limited, or loads given ids.
    $unbounded = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => (bool) preg_match('/^\(?select .* from "?(invoices|invoice_items|transactions|services|clients|support_tickets)"?\b/is', $sql))
        ->reject(fn (string $sql): bool => (bool) preg_match('/\b(count|sum|min|max)\(|\bexists\s*\(| limit \d+|"id" in \(/i', $sql))
        ->values();
    DB::disableQueryLog();

    expect($unbounded->all())->toBe([]);
});

test('past the fresh minute the dashboard serves its cached figures and refreshes them after the response (#441)', function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    $client = Client::factory()->create(['currency' => 'USD']);
    actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    payInvoice(issueInvoice($client, [10_000]), 10_000);
    Cache::flush();
    $history = [];
    DB::listen(function ($query) use (&$history): void {
        // The full-history aggregates: income per day (Billing) and the MRR points (Services).
        if (preg_match('/sum\(amount_in\)|as point_0/', $query->sql)) {
            $history[] = $query->sql;
        }
    });
    $visit = function () use (&$history): array {
        $history = [];
        $widgets = collect($this->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['widgets'])->keyBy('key');

        return [$widgets['billing.income']['money']['amount'], count($history)];
    };

    expect($visit())->toBe([10_000, 2]); // cold: computed while the visitor waits

    payInvoice(issueInvoice($client, [5_000]), 5_000);
    $this->travel(30)->seconds();
    expect($visit())->toBe([10_000, 0]); // fresh

    $this->travel(31)->seconds();
    expect($visit())->toBe([10_000, 2]); // stale figures sent, then recomputed after the response

    expect($visit())->toBe([15_000, 0]);
});

test('cached dashboard widgets say when their figures were computed, in the company time zone (#441)', function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    actingAsStaff(['*', ...BillingServiceProvider::SENSITIVE_PERMISSIONS]);
    Cache::flush();

    $page = $this->get(route('admin.dashboard'))->assertOk()->viewData('page');
    $widgets = collect($page['props']['widgets'])->keyBy('key');

    // 12:00 UTC is 17:45 in Kathmandu.
    expect($widgets['billing.income']['asOf'])->toBe('2026-10-18T17:45:00+05:45')
        ->and($widgets['billing.unpaid']['asOf'])->toBe('2026-10-18T17:45:00+05:45')
        ->and($widgets['services.mrr']['asOf'])->toBe('2026-10-18T17:45:00+05:45');

    // 30 seconds later the cached figures keep the time they were computed.
    $this->travel(30)->seconds();
    expect(collect($this->get(route('admin.dashboard'))->viewData('page')['props']['widgets'])->keyBy('key')['billing.income']['asOf'])
        ->toBe('2026-10-18T17:45:00+05:45');
});
