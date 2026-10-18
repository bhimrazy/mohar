<?php

namespace App\Modules\Orders\Pricing;

use App\Enums\BillingCycle;

/**
 * One chargeable unit of a cart item: the product itself, one of its addons, or a domain.
 * `recurring` is the first-period amount; `renewal` what later periods cost (before promotions).
 */
final class PricedLine
{
    /**
     * @param  'product'|'addon'|'domain'  $kind
     * @param  list<array{label: string, recurring: int, setup: int}>  $breakdown
     */
    public function __construct(
        public readonly string $key,
        public readonly string $itemKey,
        public readonly string $kind,
        public readonly string $description,
        public readonly int $recurring,
        public readonly int $setup = 0,
        public readonly int $renewal = 0,
        public readonly bool $taxable = true,
        public readonly ?BillingCycle $cycle = null,
        public readonly int $years = 1,
        public readonly ?int $productId = null,
        public readonly ?int $addonId = null,
        public readonly ?string $domain = null,
        public readonly ?string $tld = null,
        public readonly ?string $domainAction = null,
        public readonly array $breakdown = [],
        public int $discount = 0,
        public int $setupDiscount = 0,
        public bool $recurringDiscount = false,
    ) {}

    /**
     * Amount due today for this line after discounts.
     */
    public function dueToday(): int
    {
        return $this->recurring + $this->setup - $this->discount - $this->setupDiscount;
    }

    public function totalDiscount(): int
    {
        return $this->discount + $this->setupDiscount;
    }

    /**
     * What each renewal costs (recurring promotions keep discounting renewals).
     */
    public function renewalAfterDiscount(): int
    {
        return max(0, $this->renewal - ($this->recurringDiscount ? $this->discount : 0));
    }

    public function toDiscountTarget(): DiscountTarget
    {
        return new DiscountTarget(
            key: $this->key,
            kind: $this->kind,
            base: $this->recurring,
            setup: $this->setup,
            productId: $this->productId,
            addonId: $this->addonId,
            tld: $this->tld,
            cycle: $this->cycle?->value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'description' => $this->description,
            'cycle' => $this->cycle?->value,
            'years' => $this->years,
            'domain' => $this->domain,
            'recurring' => $this->recurring,
            'setup' => $this->setup,
            'renewal' => $this->renewalAfterDiscount(),
            'discount' => $this->totalDiscount(),
            'due_today' => $this->dueToday(),
            'breakdown' => $this->breakdown,
        ];
    }
}
