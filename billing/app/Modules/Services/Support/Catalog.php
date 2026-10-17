<?php

namespace App\Modules\Services\Support;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSummary;
use Illuminate\Contracts\Container\Container;

/**
 * Access to the Catalog module through its PriceBook contract. Degrades gracefully when no
 * Catalog implementation is bound (Services running alone): products resolve to null.
 */
class Catalog
{
    /** @var array<int, ProductSummary|null> */
    private array $products = [];

    public function __construct(private Container $container) {}

    public function priceBook(): ?PriceBook
    {
        return $this->container->bound(PriceBook::class) ? $this->container->make(PriceBook::class) : null;
    }

    public function product(int $productId): ?ProductSummary
    {
        if (! array_key_exists($productId, $this->products)) {
            $this->products[$productId] = $this->priceBook()?->product($productId);
        }

        return $this->products[$productId];
    }

    /**
     * Auto-setup policy of the product: order|payment|accept|manual (catalog default: payment).
     */
    public function autoSetup(int $productId): string
    {
        return $this->product($productId)->autoSetup ?? 'payment';
    }
}
