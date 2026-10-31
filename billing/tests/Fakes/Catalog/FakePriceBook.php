<?php

namespace Tests\Fakes\Catalog;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use Tests\Fakes\Concerns\FakesModuleInputs;

/**
 * Configurable in-memory catalog shared by the Orders and Services tests. Prices are minor units in every currency (no
 * conversion). Orders builds it with `anyUpgrade: true, driver: 'demo', type: 'shared_hosting', requiresDomain: false`;
 * Services uses the defaults, where an upgrade needs an explicit allowUpgrade() path.
 */
class FakePriceBook implements PriceBook
{
    use FakesModuleInputs;

    /** @var list<array{0: int, 1: int}> */
    public array $upgradePaths = [];

    /** @var array<int, string> addon id => name, offered for every product */
    public array $addonNames = [];

    /** @var list<ProductSelection> */
    public array $quotedSelections = [];

    public function __construct(
        private readonly bool $anyUpgrade = false,
        private readonly string $driver = 'log',
        private readonly string $type = 'hosting',
        private readonly bool $requiresDomain = true,
    ) {}

    /** @var array<int, ProductSummary> */
    public array $products = [];

    /** @var array<int, array<string, array{0: int, 1: int}>> product id => cycle => [recurring, setup] */
    public array $prices = [];

    /** @var array<int, list<array{id: int, name: string, type: string, values: list<array{id: int, name: string, pricing: list<array{cycle: string, recurring: int, setup: int}>}>}>> */
    public array $configOptions = [];

    /** @var array<int, list<array{id: int, name: string, type: string, required: bool, options: list<string>}>> */
    public array $customFields = [];

    /** @var array<int, list<int>> product id => addon ids */
    public array $productAddons = [];

    /** @var array<int, array{name: string, pricing: array<string, array{0: int, 1: int}>}> */
    public array $addons = [];

    /** @var list<int> */
    public array $reserved = [];

    /** @var list<int> */
    public array $released = [];

    /** @var list<?int> client ids passed to quote() */
    public array $quotedFor = [];

    /**
     * @param  array<string, int|array{0: int, 1: int}>  $pricing  cycle => recurring, or [recurring, setup]
     * @param  array<string, mixed>  $driverConfig
     * @param  'order'|'payment'|'accept'|'manual'  $autoSetup
     * @param  list<string>|null  $allowedGateways
     * @param  list<string>  $freeDomainTlds
     * @param  list<string>  $freeDomainCycles
     */
    public function addProduct(
        int $id,
        string $name = 'Starter Hosting',
        array $pricing = ['monthly' => [1000, 0]],
        string $autoSetup = 'payment',
        ?bool $requiresDomain = null,
        ?int $stock = null,
        bool $taxable = true,
        bool $active = true,
        ?array $allowedGateways = null,
        bool $freeDomain = false,
        array $freeDomainTlds = [],
        array $freeDomainCycles = [],
        bool $freeDomainRecurring = false,
        ?string $driver = null,
        ?int $serverGroupId = null,
        array $driverConfig = [],
        ?int $suspendAfterDays = null,
        ?int $terminateAfterDays = null,
        ?int $recurringCycleLimit = null,
    ): self {
        $this->products[$id] = new ProductSummary(
            $id, $name, 'Hosting', $this->type, $driver ?? $this->driver, $serverGroupId, $driverConfig, $autoSetup, null, $requiresDomain ?? $this->requiresDomain, $taxable, $stock, $active,
            suspendAfterDays: $suspendAfterDays, terminateAfterDays: $terminateAfterDays, recurringCycleLimit: $recurringCycleLimit,
            freeDomain: $freeDomain, freeDomainTlds: $freeDomainTlds, freeDomainCycles: $freeDomainCycles, freeDomainRecurring: $freeDomainRecurring,
            slug: 'product-'.$id, allowedGateways: $allowedGateways, groupSlug: 'hosting',
        );
        $this->prices[$id] = array_map(fn (int|array $price): array => is_int($price) ? [$price, 0] : $price, $pricing);

        return $this;
    }

