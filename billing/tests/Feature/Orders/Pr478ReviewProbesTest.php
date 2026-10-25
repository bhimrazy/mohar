<?php

use App\Enums\BillingCycle;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Billing/helpers.php';

// Review probe (#478): a group saved before this PR with a now-reserved slug must not lose its products.
test('probe: products of a group whose slug is now reserved stay reachable', function () {
    Currency::factory()->create();
    $group = ProductGroup::factory()->create(['name' => 'Domains bundle', 'slug' => 'domains']);
    $product = Product::factory()->for($group, 'group')->withPrice('USD', BillingCycle::Monthly, 1000)->create(['slug' => 'starter']);

    // Changelog: "keeps its products reachable through the numeric link". Today: 301 to /store/domains/starter, which is 404.
    $this->followingRedirects()->get("/store/products/{$product->id}")->assertOk();
});

// Review probe (#478): the dashboard's Renew now dialog states the amount the invoice is created for.
test('probe: dashboard Renew now amount equals the invoice total for a grace domain', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'Asia/Kathmandu'));
    Currency::factory()->npr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']));
    $tld = Tld::factory()->extension('.com')->priced('NPR', renew: 150_000)->create(['grace_fee' => 2_000]);
    $domain = Domain::factory()->named('lapsed', $tld)->expiring('2026-10-15')
        ->create(['client_id' => $client->id, 'currency' => 'NPR', 'recurring_amount' => 150_000, 'status' => DomainStatus::Grace]);

    $props = $this->get(route('client.dashboard'))->viewData('page')['props'];
    $row = collect(collect($props['panels'])->firstWhere('key', 'domains.list')['items'])->firstWhere('title', 'lapsed.com');

    $this->post($row['action']['href'], $row['action']['confirm']['data'])->assertSessionHasNoErrors();
    $this->post($row['action']['href'], $row['action']['confirm']['data']); // double click
    $invoices = Invoice::query()->where('client_id', $client->id)->get();

    expect($invoices)->toHaveCount(1)
        ->and($row['action']['confirm']['description'])->toContain(app(MoneyFormatter::class)->format(Money::of($invoices->first()->total, 'NPR')));
});

// Review probe (#478): the domain search page and the lookup it calls price in the same currency.
test('probe: store domain search and its lookup agree on the currency for a guest', function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    Tld::factory()->extension('.com')->priced('USD', register: 1200)->create();
    $this->put('/store/currency', ['currency' => 'NPR'])->assertRedirect();

    $page = $this->get('/store/domains?domain=asha.com')->viewData('page')['props'];
    $lookup = $this->getJson($page['lookup_url'].'?'.http_build_query(['q' => 'asha.com']))->json();

    expect($lookup['currency'])->toBe($page['currency']);
});
