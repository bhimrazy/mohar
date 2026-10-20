<?php

/*
 * Review-board probes for PR #94 (storefront public side). Each test names the finding it covers; the engineer
 * adopts the ones that fail today as regression tests once fixed.
 */

use App\Enums\BillingCycle;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Models\Tld;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\KbArticle;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Currency::factory()->create();
});

function rbProbePlan(BillingCycle $cycle, int $amount): Product
{
    $product = Product::factory()->for(ProductGroup::factory()->create(['name' => 'Shared']), 'group')->create();
    Price::factory()->create(['priceable_id' => $product->id, 'currency' => 'USD', 'cycle' => $cycle, 'amount' => $amount]);

    return $product;
}

function rbProbeQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('[JSON-LD] cannot be broken out of by the company or brand name', function (): void {
    app(Settings::class)->set('core.company_name', 'Acme</script><script>alert(1)</script>');
    app(Settings::class)->set('billing.seller.legal_name', 'Acme & Co <!-- x');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('Acme & Co <!--')
        ->toContain('"@type":"Organization"');
});

it('[N+1] /domains/pricing runs the same number of queries for 3 or 30 TLDs', function (): void {
    Tld::factory()->count(3)->priced(years: 1)->create();
    app(StorefrontCache::class)->flush();
    $this->get('/domains/pricing')->assertOk();
    app()->forgetScopedInstances(); // a new HTTP request gets a fresh DomainPricingService (scoped)
    $few = rbProbeQueries(fn () => $this->get('/domains/pricing')->assertOk());

    Tld::factory()->count(27)->priced(years: 1)->create();
    app(StorefrontCache::class)->flush();
    $this->get('/domains/pricing')->assertOk();
    app()->forgetScopedInstances();
    $many = rbProbeQueries(fn () => $this->get('/domains/pricing')->assertOk());

    expect($many - $few)->toBeLessThan(3);
});

it('[spec 5.2] shows per-month prices only when catalog.show_monthly_breakdown is on', function (): void {
    rbProbePlan(BillingCycle::Annually, 9900);

    app(Settings::class)->set('catalog.show_monthly_breakdown', false);
    app(StorefrontCache::class)->flush();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('site.products.groups.0.from.per_month', false));

    app(Settings::class)->set('catalog.show_monthly_breakdown', true);
    app(StorefrontCache::class)->flush();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('site.products.groups.0.from.per_month', true));
});

it('[sitemap] leaves out unpublished articles, scheduled announcements and a noindex home page', function (): void {
    $draft = KbArticle::factory()->create(['is_published' => false, 'is_private' => false]);
    $scheduled = Announcement::factory()->create(['is_published' => true, 'published_at' => now()->addDay()]);
    Revision::query()->create(['status' => Revision::PUBLISHED, 'content' => array_replace_recursive(SiteContent::defaults(), ['seo' => ['noindex' => true]]), 'published_at' => now()]);
    app(StorefrontCache::class)->flush();

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertDontSee(route('support.kb.article', $draft->slug), false)
        ->assertDontSee(route('support.announcements.show', $scheduled->slug), false)
        ->assertDontSee('<loc>'.url('/').'</loc>', false)
        ->assertDontSee('/admin', false)
        ->assertDontSee('/store/cart', false);
});

it('[sitemap] never caches URLs built from a forged Host header', function (): void {
    app(StorefrontCache::class)->flush();

    $this->get('http://evil.example/sitemap.xml')->assertOk();

    $this->get('/sitemap.xml')->assertOk()->assertDontSee('evil.example', false);
});

it('[404] a 404 thrown inside a public route renders the public page; a PUT-only URL keeps 405', function (): void {
    $this->get('/store/products/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('public/storefront/not-found')->has('site'));

    $this->get('/store/currency')->assertMethodNotAllowed();
    $this->get('/store/cart/items/1')->assertMethodNotAllowed();
    $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonMissingPath('component');
});

it('[tax] Billing in inclusive mode: amounts unchanged and "Prices include" note', function (): void {
    app(Settings::class)->set('billing.tax.enabled', true);
    app(Settings::class)->set('billing.tax.mode', 'inclusive');
    config(['storefront.tax_markets.USD' => 'NP']); // these probes price in USD for the Nepal market
    TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
    rbProbePlan(BillingCycle::Monthly, 1130);
    app(StorefrontCache::class)->flush();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('tax_note', 'Prices include 13% VAT')
        ->where('featured_plans.0.plans.0.pricing.0.recurring', 1130));
});

it('[tax] tax-inclusive display leaves untaxed products and untaxed domains at their checkout price', function (): void {
    app(Settings::class)->set('billing.tax.enabled', true);
    config(['storefront.tax_markets.USD' => 'NP']); // these probes price in USD for the Nepal market
    app(Settings::class)->set('orders.domains_taxable', false);
    TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
    $product = rbProbePlan(BillingCycle::Monthly, 1000);
    $product->update(['is_taxable' => false]);
    Tld::factory()->extension('.com')->priced(register: 1200, years: 1)->create(['is_spotlight' => true]);
    Revision::query()->create(['status' => Revision::PUBLISHED, 'content' => array_replace_recursive(SiteContent::defaults(), ['prices' => ['tax_inclusive' => ['USD']]]), 'published_at' => now()]);
    app(StorefrontCache::class)->flush();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('featured_plans.0.plans.0.pricing.0.recurring', 1000)
        ->where('domains.featured.0.register', 1200));
});
