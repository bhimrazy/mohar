<?php

namespace App\Modules\Orders\Pricing;

use App\Data\Catalog\ProductSummary;
use App\Modules\Orders\Data\CartItem;

/**
 * A cart item with its priced lines (product + addons + domain) and any problem that blocks checkout.
 */
final class PricedItem
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<string>  $errors
     * @param  list<array{label: string, value: string}>  $details  the registrar's extension details, as they read back
     */
    public function __construct(
        public readonly CartItem $item,
        public readonly string $title,
        public readonly ?string $subtitle,
        public array $lines = [],
        public array $errors = [],
        public array $details = [],
        public readonly ?ProductSummary $product = null,
    ) {}

    public function dueToday(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->dueToday(), $this->lines));
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->item->key,
            'kind' => $this->item->kind->value,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'product_id' => $this->item->productId,
            'cycle' => $this->item->cycle?->value,
            'domain' => $this->item->domain(),
            'domain_mode' => $this->item->domainMode,
            'years' => $this->item->years,
            'lines' => array_map(fn (PricedLine $line): array => $line->toArray(), $this->lines),
            'errors' => $this->errors,
            'details' => $this->details,
            'due_today' => $this->dueToday(),
        ];
    }
}
