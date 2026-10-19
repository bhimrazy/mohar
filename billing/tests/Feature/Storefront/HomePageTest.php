<?php

use App\Enums\BillingCycle;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\KbArticle;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
});

/**
 * A visible group with one plan priced in USD and NPR for the given cycle.
 */
function storefrontPlan(string $group = 'Shared Hosting', BillingCycle $cycle = BillingCycle::Monthly, int $amount = 1000, array $product = []): Product
{
    $model = Product::factory()->for(ProductGroup::factory()->create(['name' => $group, 'tagline' => "{$group} for everyone"]), 'group')->create($product);

    foreach (['USD' => $amount, 'NPR' => $amount * 100] as $currency => $value) {
        Price::factory()->create(['priceable_id' => $model->id, 'currency' => $currency, 'cycle' => $cycle, 'amount' => $value]);
    }

    return $model;
}

function publishContent(array $changes): void
{
    $content = array_replace_recursive(SiteContent::defaults(), $changes);
    Revision::query()->create(['status' => Revision::PUBLISHED, 'content' => $content, 'published_at' => now()]);
    app(StorefrontCache::class)->flush();
}

it('shows a neutral headline on a fresh install, never the product tagline', function (): void {
    app(Settings::class)->set('themes.tagline', 'Web hosting billing, sealed and settled.');
    app(Settings::class)->set('core.company_name', 'Acme Hosting');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/storefront/home')
            ->where('hero.headline', 'Hosting and domains from Acme Hosting')
            ->where('brand.hasUploadedLogo', false)
            ->where('brand.hasUploadedMark', false));
});

it('renders the title, description, canonical and Organization data in the first HTML response', function (): void {
    app(Settings::class)->set('core.company_name', 'Acme Hosting');
    app(Settings::class)->set('billing.seller.vat_number', '600000001');
    app(Settings::class)->set('billing.seller.address', 'Jhamsikhel Road, Lalitpur');
    publishContent(['hero' => ['headline' => 'Reliable hosting for your website'], 'seo' => ['description' => 'Hosting with clear prices.']]);

    $html = $this->get('/?currency=NPR&utm_source=mail')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Acme Hosting | Reliable hosting for your website</title>')
        ->toContain('<meta name="description" content="Hosting with clear prices."')
        ->toContain('<link rel="canonical" href="'.url('/').'"')
        ->toContain('"@type":"Organization"')
        ->toContain('"vatID":"600000001"')
        ->toContain('"address":"Jhamsikhel Road, Lalitpur"');
});

it('lists up to three product groups as header links and more in one menu, without hidden groups', function (): void {
    foreach (['Shared', 'VPS', 'SSL'] as $name) {
        storefrontPlan($name);
    }
    ProductGroup::factory()->hidden()->create(['name' => 'Legacy']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('site.products.mode', 'links')
        ->where('site.products.groups', fn ($groups) => collect($groups)->pluck('name')->all() === ['Shared', 'VPS', 'SSL']));

    storefrontPlan('Dedicated', amount: 9000);
    $this->withSession([CartManager::SESSION_CURRENCY => 'NPR']);

    $this->get('/store')->assertInertia(fn (Assert $page) => $page
        ->where('site.products.mode', 'menu')
        ->where('site.currency', 'NPR')
        ->where('site.products.groups.3', fn ($group) => $group['name'] === 'Dedicated'
            && $group['tagline'] === 'Dedicated for everyone'
            && $group['from'] === ['amount' => 900_000, 'cycle' => 'monthly', 'per_month' => false, 'setup' => 0]));
});

it('prices plan cards per month with the billed total and renewal for the selected cycle', function (): void {
    app(Settings::class)->set('catalog.show_monthly_breakdown', true);
    storefrontPlan(cycle: BillingCycle::Biennially, amount: 7699, product: ['is_featured' => true, 'description' => '10 websites, 50 GB SSD']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('featured_plans.0.default_cycle', 'biennially')
        ->where('featured_plans.0.plans.0.pricing.0', ['cycle' => 'biennially', 'recurring' => 7699, 'setup' => 0, 'monthly' => 321])
        ->where('featured_plans.0.plans.0.features', ['10 websites', '50 GB SSD'])
        ->where('hero.plan.plan.featured', true));
});

