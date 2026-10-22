<?php

namespace App\Modules\Orders\Pricing;

use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Core\CompanyClock;
use App\Contracts\Domains\DomainPricing;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Services\CurrencyConverter;
use App\Modules\Orders\Services\CustomFieldRules;
use App\Modules\Orders\Services\DomainGuard;

/**
 * Re-quotes cart items through PriceBook/DomainPricing (the cart never trusts stored prices), applies
 * the promotion and previews tax through TaxCalculator.
 */
class CartPricer
{
    public function __construct(
        private PriceBook $priceBook,
        private DomainPricing $domainPricing,
        private TaxCalculator $taxCalculator,
        private PromotionCalculator $promotions,
        private PromotionEligibility $eligibility,
        private CurrencyConverter $converter,
        private InvoiceLineBuilder $invoiceLines,
        private CompanyClock $clock,
        private Settings $settings,
        private DomainGuard $domainGuard,
        private CustomFieldRules $customFieldRules,
    ) {}

    /**
     * @param  list<CartItem>  $items
     * @param  array<string, int>  $priceOverrides  item key => recurring amount (staff orders)
     */
    public function price(array $items, string $currency, ?string $promotionCode = null, ?Client $client = null, array $priceOverrides = []): PricedCart
    {
        $cart = new PricedCart(strtoupper($currency));

        foreach ($items as $item) {
            $cart->items[] = $item->kind === CartItemKind::Product
                ? $this->priceProduct($item, $cart->currency, $priceOverrides[$item->key] ?? null, $client?->id)
                : $this->priceDomainItem($item, $cart->currency, $client?->id);
        }

        $this->applyPromotion($cart, $promotionCode, $client);
        $this->applyTax($cart, $client);

        return $cart;
    }

    private function priceProduct(CartItem $item, string $currency, ?int $override, ?int $clientId): PricedItem
    {
        $product = $item->productId === null ? null : $this->priceBook->product($item->productId);
        $cycle = $item->cycle ?? BillingCycle::Monthly;

        if ($product === null || ! $product->active) {
            return new PricedItem($item, __('Unavailable product'), null, errors: [__('This product is no longer available.')]);
        }

        $priced = new PricedItem($item, $product->name, $product->groupName, product: $product);

        if ($product->stock !== null && $product->stock <= 0) {
            $priced->errors[] = __(':product is out of stock.', ['product' => $product->name]);
        }

        foreach ($this->customFieldRules->check($product->id, $item->customFields)['errors'] as $message) {
            $priced->errors[] = $message;
        }

        if ($product->requiresDomain && $item->domain() === null) {
            $priced->errors[] = __('Choose a domain for :product.', ['product' => $product->name]);
        }

        if ($item->domain() !== null && $this->settings->get('orders.block_duplicate_domains', true) && $this->domainGuard->isAttachedToService($item->domain())) {
            $priced->errors[] = __(':domain is already used by another service.', ['domain' => $item->domain()]);
        }

        $quote = $this->priceBook->quote(new ProductSelection($product->id, $cycle, $currency, $item->configOptions, $clientId));

        if ($quote === null) {
            $priced->errors[] = __(':product is not available for this billing cycle in :currency.', ['product' => $product->name, 'currency' => $currency]);

            return $priced;
        }

        $recurring = $override ?? $quote->recurring;
        $description = $product->name.($item->domain() !== null ? ' - '.$item->domain() : '');

        $priced->lines[] = new PricedLine(
            key: $item->key.':product',
            itemKey: $item->key,
            kind: 'product',
            description: $description,
            recurring: $recurring,
            setup: $quote->setup,
            renewal: $cycle->isRecurring() ? $recurring : 0,
            taxable: $product->taxable,
            cycle: $cycle,
            productId: $product->id,
            domain: $item->domain(),
            breakdown: $quote->breakdown,
        );

        $this->priceAddons($priced, $item, $product->id, $cycle, $currency, $product->taxable);

        if (($action = $item->domainAction()) !== null && $item->domain() !== null) {
            $freeDomain = $product->freeDomain && $action === 'register' && in_array($cycle->value, $product->freeDomainCycles, true);
            $this->priceDomain($priced, $item, $item->domain(), $action, 1, $currency, $clientId, $freeDomain ? $product : null);
        }

        return $priced;
    }

    private function priceAddons(PricedItem $priced, CartItem $item, int $productId, BillingCycle $cycle, string $currency, bool $taxable): void
    {
        if ($item->addonIds === []) {
            return;
        }

        $names = array_column($this->priceBook->addonsFor($productId, $currency), 'name', 'id');

        foreach ($item->addonIds as $addonId) {
            $quote = $this->priceBook->addonQuote($addonId, $cycle, $currency);

            if ($quote === null || ! isset($names[$addonId])) {
                $priced->errors[] = __('An addon you selected is not available for this billing cycle.');

                continue;
            }

            $priced->lines[] = new PricedLine(
                key: "{$item->key}:addon:{$addonId}",
                itemKey: $item->key,
                kind: 'addon',
                description: $names[$addonId],
                recurring: $quote->recurring,
                setup: $quote->setup,
                renewal: $cycle->isRecurring() ? $quote->recurring : 0,
                taxable: $taxable,
                cycle: $cycle,
                productId: $productId,
                addonId: $addonId,
            );
        }
    }

