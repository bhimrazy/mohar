<?php

namespace App\Modules\Orders\Support\Demo;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * LOCAL DEMO ONLY: a fixed catalog so the storefront works before the Catalog module is merged.
 * Bound by OrdersServiceProvider only in the local environment and only when no real PriceBook exists.
 * Prices are defined in USD minor units and converted with the currency's exchange rate.
 */
class DemoCatalog implements PriceBook
{
    /**
     * @var array<int, array{group: int, name: string, description: string, type: string, requires_domain: bool, auto_setup: 'order'|'payment'|'accept'|'manual', stock: ?int, pricing: array<string, array{0: int, 1: int}>, options: list<int>, addons: list<int>, fields: list<int>}>
     */
    private const PRODUCTS = [
        1 => ['group' => 1, 'name' => 'Starter Hosting', 'description' => '1 website, 10 GB SSD storage, free SSL, weekly backups.', 'type' => 'shared_hosting', 'requires_domain' => true, 'auto_setup' => 'payment', 'stock' => null,
            'pricing' => ['monthly' => [499, 0], 'annually' => [4990, 0]], 'options' => [], 'addons' => [1, 2], 'fields' => []],
        2 => ['group' => 1, 'name' => 'Business Hosting', 'description' => '10 websites, 50 GB NVMe storage, daily backups, staging.', 'type' => 'shared_hosting', 'requires_domain' => true, 'auto_setup' => 'payment', 'stock' => null,
            'pricing' => ['monthly' => [999, 0], 'quarterly' => [2847, 0], 'annually' => [9990, 0]], 'options' => [], 'addons' => [1, 2], 'fields' => []],
        3 => ['group' => 1, 'name' => 'Pro Hosting', 'description' => 'Unlimited websites, 200 GB NVMe, priority support.', 'type' => 'shared_hosting', 'requires_domain' => true, 'auto_setup' => 'payment', 'stock' => 0,
            'pricing' => ['monthly' => [1999, 0], 'annually' => [19990, 0]], 'options' => [], 'addons' => [1, 2], 'fields' => []],
        4 => ['group' => 2, 'name' => 'Cloud VPS S', 'description' => '2 vCPU, dedicated IPv4, 60 GB NVMe, full root access.', 'type' => 'server', 'requires_domain' => false, 'auto_setup' => 'order', 'stock' => 25,
            'pricing' => ['monthly' => [1200, 500], 'annually' => [12000, 0]], 'options' => [1, 2, 3], 'addons' => [3], 'fields' => [1, 2]],
        5 => ['group' => 2, 'name' => 'Cloud VPS M', 'description' => '4 vCPU, dedicated IPv4, 160 GB NVMe, full root access.', 'type' => 'server', 'requires_domain' => false, 'auto_setup' => 'order', 'stock' => 10,
            'pricing' => ['monthly' => [2400, 500], 'annually' => [24000, 0]], 'options' => [1, 2, 3], 'addons' => [3], 'fields' => [1, 2]],
        6 => ['group' => 3, 'name' => 'Business Email', 'description' => '25 GB mailbox, calendar and contacts, spam filtering.', 'type' => 'other', 'requires_domain' => true, 'auto_setup' => 'accept', 'stock' => null,
            'pricing' => ['monthly' => [300, 0], 'annually' => [3000, 0]], 'options' => [], 'addons' => [], 'fields' => []],
    ];

    private const GROUPS = [
        1 => ['name' => 'Web Hosting', 'slug' => 'web-hosting', 'description' => 'Fast, secure hosting for websites and online stores.'],
        2 => ['name' => 'Cloud VPS', 'slug' => 'cloud-vps', 'description' => 'Virtual servers with dedicated resources and root access.'],
        3 => ['name' => 'Email', 'slug' => 'email', 'description' => 'Professional email on your own domain.'],
    ];

