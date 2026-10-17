<?php

namespace App\Modules\Services\Support;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSummary;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

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
     * Custom field values (stored by field id) keyed by the field's module key for drivers.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<string, mixed>
     */
    public function customFieldsByModuleKey(int $productId, array $values): array
    {
        $byKey = [];

        foreach ($this->customFields($productId) as $field) {
            if (array_key_exists($field['id'], $values)) {
                $byKey[$this->moduleKey($field)] = $values[$field['id']];
            }
        }

        return $byKey;
    }

    /**
     * Values of password-type custom fields (never logged).
     *
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    public function secretCustomFieldValues(int $productId, array $values): array
    {
        $secrets = [];

        foreach ($this->customFields($productId) as $field) {
            if ($field['type'] === 'password' && isset($values[$field['id']]) && is_scalar($values[$field['id']])) {
                $secrets[] = (string) $values[$field['id']];
            }
        }

        return $secrets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customFields(int $productId): array
    {
        return $this->priceBook()?->customFieldsFor($productId) ?? [];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function moduleKey(array $field): string
    {
        $key = $field['module_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : Str::slug((string) $field['name'], '_');
    }

    /**
     * Auto-setup policy of the product: order|payment|accept|manual (catalog default: payment).
     */
    public function autoSetup(int $productId): string
    {
        return $this->product($productId)->autoSetup ?? 'payment';
    }
}
