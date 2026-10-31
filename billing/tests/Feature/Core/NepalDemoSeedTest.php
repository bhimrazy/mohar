<?php

use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Catalog\Database\Seeders\CatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Database\Seeders\DomainsSeeder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Services\Database\Seeders\ServicesSeeder;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Illuminate\Support\Facades\Storage;

// The demo seed takes tens of seconds: seed it once per worker. The object disk is faked before the seed runs, and the
// files the seed wrote are kept for the PDF test, so it observes the seed itself rather than a disk faked afterwards.
beforeEach(function () {
    $this->useSeededDatabase('nepal_demo', function () {
        Storage::fake('objects');
        $this->seed(CoreSeeder::class);
        $this->seed(CatalogSeeder::class);
        $this->seed(DomainsSeeder::class);
        $this->seed(ServicesSeeder::class);
        $this->seed(BillingSeeder::class);
        nepalDemoSeedPdfs(Storage::disk('objects')->allFiles('billing'));
    });
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
    $demo = Client::query()->where('email', 'demo@example.com')->sole();
    $lines = Invoice::query()->where('client_id', $demo->id)->whereNotNull('number')->with('items')->get()->flatMap(fn (Invoice $invoice) => $invoice->items);
    $line = fn (string $prefix) => $lines->first(fn ($item) => str_starts_with($item->description, $prefix))->unit_amount;
    $service = fn (string $name) => Service::query()->where('client_id', $demo->id)->where('product_name', $name)->value('recurring_amount');
    $renewal = TldPrice::query()->where('currency', 'NPR')->where('years', 1)->whereHas('tld', fn ($query) => $query->where('extension', '.com'))->value('renew');

    expect($line('Business — himallabs.io'))->toBe($service('Business'))->toBe(197_370)
        ->and($line('VPS S — vps.himallabs.io'))->toBe($service('VPS S'))->toBe(288_686)
        ->and($line('Daily backups'))->toBe(ServiceAddon::query()->where('currency', 'NPR')->value('recurring_amount'))
        ->and($line('Domain renewal — himallabs.com'))->toBe($renewal)->toBe(227_900)
        ->and($line('Business — himallabs.io'))->not->toBe($line('Domain renewal'))
        ->and($lines->pluck('description')->filter(fn (string $text) => str_contains($text, 'Domain') && str_contains($text, '.np'))->all())->toBe([])
        ->and($lines->pluck('description')->filter(fn (string $text) => str_contains($text, 'himal-labs'))->all())->toBe([]);
});

it('renders no PDF while seeding and leaves the attachment setting as it was', function () {
    expect(Invoice::query()->whereNotNull('number')->exists())->toBeTrue()
        ->and(app(Settings::class)->get('billing.pdf.attach_to_emails'))->toBeTrue()
        ->and(nepalDemoSeedPdfs())->toBe([]);
});

/**
 * The billing files on the faked object disk when the seed finished in this process (null before it ran).
 *
 * @param  list<string>|null  $files
 * @return list<string>|null
 */
function nepalDemoSeedPdfs(?array $files = null): ?array
{
    static $seeded = null;

    return $seeded = $files ?? $seeded;
}