    private function priceDomainItem(CartItem $item, string $currency, ?int $clientId): PricedItem
    {
        $domain = (string) $item->domain();
        $action = (string) $item->domainAction();
        $title = $action === 'transfer' ? __('Domain transfer') : __('Domain registration');
        $priced = new PricedItem($item, $title, $domain);

        $this->priceDomain($priced, $item, $domain, $action, $item->years, $currency, $clientId);

        return $priced;
    }

    /**
     * @param  ProductSummary|null  $bundle  the hosting product when its free-domain offer covers this registration
     *                                       (catalog.md §3.10; the TLD is checked here)
     */
    private function priceDomain(PricedItem $priced, CartItem $item, string $domain, string $action, int $years, string $currency, ?int $clientId, ?ProductSummary $bundle = null): void
    {
        $parts = $this->domainPricing->split($domain);

        if ($parts === null) {
            $priced->errors[] = __('We do not offer :domain. Choose another extension.', ['domain' => $domain]);

            return;
        }

        $tld = strtolower($parts[1]);
        $price = $this->domainPricing->price($tld, $action === 'transfer' ? 'transfer' : 'register', $years, $currency, $clientId);

        if ($price === null && $this->domainPricing->terms($tld, $action === 'transfer' ? 'transfer' : 'register', $currency) === []) {
            // Not sold here at all, e.g. .np, which clients register at the registry themselves.
            $priced->errors[] = __(':tld domains are not sold here. If you already own one, choose "Use a domain I own".', ['tld' => $tld]);

            return;
        }

        if ($price === null) {
            $priced->errors[] = __(':tld domains cannot be ordered for :years year(s) in :currency.', ['tld' => $tld, 'years' => $years, 'currency' => $currency]);

            return;
        }

        $label = $action === 'transfer' ? __('Domain transfer') : __('Domain registration');
        $renewal = $this->domainPricing->price($tld, 'renew', $years, $currency, $clientId) ?? $price;
        $free = $bundle !== null && in_array($tld, array_map(strtolower(...), $bundle->freeDomainTlds), true);

        $priced->lines[] = new PricedLine(
            key: $item->key.':domain',
            itemKey: $item->key,
            kind: 'domain',
            description: $free ? __(':label: :domain (free with :product)', ['label' => $label, 'domain' => $domain, 'product' => $bundle->name]) : "{$label}: {$domain}",
            recurring: $free ? 0 : $price,
            renewal: $free && $bundle->freeDomainRecurring ? 0 : $renewal,
            taxable: (bool) $this->settings->get('orders.domains_taxable', true),
            years: $years,
            domain: $domain,
            tld: $tld,
            domainAction: $action,
        );
    }

    private function applyPromotion(PricedCart $cart, ?string $code, ?Client $client): void
    {
        if ($code === null || trim($code) === '') {
            return;
        }

        $cart->promotionCode = Promotion::normalizeCode($code);
        $promotion = Promotion::findByCode($code);

        if ($promotion === null) {
            $cart->promotionError = PromotionEligibility::invalidCode();

            return;
        }

        $cart->promotion = $promotion;
        $lines = $cart->validLines();
        $cart->promotionError = $this->eligibility->check($promotion, $lines, $cart->subtotal(), $cart->currency, $client);

        if ($cart->promotionError !== null) {
            return;
        }

        $amount = $promotion->type->isAmount() ? $this->converter->fromDefault($promotion->valueHundredths(), $cart->currency) : 0;
        $terms = PromotionTerms::fromPromotion($promotion, $amount);
        $discounts = $this->promotions->discounts($terms, array_map(fn (PricedLine $line): DiscountTarget => $line->toDiscountTarget(), $lines));

        if ($discounts === []) {
            $cart->promotionError = __('This promotion code does not apply to the items in your cart.');

            return;
        }

        foreach ($lines as $line) {
            if (isset($discounts[$line->key])) {
                $line->discount = $discounts[$line->key]['discount'];
                $line->setupDiscount = $discounts[$line->key]['setup_discount'];
                $line->recurringDiscount = $promotion->recurring && $line->kind !== 'domain';
            }
        }
    }

    private function applyTax(PricedCart $cart, ?Client $client): void
    {
        $lines = $this->invoiceLines->build($cart, $this->clock->today());

        if ($lines === []) {
            return;
        }

        $totals = $client !== null
            ? $this->taxCalculator->calculateForClient($lines, $client->id, $cart->currency)
            : $this->taxCalculator->calculate($lines, $cart->currency, (string) $this->settings->get('core.default_country', 'US'), null);

        $cart->taxes = $totals->taxes;
        $cart->taxTotal = $totals->taxAmount();
    }
}
