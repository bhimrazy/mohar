<?php

namespace App\Modules\Orders\Pricing;

use App\Modules\Orders\Enums\PromotionType;

/**
 * Pure promotion math (orders.md §5). All amounts are integer minor units; every per-line amount is
 * rounded half-up to the minor unit before summation and a discount never exceeds what it discounts.
 */
final class PromotionCalculator
{
    /**
     * Targets the promotion applies to (before apply_once narrowing).
     *
     * @param  list<DiscountTarget>  $targets
     * @return list<DiscountTarget>
     */
    public function applicable(PromotionTerms $terms, array $targets): array
    {
        return array_values(array_filter($targets, fn (DiscountTarget $target): bool => $this->matches($terms, $target)));
    }

    /**
     * Discount per target key: the recurring/first-period discount and the setup-fee discount.
     *
     * @param  list<DiscountTarget>  $targets
     * @return array<string, array{discount: int, setup_discount: int}>
     */
    public function discounts(PromotionTerms $terms, array $targets): array
    {
        $applicable = $this->applicable($terms, $targets);

        if ($terms->applyOnce && $applicable !== []) {
            usort($applicable, fn (DiscountTarget $a, DiscountTarget $b): int => $b->base <=> $a->base);
            $applicable = [$applicable[0]];
        }

        $result = [];

        foreach ($applicable as $target) {
            $discounts = $this->discountFor($terms, $target);

            if ($discounts['discount'] > 0 || $discounts['setup_discount'] > 0) {
                $result[$target->key] = $discounts;
            }
        }

        return $result;
    }

    /**
     * @return array{discount: int, setup_discount: int}
     */
    public function discountFor(PromotionTerms $terms, DiscountTarget $target): array
    {
        $base = max(0, $target->base);
        $setup = max(0, $target->setup);
        $value = max(0, $terms->value);

        return match ($terms->type) {
            PromotionType::Percent => [
                'discount' => min($base, self::percentOf($base, $value)),
                'setup_discount' => $terms->discountSetupFee ? min($setup, self::percentOf($setup, $value)) : 0,
            ],
            PromotionType::Fixed => [
                'discount' => $fixed = min($base, $value),
                'setup_discount' => $terms->discountSetupFee ? min($setup, $value - $fixed) : 0,
            ],
            PromotionType::PriceOverride => [
                'discount' => max(0, $base - $value),
                'setup_discount' => 0,
            ],
            PromotionType::FreeSetup => [
                'discount' => 0,
                'setup_discount' => $setup,
            ],
        };
    }

    /**
     * round(amount × hundredths / 10000) half-up, in integers (no floats).
     */
    public static function percentOf(int $amount, int $hundredths): int
    {
        $numerator = $amount * $hundredths;

        return intdiv($numerator + 5000, 10000);
    }

    private function matches(PromotionTerms $terms, DiscountTarget $target): bool
    {
        if ($terms->cycles !== null && $target->kind !== 'domain' && ! in_array($target->cycle, $terms->cycles, true)) {
            return false;
        }

        if (! $terms->hasTargetRestriction()) {
            return $target->kind === 'product';
        }

        return match ($target->kind) {
            'product' => in_array($target->productId, $terms->productIds, true),
            'addon' => in_array($target->addonId, $terms->addonIds, true),
            'domain' => in_array($target->tld, $terms->tlds, true),
        };
    }
}
