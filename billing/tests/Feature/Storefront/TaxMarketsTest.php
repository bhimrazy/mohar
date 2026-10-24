<?php

use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Services\PriceDisplay;
use App\Modules\Storefront\Support\SiteContent;
use App\Modules\Storefront\Support\StorefrontCache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    app(Settings::class)->set('billing.tax.enabled', true);
    TaxRule::factory()->create(['name' => 'VAT', 'country' => 'NP', 'rate' => '13.000']);
    TaxRule::factory()->create(['name' => 'IGST', 'country' => 'IN', 'rate' => '18.000']);
});

function marketsForm(array $markets, array $inclusive = []): array
{
    return array_replace_recursive(SiteContent::defaults(), [
        'hero' => ['headline' => 'Reliable hosting for your website'],
        'prices' => ['markets' => $markets, 'tax_inclusive' => $inclusive],
    ]);
}

function publishMarkets(array $markets): void
{
    Revision::query()->create(['status' => Revision::PUBLISHED, 'content' => SiteContent::normalise(marketsForm($markets)), 'published_at' => now()]);
    app(StorefrontCache::class)->flush();
}

it('uses the config default market until the host chooses one', function (): void {
    expect(PriceDisplay::market('NPR', SiteContent::defaults()))->toBe('NP')
        ->and(PriceDisplay::market('usd', SiteContent::defaults()))->toBeNull()
        ->and(PriceDisplay::market('NPR', marketsForm(['NPR' => 'IN'])))->toBe('IN')
        ->and(PriceDisplay::market('NPR', marketsForm(['NPR' => ''])))->toBeNull();
});

it('prices a currency for the market the host set, and a blank market shows the neutral note', function (): void {
    publishMarkets(['USD' => 'NP']);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('site.tax_note', '+13% VAT at checkout'));

    publishMarkets(['NPR' => 'IN']);
    $this->withSession([CartManager::SESSION_CURRENCY => 'NPR'])->get('/')->assertInertia(fn (Assert $page) => $page->where('site.tax_note', '+18% GST at checkout'));

    publishMarkets(['NPR' => '']);
    $this->withSession([CartManager::SESSION_CURRENCY => 'NPR'])->get('/')->assertInertia(fn (Assert $page) => $page->where('site.tax_note', PriceDisplay::NEUTRAL_NOTE));
});

it('saves markets in the draft, shows them in the editor and lists the change', function (): void {
    actingAsStaff(['storefront.content.manage']);

    $this->put(route('admin.storefront.website.update'), marketsForm(['USD' => 'NP', 'NPR' => 'IN', 'XXX' => 'NP']))->assertSessionHasNoErrors();

    $this->get(route('admin.storefront.website.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('content.prices.markets', ['USD' => 'NP', 'NPR' => 'IN'])
        ->where('options.currencies', fn ($currencies) => collect($currencies)->pluck('market', 'code')->all() === ['USD' => 'NP', 'NPR' => 'IN']
            && collect($currencies)->pluck('default_market', 'code')->all() === ['USD' => null, 'NPR' => 'NP'])
        ->where('draft.changes', ['Headline changed', 'Price and tax display changed']));
});

it('rejects a market that is not a country', function (): void {
    actingAsStaff(['storefront.content.manage']);

    $this->put(route('admin.storefront.website.update'), marketsForm(['NPR' => 'Nepal']))->assertSessionHasErrors('prices.markets.NPR');
});
