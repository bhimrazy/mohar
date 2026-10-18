<?php

namespace App\Modules\Orders\Pricing;

use App\Modules\Orders\Models\Promotion;

/**
 * A freshly re-quoted cart: items, discounts, tax preview and totals (minor units, cart currency).
 */
final class PricedCart
{
    /**
     * @param  list<PricedItem>  $items
     * @param  list<array{name: string, rate: float, amount: int}>  $taxes
     */
    public function __construct(
        public readonly string $currency,
        public array $items = [],
        public ?Promotion $promotion = null,
        public ?string $promotionCode = null,
        public ?string $promotionError = null,
        public array $taxes = [],
        public int $taxTotal = 0,
    ) {}

    /**
     * @return list<PricedLine>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->items as $item) {
            array_push($lines, ...$item->lines);
        }

        return $lines;
    }

    /**
     * @return list<PricedLine>
     */
    public function validLines(): array
    {
        $lines = [];

        foreach ($this->items as $item) {
            if ($item->isValid()) {
                array_push($lines, ...$item->lines);
            }
        }

        return $lines;
    }

    /**
     * Gross amount due today before discounts and tax (first periods + setup fees).
     */
    public function subtotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->recurring + $line->setup, $this->validLines()));
    }

    public function setupTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->setup, $this->validLines()));
    }

    public function discountTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->totalDiscount(), $this->validLines()));
    }

    /**
     * Due today after discounts, before tax (tax depends on the buyer's address, prices do not).
     */
    public function netTotal(): int
    {
        return $this->subtotal() - $this->discountTotal();
    }

    public function total(): int
    {
        return $this->netTotal() + $this->taxTotal;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $item) {
            if (! $item->isValid()) {
                return true;
            }
        }

        return false;
    }

    public function promotionApplied(): bool
    {
        return $this->promotion !== null && $this->promotionError === null && $this->discountTotal() > 0;
    }

    /**
     * Recurring charges per cycle after recurring promotions, e.g. [['cycle' => 'monthly', 'amount' => 1000]].
     *
     * @return list<array{cycle: string, amount: int}>
     */
    public function recurringTotals(): array
    {
        $totals = [];

        foreach ($this->validLines() as $line) {
            $cycle = $line->kind === 'domain' ? ($line->years === 1 ? 'annually' : "{$line->years}_years") : $line->cycle?->value;

            if ($cycle === null || in_array($cycle, ['onetime', 'free'], true)) {
                continue;
            }

            $totals[$cycle] = ($totals[$cycle] ?? 0) + $line->renewalAfterDiscount();
        }

        return array_map(fn (string $cycle, int $amount): array => ['cycle' => $cycle, 'amount' => $amount], array_keys($totals), $totals);
    }

    /**
     * Product ids of all valid items (gateway intersection, stock).
     *
     * @return list<int>
     */
    public function productIds(): array
    {
        $ids = [];

        foreach ($this->items as $item) {
            if ($item->item->productId !== null) {
                $ids[] = $item->item->productId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'items' => array_map(fn (PricedItem $item): array => $item->toArray(), $this->items),
            'promotion' => $this->promotionCode === null ? null : [
                'code' => $this->promotionCode,
                'applied' => $this->promotionApplied(),
                'error' => $this->promotionError,
                'summary' => $this->promotion?->summary(),
            ],
            'subtotal' => $this->subtotal(),
            'setup_total' => $this->setupTotal(),
            'discount_total' => $this->discountTotal(),
            'net_total' => $this->netTotal(),
            'taxes' => $this->taxes,
            'tax_total' => $this->taxTotal,
            'total' => $this->total(),
            'recurring' => $this->recurringTotals(),
            'has_errors' => $this->hasErrors(),
            'count' => count($this->items),
        ];
    }
}
