<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Branding\BrandProvider;
use App\Contracts\Integrations\ProvidesLogo;
use App\Contracts\Orders\ShopperSession;
use App\Contracts\Support\PublicHelpDirectory;
use App\Modules\Core\Support\Settings;
use App\Modules\Storefront\Support\StorefrontCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The shared public layout's data (spec §4): header groups with "from" prices, domain and support menus, the
 * company block, payment marks, the tax statement and legal links. The visitor-independent part is cached per
 * currency; the cart badge, currency and account links are read per request.
 */
class SiteData
{
    /** Header shows a link per group up to this many groups, then one menu (owner brief, 2026-10-19). */
    public const MAX_GROUP_LINKS = 4;

    public function __construct(
        private ContentStore $store,
        private Catalogue $catalogue,
        private PriceDisplay $prices,
        private StorefrontCache $cache,
        private ShopperSession $shopper,
        private PublicHelpDirectory $help,
        private Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>|null  $draft  content to render instead of the published version (staff preview)
     * @return array<string, mixed>
     */
    public function forRequest(Request $request, ?array $draft = null): array
    {
        $currency = $this->shopper->currency($request);
        $signedIn = $request->user() !== null;
        $shared = $draft !== null
            ? $this->build($currency, $draft, $signedIn)
            : $this->cache->remember("site.{$currency}.".(int) $signedIn, fn (): array => $this->build($currency, $this->store->published(), $signedIn));

        return [
            ...$shared,
            'currency' => $currency,
            'currencies' => $this->shopper->switchableCurrencies($request),
            'currency_url' => $this->url('store.currency'),
            'cart' => Route::has('store.cart') ? ['count' => $this->shopper->cartCount($request), 'url' => route('store.cart')] : null,
            'account' => [
                'signed_in' => $signedIn,
                'client_area_url' => $this->url('dashboard'),
                'login_url' => $this->url('login'),
                'register_url' => $this->url('register'),
                'reset_url' => $this->url('password.request'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function build(string $currency, array $content, bool $signedIn): array
    {
        $display = $this->prices->forCurrency($currency, $content);
        $groups = array_map(fn (array $group): array => array_diff_key($group, ['plans' => true]), $this->catalogue->groups($currency, $display));
        $mode = $content['header']['products'];
        $status = $this->help->networkStatus($signedIn);
        $open = $status === null ? null : count(array_filter($status, fn (array $incident): bool => $incident['open']));
        $company = $this->company();

        return [
            'products' => [
                'mode' => $mode === 'auto' ? (count($groups) <= self::MAX_GROUP_LINKS ? 'links' : 'menu') : $mode,
                'label' => $content['header']['menu_label'],
                'groups' => $groups,
                'compare_url' => $this->url('store.index'),
            ],
            'domains' => $this->links([
                ['Register a domain', 'store.domains'],
                ['Transfer a domain', 'store.domains', ['transfer' => 1]],
                ['Domain prices', 'storefront.domain-pricing'],
            ]),
            'support' => [
                ...$this->links([
                    ['Knowledge base', 'support.kb.index'],
                    ['Announcements', 'support.announcements.index'],
                ]),
                ...($status === null ? [] : $this->links([['Network status', 'support.network.index']], $open)),
                ...$this->links([['Open a ticket', 'support.tickets.create']]),
            ],
            'company' => $company,
            'payments' => $this->paymentMarks($currency),
            'tax_note' => $display['note'],
            'legal' => array_values(array_filter([
                ['label' => 'Terms of service', 'url' => $content['legal']['terms_url']],
                ['label' => 'Privacy policy', 'url' => $content['legal']['privacy_url']],
                ['label' => 'Refund policy', 'url' => $content['legal']['refund_url']],
            ], fn (array $link): bool => $link['url'] !== '')),
        ];
    }

    /**
     * Company details for the footer and the Organization markup. Read from Settings → Company and the invoice
     * seller details until the company profile (spec K1) replaces both.
     *
     * @return array{name: string, legal_name: string|null, address: string|null, email: string|null, phone: string|null, ids: list<array{label: string, value: string}>}
     */
    public function company(): array
    {
        $text = fn (string $key): ?string => is_string($value = $this->settings->get($key)) && trim($value) !== '' ? trim($value) : null;
        $ids = array_filter([
            'PAN' => $text('billing.seller.pan'),
            'VAT no.' => $text('billing.seller.vat_number'),
            'GSTIN' => $text('billing.seller.gstin'),
        ]);

        return [
            'name' => app(BrandProvider::class)->brand()->name,
            'legal_name' => $text('billing.seller.legal_name'),
            'address' => $text('billing.seller.address'),
            'email' => $text('billing.seller.email') ?? $text('core.company_email'),
            'phone' => $text('billing.seller.phone'),
            'ids' => array_map(fn (string $label, string $value): array => ['label' => $label, 'value' => $value], array_keys($ids), $ids),
        ];
    }

    /**
     * Gateways a visitor can pay with in this currency; the driver's official logo when it ships one, else its name.
     *
     * @return list<array{key: string, label: string, logo: array{url: string, dark_url: string|null, alt: string}|null}>
     */
    private function paymentMarks(string $currency): array
    {
        $registry = app()->bound(GatewayRegistry::class) ? app(GatewayRegistry::class) : null;

        return array_map(function (array $gateway) use ($registry): array {
            $driver = $registry?->has($gateway['key']) ? $registry->driver($gateway['key']) : null;

            return [
                'key' => $gateway['key'],
                'label' => $gateway['label'],
                'logo' => $driver instanceof ProvidesLogo ? $driver->logo()?->toArray() : null,
            ];
        }, app(GatewayDirectory::class)->available($currency));
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: array<string, int|string>}>  $links
     * @return list<array{label: string, url: string, badge: int|null}>
     */
    private function links(array $links, ?int $badge = null): array
    {
        return array_values(array_map(
            fn (array $link): array => ['label' => $link[0], 'url' => route($link[1], $link[2] ?? []), 'badge' => $badge ?: null],
            array_filter($links, fn (array $link): bool => Route::has($link[1])),
        ));
    }

    private function url(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }
}
