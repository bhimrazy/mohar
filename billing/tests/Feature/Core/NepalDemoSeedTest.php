<?php

use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Catalog\Database\Seeders\CatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Database\Seeders\DomainsSeeder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Services\Database\Seeders\ServicesSeeder;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;

beforeEach(function () {
    $this->seed(CoreSeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(DomainsSeeder::class);
});

it('prices the catalogue and the domains in NPR from the US dollar base', function () {
    $business = Product::query()->where('slug', 'business')->firstOrFail()->prices()->where('currency', 'NPR')->where('cycle', 'monthly')->value('amount');
    $comRenewal = TldPrice::query()->where('currency', 'NPR')->where('years', 1)->whereHas('tld', fn ($query) => $query->where('extension', '.com'))->value('renew');

    // $14.99 a month at 151.94 NPR per dollar, rounded to a price point (not Rs 14.99).
    expect($business)->toBe(227_900)->and($comRenewal)->toBe(227_900);
});

it('gives the showcase client a client-registered .np domain with no registrar and no fee, and Nepali names', function () {
    $demo = Client::query()->where('email', 'demo@example.com')->sole();
    $np = Domain::query()->where('client_id', $demo->id)->where('name', 'himallabs.com.np')->sole();

    expect($np->registrar_key)->toBeNull()
        ->and([$np->recurring_amount, $np->first_payment_amount, $np->auto_renew])->toBe([0, 0, false])
        ->and(Domain::query()->pluck('name')->filter(fn (string $name) => preg_match('/northwind|globex|initech|umbrella|acme|stark|demo/', $name) === 1)->all())->toBe([]);
});

it('bills each showcase line at its own price: the service for hosting, the TLD renewal for the domain', function () {
    $this->seed(ServicesSeeder::class);
    $this->seed(BillingSeeder::class);
    $demo = Client::query()->where('email', 'demo@example.com')->sole();
    $lines = Invoice::query()->where('client_id', $demo->id)->whereNotNull('number')->with('items')->get()->flatMap(fn (Invoice $invoice) => $invoice->items);
    $line = fn (string $prefix) => $lines->first(fn ($item) => str_starts_with($item->description, $prefix))->unit_amount;
    $service = fn (string $name) => Service::query()->where('client_id', $demo->id)->where('product_name', $name)->value('recurring_amount');
    $renewal = TldPrice::query()->where('currency', 'NPR')->where('years', 1)->whereHas('tld', fn ($query) => $query->where('extension', '.com'))->value('renew');

    expect($line('Business Hosting'))->toBe($service('Business Hosting'))->toBe(197_370)
        ->and($line('VPS Small'))->toBe(288_686)
        ->and($line('Daily backups'))->toBe(ServiceAddon::query()->where('currency', 'NPR')->value('recurring_amount'))
        ->and($line('Domain renewal — himallabs.com'))->toBe($renewal)->toBe(227_900)
        ->and($line('Business Hosting'))->not->toBe($line('Domain renewal'))
        ->and($lines->pluck('description')->filter(fn (string $text) => str_contains($text, 'Domain') && str_contains($text, '.np'))->all())->toBe([])
        ->and($lines->pluck('description')->filter(fn (string $text) => str_contains($text, 'himal-labs'))->all())->toBe([]);
});