it('states tax per currency for its own market and shows tax-inclusive prices only where the host turned it on', function (): void {
    app(Settings::class)->set('billing.tax.enabled', true);
    TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
    TaxRule::factory()->create(['name' => 'GST', 'country' => 'IN', 'rate' => '18.000']);
    Currency::factory()->inr()->create();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('site.tax_note', 'Tax added at checkout where applicable'));
    $this->withSession([CartManager::SESSION_CURRENCY => 'INR'])->get('/')->assertInertia(fn (Assert $page) => $page->where('site.tax_note', '+18% GST at checkout'));
    storefrontPlan(amount: 1000);
    $this->withSession([CartManager::SESSION_CURRENCY => 'NPR']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('tax_note', '+13% VAT at checkout')
        ->where('site.tax_note', '+13% VAT at checkout')
        ->where('featured_plans.0.plans.0.pricing.0.recurring', 100_000));

    publishContent(['prices' => ['tax_inclusive' => ['NPR']]]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('tax_note', 'Prices include 13% VAT')
        ->where('featured_plans.0.plans.0.pricing.0.recurring', 113_000));
});

it('shows eSewa and Khalti only to visitors paying in NPR, with their official logos', function (): void {
    Gateway::factory()->create();
    Gateway::factory()->create(['key' => 'khalti', 'display_name' => 'Khalti', 'config' => ['secret_key' => 'test']]);
    Gateway::factory()->create(['key' => 'esewa', 'display_name' => 'eSewa', 'config' => ['product_code' => 'EPAYTEST', 'secret_key' => 'test']]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('site.payments', fn ($marks) => collect($marks)->pluck('key')->all() === ['bank_transfer']));

    $this->withSession([CartManager::SESSION_CURRENCY => 'NPR'])->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('site.payments', fn ($marks) => collect($marks)->pluck('key')->sort()->values()->all() === ['bank_transfer', 'esewa', 'khalti']
            && collect($marks)->firstWhere('key', 'khalti')['logo'] !== null
            && collect($marks)->firstWhere('key', 'bank_transfer')['logo'] === null));
});

it('keeps incident details off the home page for guests while network status requires login', function (): void {
    Announcement::factory()->create(['is_published' => true, 'published_at' => now()->subDay()]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('news.status', null)
        ->has('news.announcements', 1)
        ->where('site.support', fn ($links) => collect($links)->firstWhere('label', 'Network status')['badge'] === null));
});

it('fills the footer from Settings → Company and shows the trust facts and call to action band', function (): void {
    foreach (['core.company_email' => 'help@acme.test', 'core.support_hours' => 'Sunday–Friday, 9:00–18:00', 'core.whatsapp' => '+977 9800000000',
        'core.registration_number' => '123456/78', 'core.social_facebook' => 'https://facebook.com/acme', 'billing.seller.vat_number' => '600000001'] as $key => $value) {
        app(Settings::class)->set($key, $value);
    }
    storefrontPlan(amount: 1000);
    publishContent(['trust_facts' => [['label' => 'Hosting since', 'value' => '2014']]]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('site.company.hours', 'Sunday–Friday, 9:00–18:00')
        ->where('site.company.whatsapp', '+977 9800000000')
        ->where('site.company.social', [['label' => 'Facebook', 'url' => 'https://facebook.com/acme']])
        ->where('site.company.ids', fn ($ids) => collect($ids)->pluck('kind')->all() === ['vat', 'registration'])
        ->where('site.support', fn ($links) => collect($links)->firstWhere('label', 'Contact us')['url'] === 'mailto:help@acme.test')
        ->where('sections', fn ($sections) => $sections[0] === 'trust_facts' && in_array('cta_band', $sections->all(), true))
        ->where('cta_band.from.amount', 1000));
});

it('answers unknown public URLs with the public 404 page and noindex', function (): void {
    $response = $this->get('/no-such-page')->assertNotFound();

    $response->assertInertia(fn (Assert $page) => $page->component('public/storefront/not-found')->where('path', '/no-such-page')->has('site'));
    expect($response->getContent())->toContain('<meta name="robots" content="noindex, nofollow"');

    $this->get(route('store.cart.domains.store'))->assertMethodNotAllowed();

    actingAsStaff();
    $this->get('/admin/no-such-page')->assertNotFound()->assertDontSee('public/storefront/not-found', false);
});

it('lists public pages in the sitemap and leaves out private articles', function (): void {
    $public = KbArticle::factory()->create(['is_published' => true, 'is_private' => false]);
    $private = KbArticle::factory()->create(['is_published' => true, 'is_private' => true]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.url('/').'</loc>', false)
        ->assertSee(route('store.index'), false)
        ->assertSee(route('support.kb.article', $public->slug), false)
        ->assertDontSee(route('support.kb.article', $private->slug), false);
});
