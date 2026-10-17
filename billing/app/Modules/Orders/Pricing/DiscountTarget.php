<?php

namespace App\Modules\Orders\Pricing;

/**
 * A priced unit a promotion may discount: a product, an addon or a domain line (minor units).
 */
final readonly class DiscountTarget
{
    /**
     * @param  'product'|'addon'|'domain'  $kind
     */
    public function __construct(
        public string $key,
        public string $kind,
        public int $base,
        public int $setup = 0,
        public ?int $productId = null,
        public ?int $addonId = null,
        public ?string $tld = null,
        public ?string $cycle = null,
    ) {}
}