    public function allowUpgrade(int $from, int $to): void
    {
        $this->upgradePaths[] = [$from, $to];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $pricing
     */
    public function addAddon(int $productId, int $addonId, string $name = 'Backups', array $pricing = ['monthly' => [200, 0]]): self
    {
        $this->addons[$addonId] = ['name' => $name, 'pricing' => $pricing];
        $this->productAddons[$productId][] = $addonId;

        return $this;
    }

    public function setPrice(int $productId, string $cycle, int $recurring, int $setup = 0): self
    {
        $this->prices[$productId][$cycle] = [$recurring, $setup];

        return $this;
    }

    /** @var array<int, int> client id => group discount percent applied to the recurring price */
    public array $clientDiscounts = [];

    public function product(int $productId): ?ProductSummary
    {
        return $this->products[$productId] ?? null;
    }

    public function quote(ProductSelection $selection): ?PriceQuote
    {
        $this->quotedFor[] = $selection->clientId;
        $this->quotedSelections[] = $selection;
        $price = $this->prices[$selection->productId][$selection->cycle->value] ?? null;

        if ($price === null) {
            return null;
        }

        $recurring = $price[0];

        foreach ($this->configOptions[$selection->productId] ?? [] as $option) {
            foreach ($option['values'] as $value) {
                if (($selection->configOptions[$option['id']] ?? null) === $value['id']) {
                    $recurring += collect($value['pricing'])->firstWhere('cycle', $selection->cycle->value)['recurring'] ?? 0;
                }
            }
        }

        $percent = $selection->clientId === null ? 0 : ($this->clientDiscounts[$selection->clientId] ?? 0);

        if ($percent > 0) {
            $recurring = intdiv($recurring * (100 - $percent) + 50, 100);
        }

        return new PriceQuote($selection->currency, $selection->cycle, $recurring, $price[1]);
    }

    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote
    {
        $price = $this->addons[$addonId]['pricing'][$cycle->value] ?? null;

        return $price === null ? null : new PriceQuote($currency, $cycle, $price[0], $price[1]);
    }

    public function availableCycles(int $productId, string $currency): array
    {
        return array_map(fn (string $cycle): BillingCycle => BillingCycle::from($cycle), array_keys($this->prices[$productId] ?? []));
    }

    public function canUpgrade(int $fromProductId, int $toProductId): bool
    {
        return $this->anyUpgrade || in_array([$fromProductId, $toProductId], $this->upgradePaths, true);
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
                'slug' => (string) $product->slug,
                'description' => null,
                'type' => $product->type,
                'requires_domain' => $product->requiresDomain,
                'in_stock' => $product->stock !== 0,
                'pricing' => array_map(fn (string $cycle, array $price): array => ['cycle' => $cycle, 'recurring' => $price[0], 'setup' => $price[1]], array_keys($this->prices[$product->id]), $this->prices[$product->id]),
            ], $this->products)),
        ]];
    }

    public function configOptionsFor(int $productId, string $currency): array
    {
        return $this->configOptions[$productId] ?? [];
    }

    public function addonsFor(int $productId, string $currency): array
    {
        $named = array_values(array_map(fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'description' => null, 'pricing' => []], array_keys($this->addonNames), $this->addonNames));

        return [...array_map(fn (int $addonId): array => [
            'id' => $addonId,
            'name' => $this->addons[$addonId]['name'],
            'description' => null,
            'pricing' => array_map(fn (string $cycle, array $price): array => ['cycle' => $cycle, 'recurring' => $price[0], 'setup' => $price[1]], array_keys($this->addons[$addonId]['pricing']), $this->addons[$addonId]['pricing']),
        ], $this->productAddons[$productId] ?? []), ...$named];
    }

    public function customFieldsFor(int $productId): array
    {
        return $this->customFields[$productId] ?? [];
    }

    public function reserveStock(int $productId): bool
    {
        $product = $this->products[$productId] ?? null;

        if ($product === null || $product->stock === null) {
            $this->reserved[] = $productId;

            return true;
        }

        if ($product->stock <= 0) {
            return false;
        }

        $this->setStock($product, $product->stock - 1);
        $this->reserved[] = $productId;

        return true;
    }

    public function releaseStock(int $productId, int $quantity = 1): void
    {
        $product = $this->products[$productId] ?? null;
        $this->released[] = $productId;

        if ($product?->stock !== null) {
            $this->setStock($product, $product->stock + $quantity);
        }
    }

    public function productBySlug(string $slug): ?ProductSummary
    {
        foreach ($this->products as $product) {
            if ($product->slug === $slug) {
                return $product;
            }
        }

        return null;
    }

    private function setStock(ProductSummary $product, int $stock): void
    {
        $this->products[$product->id] = new ProductSummary(...[...get_object_vars($product), 'stock' => $stock]);
    }
}
