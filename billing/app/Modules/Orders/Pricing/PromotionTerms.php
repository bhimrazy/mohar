<?php

namespace App\Modules\Orders\Pricing;

use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Promotion;

/**
 * The calculation-relevant part of a promotion, independent of storage.
 */
final readonly class PromotionTerms
{
    /**
     * @param  int  $value  hundredths: 2500 = 25% for percent; for fixed/price_override the amount in the CART currency (minor units)
     * @param  list<int>  $productIds  applies_to.product_ids
     * @param  list<int>  $addonIds  applies_to.addon_ids
     * @param  list<string>  $tlds  applies_to.tlds (with leading dot)
     * @param  list<string>|null  $cycles  null = any cycle
     */
    public function __construct(
        public PromotionType $type,
        public int $value,
        public array $productIds = [],
        public array $addonIds = [],
        public array $tlds = [],
        public ?array $cycles = null,
        public bool $applyOnce = false,
        public bool $discountSetupFee = false,
    ) {}

    /**
     * @param  int  $amountInCartCurrency  the promotion's amount converted to the cart currency (amount types only)
     */
    public static function fromPromotion(Promotion $promotion, int $amountInCartCurrency): self
    {
        return new self(
            type: $promotion->type,
            value: $promotion->type->isAmount() ? $amountInCartCurrency : $promotion->valueHundredths(),
            productIds: array_map(intval(...), $promotion->applies_to['product_ids'] ?? []),
            addonIds: array_map(intval(...), $promotion->applies_to['addon_ids'] ?? []),
            tlds: array_map(fn (string $tld): string => '.'.ltrim(strtolower($tld), '.'), $promotion->applies_to['tlds'] ?? []),
            cycles: $promotion->cycles === null || $promotion->cycles === [] ? null : $promotion->cycles,
            applyOnce: $promotion->apply_once,
            discountSetupFee: $promotion->discount_setup_fee,
        );
    }

    /**
     * No product/addon/TLD restriction means the promotion applies to every product (not addons/domains).
     */
    public function hasTargetRestriction(): bool
    {
        return $this->productIds !== [] || $this->addonIds !== [] || $this->tlds !== [];
    }
}