    /** @var array<int, array{name: string, type: string, values: array<int, array{name: string, pricing: array<string, int>}>}> */
    private const OPTIONS = [
        1 => ['name' => 'Memory', 'type' => 'dropdown', 'values' => [
            1 => ['name' => '4 GB', 'pricing' => ['monthly' => 0, 'annually' => 0]],
            2 => ['name' => '8 GB', 'pricing' => ['monthly' => 600, 'annually' => 6000]],
            3 => ['name' => '16 GB', 'pricing' => ['monthly' => 1400, 'annually' => 14000]],
        ]],
        2 => ['name' => 'Operating system', 'type' => 'radio', 'values' => [
            4 => ['name' => 'Ubuntu 24.04 LTS', 'pricing' => ['monthly' => 0, 'annually' => 0]],
            5 => ['name' => 'Debian 13', 'pricing' => ['monthly' => 0, 'annually' => 0]],
            6 => ['name' => 'Windows Server 2025', 'pricing' => ['monthly' => 1500, 'annually' => 15000]],
        ]],
        3 => ['name' => 'Additional IPv4 addresses', 'type' => 'quantity', 'values' => [
            7 => ['name' => 'IPv4 address', 'pricing' => ['monthly' => 150, 'annually' => 1500]],
        ]],
    ];

    /** @var array<int, array{name: string, description: string, pricing: array<string, array{0: int, 1: int}>}> */
    private const ADDONS = [
        1 => ['name' => 'Daily off-site backups', 'description' => '30 days of restorable snapshots.', 'pricing' => ['monthly' => [200, 0], 'quarterly' => [600, 0], 'annually' => [2000, 0]]],
        2 => ['name' => 'Premium SSL certificate', 'description' => 'Organisation-validated certificate with site seal.', 'pricing' => ['monthly' => [400, 0], 'quarterly' => [1200, 0], 'annually' => [4000, 0]]],
        3 => ['name' => 'Managed server support', 'description' => 'We patch, monitor and harden your server.', 'pricing' => ['monthly' => [1500, 0], 'annually' => [15000, 0]]],
    ];

    /** @var array<int, array{name: string, type: string, required: bool, options: list<string>}> */
    private const FIELDS = [
        1 => ['name' => 'Server hostname', 'type' => 'text', 'required' => true, 'options' => []],
        2 => ['name' => 'Data centre', 'type' => 'dropdown', 'required' => true, 'options' => ['Frankfurt', 'London', 'New York', 'Singapore']],
    ];

    public function product(int $productId): ?ProductSummary
    {
        $product = self::PRODUCTS[$productId] ?? null;

        if ($product === null) {
            return null;
        }

        return new ProductSummary(
            id: $productId,
            name: $product['name'],
            groupName: self::GROUPS[$product['group']]['name'],
            type: $product['type'],
            provisioningDriver: 'demo',
            serverGroupId: null,
            driverConfig: [],
            autoSetup: $product['auto_setup'],
            welcomeEmail: null,
            requiresDomain: $product['requires_domain'],
            taxable: true,
            stock: $this->stock($productId),
            active: true,
        );
    }

    public function quote(ProductSelection $selection): ?PriceQuote
    {
        $product = self::PRODUCTS[$selection->productId] ?? null;
        $base = $product['pricing'][$selection->cycle->value] ?? null;

        if ($product === null || $base === null) {
            return null;
        }

        $rate = $this->rate($selection->currency);
        $breakdown = [['label' => $product['name'], 'recurring' => $this->convert($base[0], $rate), 'setup' => $this->convert($base[1], $rate)]];

        foreach ($product['options'] as $optionId) {
            $option = self::OPTIONS[$optionId];
            $selected = $selection->configOptions[$optionId] ?? null;

            if ($option['type'] === 'quantity') {
                $unit = reset($option['values']);
                $quantity = (int) $selected;

                if ($quantity > 0) {
                    $breakdown[] = ['label' => "{$option['name']}: {$quantity}", 'recurring' => $this->convert(($unit['pricing'][$selection->cycle->value] ?? 0) * $quantity, $rate), 'setup' => 0];
                }

                continue;
            }

            $value = $option['values'][$selected] ?? reset($option['values']);
            $breakdown[] = ['label' => "{$option['name']}: {$value['name']}", 'recurring' => $this->convert($value['pricing'][$selection->cycle->value] ?? 0, $rate), 'setup' => 0];
        }

        return new PriceQuote(
            currency: $selection->currency,
            cycle: $selection->cycle,
            recurring: array_sum(array_column($breakdown, 'recurring')),
            setup: array_sum(array_column($breakdown, 'setup')),
            breakdown: $breakdown,
        );
    }

    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote
    {
        $price = self::ADDONS[$addonId]['pricing'][$cycle->value] ?? null;

        if ($price === null) {
            return null;
        }

        $rate = $this->rate($currency);

        return new PriceQuote($currency, $cycle, $this->convert($price[0], $rate), $this->convert($price[1], $rate));
    }

