<?php

namespace Tests\Fakes\Services;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;

/**
 * In-memory PriceBook for Services tests: products, per-cycle prices and upgrade paths.
 */
class FakePriceBook implements PriceBook
{
    /** @var array<int, ProductSummary> */
    public array $products = [];

    /** @var array<int, array<string, int>> product id => cycle => recurring amount */
    public array $prices = [];

    /** @var list<array{0: int, 1: int}> */
    public array $upgradePaths = [];

    /** @var array<int, string> */
    public array $addonNames = [];

    /**
     * @param  array<string, int>  $prices  cycle value => recurring amount
     * @param  array<string, mixed>  $driverConfig
     */
    public function addProduct(int $id, string $name, array $prices = ['monthly' => 1000], string $driver = 'log', ?int $serverGroupId = null, string $autoSetup = 'payment', array $driverConfig = []): ProductSummary
    {
        $this->prices[$id] = $prices;

        return $this->products[$id] = new ProductSummary($id, $name, 'Hosting', 'hosting', $driver, $serverGroupId, $driverConfig, $autoSetup, null, true, true, null, true);
    }

    public function allowUpgrade(int $from, int $to): void
    {
        $this->upgradePaths[] = [$from, $to];
    }

    public function product(int $productId): ?ProductSummary
    {
        return $this->products[$productId] ?? null;
    }

    public function quote(ProductSelection $selection): ?PriceQuote
    {
        $amount = $this->prices[$selection->productId][$selection->cycle->value] ?? null;

        return $amount === null ? null : new PriceQuote($selection->currency, $selection->cycle, $amount, 0);
    }

    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote
    {
        return null;
    }

    public function availableCycles(int $productId, string $currency): array
    {
        return array_values(array_map(fn (string $cycle): BillingCycle => BillingCycle::from($cycle), array_keys($this->prices[$productId] ?? [])));
    }

    public function canUpgrade(int $fromProductId, int $toProductId): bool
    {
        return in_array([$fromProductId, $toProductId], $this->upgradePaths, true);
    }

    public function storefront(string $currency): array
    {
        return [[
            'id' => 1,
            'name' => 'Hosting',
            'slug' => 'hosting',
            'description' => null,
            'products' => array_values(array_map(fn (ProductSummary $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => null,
                'type' => $product->type,
                'requires_domain' => $product->requiresDomain,
                'in_stock' => true,
                'pricing' => array_values(array_map(fn (string $cycle, int $amount): array => ['cycle' => $cycle, 'recurring' => $amount, 'setup' => 0], array_keys($this->prices[$product->id] ?? []), $this->prices[$product->id] ?? [])),
            ], $this->products)),
        ]];
    }

    public function configOptionsFor(int $productId, string $currency): array
    {
        return [];
    }

    public function addonsFor(int $productId, string $currency): array
    {
        return array_values(array_map(fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'description' => null, 'pricing' => []], array_keys($this->addonNames), $this->addonNames));
    }

    public function customFieldsFor(int $productId): array
    {
        return [];
    }

    public function reserveStock(int $productId): bool
    {
        return true;
    }
}