    public function availableCycles(int $productId, string $currency): array
    {
        return array_map(fn (string $cycle): BillingCycle => BillingCycle::from($cycle), array_keys(self::PRODUCTS[$productId]['pricing'] ?? []));
    }

    public function canUpgrade(int $fromProductId, int $toProductId): bool
    {
        return (self::PRODUCTS[$fromProductId]['group'] ?? null) === (self::PRODUCTS[$toProductId]['group'] ?? 0);
    }

    public function storefront(string $currency): array
    {
        $rate = $this->rate($currency);
        $groups = [];

        foreach (self::GROUPS as $groupId => $group) {
            $products = [];

            foreach (self::PRODUCTS as $productId => $product) {
                if ($product['group'] !== $groupId) {
                    continue;
                }

                $products[] = [
                    'id' => $productId,
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'type' => $product['type'],
                    'requires_domain' => $product['requires_domain'],
                    'in_stock' => $this->stock($productId) !== 0,
                    'pricing' => $this->pricing($product['pricing'], $rate),
                ];
            }

            $groups[] = ['id' => $groupId, 'name' => $group['name'], 'slug' => $group['slug'], 'description' => $group['description'], 'products' => $products];
        }

        return $groups;
    }

    public function configOptionsFor(int $productId, string $currency): array
    {
        $rate = $this->rate($currency);

        return array_map(fn (int $optionId): array => [
            'id' => $optionId,
            'name' => self::OPTIONS[$optionId]['name'],
            'type' => self::OPTIONS[$optionId]['type'],
            'values' => array_map(fn (int $valueId, array $value): array => [
                'id' => $valueId,
                'name' => $value['name'],
                'pricing' => array_map(fn (string $cycle, int $amount): array => ['cycle' => $cycle, 'recurring' => $this->convert($amount, $rate), 'setup' => 0], array_keys($value['pricing']), $value['pricing']),
            ], array_keys(self::OPTIONS[$optionId]['values']), self::OPTIONS[$optionId]['values']),
        ], self::PRODUCTS[$productId]['options'] ?? []);
    }

    public function addonsFor(int $productId, string $currency): array
    {
        $rate = $this->rate($currency);

        return array_map(fn (int $addonId): array => [
            'id' => $addonId,
            'name' => self::ADDONS[$addonId]['name'],
            'description' => self::ADDONS[$addonId]['description'],
            'pricing' => $this->pricing(self::ADDONS[$addonId]['pricing'], $rate),
        ], self::PRODUCTS[$productId]['addons'] ?? []);
    }

    public function customFieldsFor(int $productId): array
    {
        return array_map(fn (int $fieldId): array => ['id' => $fieldId, ...self::FIELDS[$fieldId]], self::PRODUCTS[$productId]['fields'] ?? []);
    }

    public function reserveStock(int $productId): bool
    {
        $stock = $this->stock($productId);

        if ($stock === null) {
            return true;
        }

        if ($stock <= 0) {
            return false;
        }

        Cache::forever("orders.demo.stock.{$productId}", $stock - 1);

        return true;
    }

    private function stock(int $productId): ?int
    {
        $initial = self::PRODUCTS[$productId]['stock'] ?? null;

        return $initial === null ? null : (int) Cache::get("orders.demo.stock.{$productId}", $initial);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $pricing
     * @return list<array{cycle: string, recurring: int, setup: int}>
     */
    private function pricing(array $pricing, float $rate): array
    {
        return array_map(fn (string $cycle, array $price): array => [
            'cycle' => $cycle,
            'recurring' => $this->convert($price[0], $rate),
            'setup' => $this->convert($price[1], $rate),
        ], array_keys($pricing), $pricing);
    }

    private function rate(string $currency): float
    {
        return (float) (Currency::query()->where('code', $currency)->value('exchange_rate') ?? 1);
    }

    private function convert(int $amount, float $rate): int
    {
        return (int) round($amount * $rate);
    }
}
