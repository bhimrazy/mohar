<?php

namespace App\Modules\Catalog\Services;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ModuleInputs;
use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Events\Catalog\ProductStockDepleted;
use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Catalog\Support\CyclePicker;
use App\Modules\Catalog\Support\PriceCalculator;
use App\Modules\Catalog\Support\RegexMatcher;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use Illuminate\Support\Facades\DB;

/**
 * Catalog implementation of the PriceBook contract (read-only pricing for Orders and Services).
 */
class CatalogPriceBook implements PriceBook
{
    /** Upper bound for quantity options without a configured maximum (keeps unit × quantity in integer range). */
    public const MAX_QUANTITY = 1_000_000;

    public function product(int $productId): ?ProductSummary
    {
        $product = Product::query()->with('group:id,name,allowed_gateways')->find($productId);

        return $product === null ? null : $this->summarise($product);
    }

    public function productsForDriver(string $driverKey): array
    {
        return array_values(Product::query()->with('group:id,name,allowed_gateways')
            ->where('provisioning_driver', $driverKey)
            ->where('is_retired', false)
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): ProductSummary => $this->summarise($product))
            ->all());
    }

    public function productBySlug(string $slug): ?ProductSummary
    {
        $product = Product::query()->with('group:id,name,allowed_gateways')->where('slug', strtolower(trim($slug)))->first();

        return $product === null ? null : $this->summarise($product);
    }

    /**
     * Recurring = product price + Σ option values (unit × quantity for quantity options); setup likewise.
     * Hidden options/values are still priced when explicitly selected (existing services keep their price).
     * Dropdown/radio options without a selection use their first visible value. With a client id, the
     * client's group discount is applied after options, rounded half-up per line.
     *
     * Returns null (never throws) for an unknown/retired product, a cycle or currency that is not offered,
     * an unpriced option value, or an invalid selection (foreign value id, quantity outside the limits).
     */
    public function quote(ProductSelection $selection): ?PriceQuote
    {
        $product = Product::query()
            ->with([
                'prices' => $this->pricesIn($selection->currency),
                'configOptionGroups.options.values.prices' => $this->pricesIn($selection->currency),
            ])
            ->find($selection->productId);

        if ($product === null || $product->is_retired || ! $this->wellFormedOptions($selection->configOptions)) {
            return null;
        }

        $quote = $this->quoteProduct($product, $selection->cycle, strtoupper($selection->currency), $selection->configOptions);
        $discount = $selection->clientId === null ? 0.0 : $this->groupDiscount($selection->clientId);

        return $quote !== null && $discount > 0 ? PriceCalculator::discount($quote, $discount) : $quote;
    }

    /**
     * Contract shape is option id => value id (or quantity); anything else (strings, floats, arrays) is an invalid
     * selection and must yield a null quote instead of a TypeError further down.
     *
     * @param  array<mixed, mixed>  $options
     */
    private function wellFormedOptions(array $options): bool
    {
        foreach ($options as $optionId => $value) {
            if (! is_int($optionId) || ! is_int($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uses the parent's cycle when the addon is priced for it, otherwise the addon's shortest enabled cycle;
     * the returned quote's cycle is the one actually chosen.
     */
    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote
    {
        $currency = strtoupper($currency);
        $addon = Addon::query()->with(['prices' => $this->pricesIn($currency)])->find($addonId);

        if ($addon === null || $addon->is_retired) {
            return null;
        }

        if ($addon->payment_type === PaymentType::Free) {
            return PriceCalculator::quote($currency, BillingCycle::Free, [['label' => $addon->name, 'recurring' => 0, 'setup' => 0]]);
        }

        $enabled = array_values(array_filter(
            array_map(fn (Price $price): BillingCycle => $price->cycle, $addon->enabledPricesIn($currency)),
            fn (BillingCycle $priced): bool => in_array($priced, $addon->payment_type->cycles(), true),
        ));
        $chosen = CyclePicker::forAddon($enabled, $cycle);
        $price = $chosen === null ? null : $addon->priceFor($currency, $chosen);

        if ($chosen === null || $price === null) {
            return null;
        }

        return PriceCalculator::quote($currency, $chosen, [['label' => $addon->name, 'recurring' => $price->amount, 'setup' => $price->setup_fee]]);
    }

    public function availableCycles(int $productId, string $currency): array
    {
        $product = Product::query()->with(['prices' => $this->pricesIn($currency)])->find($productId);

        if ($product === null || $product->is_retired) {
            return [];
        }

        return $this->cyclesOf($product, strtoupper($currency));
    }

    /**
     * True only when a path exists, the target is not retired and the target is sellable in some currency.
     */
    public function canUpgrade(int $fromProductId, int $toProductId): bool
    {
        if ($fromProductId === $toProductId) {
            return false;
        }

        $pathExists = DB::table('product_upgrade_paths')
            ->where('product_id', $fromProductId)
            ->where('target_product_id', $toProductId)
            ->exists();

        if (! $pathExists) {
            return false;
        }

        $target = Product::query()->with(['prices' => fn ($query) => $query->where('enabled', true)])->find($toProductId);

        if ($target === null || $target->is_retired) {
            return false;
        }

        return $target->payment_type === PaymentType::Free
            || $target->prices->contains(fn (Price $price): bool => in_array($price->cycle, $target->payment_type->cycles(), true));
    }

    /**
     * Extra keys beyond the contract shape: group headline/tagline/allowed_gateways, product slug/tagline/
     * payment_type/featured/stock and per-row monthly equivalent.
     */
    public function storefront(string $currency): array
    {
        $currency = strtoupper($currency);

        $groups = ProductGroup::query()
            ->where('is_hidden', false)
            ->ordered()
            ->with(['products' => fn ($query) => $query
                ->where('is_hidden', false)
                ->where('is_retired', false)
                ->orderBy('sort_order')->orderBy('id')
                ->with(['prices' => $this->pricesIn($currency)]),
            ])
            ->get();

        $result = [];

        foreach ($groups as $group) {
            $products = [];

            foreach ($group->products as $product) {
                $pricing = $this->pricingRows($product, $currency);

                if ($pricing === []) {
                    continue;
                }

                $products[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'tagline' => $product->tagline,
                    'description' => $product->description,
                    'type' => $product->type->value,
                    'payment_type' => $product->payment_type->value,
                    'is_featured' => $product->is_featured,
                    'is_taxable' => $product->is_taxable,
                    'requires_domain' => $product->requires_domain,
                    'in_stock' => ! $product->isOutOfStock(),
                    'stock' => $product->stock_enabled ? $product->stock_quantity : null,
                    'pricing' => $pricing,
                ];
            }

            if ($products === []) {
                continue;
            }

            $result[] = [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'headline' => $group->headline,
                'tagline' => $group->tagline,
                'description' => $group->description,
                'allowed_gateways' => $group->allowed_gateways,
                'products' => $products,
            ];
        }

        return $result;
    }

    public function moduleInputs(int $productId, array $optionSelections, array $customFieldValues): ModuleInputs
    {
        $product = Product::query()->with(['configOptionGroups.options.values', 'customFields'])->find($productId);

        if ($product === null) {
            return new ModuleInputs;
        }

        $options = [];

        foreach ($this->optionsOf($product) as $option) {
            $selected = $optionSelections[$option->id] ?? null;

            if ($option->module_key === null || $option->module_key === '' || $selected === null || $selected === '') {
                continue;
            }

            if ($option->type === ConfigOptionType::Quantity) {
                $options[$option->module_key] = (int) $selected;

                continue;
            }

            if ($option->type === ConfigOptionType::YesNo) {
                $options[$option->module_key] = (int) $selected === 0 ? '0' : '1';

                continue;
            }

            // A value that no longer belongs to the option (deleted, moved) is left out: its id means nothing to a driver.
            $value = $option->values->firstWhere('id', (int) $selected);

            if ($value !== null) {
                $options[$option->module_key] = $value->module_value !== null && $value->module_value !== '' ? $value->module_value : $value->label;
            }
        }

        $fields = [];
        $secrets = [];

        foreach ($product->customFields as $field) {
            if (! array_key_exists($field->id, $customFieldValues)) {
                continue;
            }

            $value = $customFieldValues[$field->id];

            if ($field->type === CustomFieldType::Password && is_scalar($value) && (string) $value !== '') {
                $secrets[] = (string) $value;
            }

            if ($field->module_key !== null && $field->module_key !== '') {
                $fields[$field->module_key] = $value;
            }
        }

        return new ModuleInputs($options, $fields, $secrets);
    }

    /**
     * Visible options and values only. Extra keys: module_key, required, quantity_min/max/step, value module_value.
     */
    public function configOptionsFor(int $productId, string $currency): array
    {
        $currency = strtoupper($currency);
        $product = Product::query()
            ->with([
                'prices' => $this->pricesIn($currency),
                'configOptionGroups.options' => fn ($query) => $query->where('is_hidden', false),
                'configOptionGroups.options.values' => fn ($query) => $query->where('is_hidden', false),
                'configOptionGroups.options.values.prices' => $this->pricesIn($currency),
            ])
            ->find($productId);

        if ($product === null || $product->is_retired) {
            return [];
        }

        $cycles = $this->cyclesOf($product, $currency);
        $options = [];

        foreach ($this->optionsOf($product) as $option) {
            $options[] = [
                'id' => $option->id,
                'name' => $option->name,
                'type' => $option->type->value,
                'module_key' => $option->module_key,
                'required' => $option->is_required,
                'quantity_min' => $option->quantity_min,
                'quantity_max' => $option->quantity_max,
                'quantity_step' => $option->quantity_step,
                'values' => array_values($option->values->map(fn (ConfigOptionValue $value): array => [
                    'id' => $value->id,
                    'name' => $value->label,
                    'module_value' => $value->module_value,
                    'pricing' => $this->rowsFor($value->enabledPricesIn($currency), $cycles),
                ])->all()),
            ];
        }

        return $options;
    }

    /**
     * Addons offered in the new-order cart (visible, not retired, shown on order) with pricing in the currency.
     */
    public function addonsFor(int $productId, string $currency): array
    {
        $currency = strtoupper($currency);
        $product = Product::query()
            ->with(['addons' => fn ($query) => $query
                ->where('is_hidden', false)
                ->where('is_retired', false)
                ->where('show_on_order', true)
                ->orderBy('weight')->orderBy('addons.id')
                ->with(['prices' => $this->pricesIn($currency)]),
            ])
            ->find($productId);

        if ($product === null) {
            return [];
        }

        $addons = [];

        foreach ($product->addons as $addon) {
            $pricing = $addon->payment_type === PaymentType::Free
                ? [['cycle' => BillingCycle::Free->value, 'recurring' => 0, 'setup' => 0]]
                : $this->rowsFor($addon->enabledPricesIn($currency), $addon->payment_type->cycles());

            if ($pricing === []) {
                continue;
            }

            $addons[] = [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'payment_type' => $addon->payment_type->value,
                'allow_quantity' => $addon->allow_quantity,
                'pricing' => $pricing,
            ];
        }

        return $addons;
    }

    /**
     * Fields shown on the order form (never admin-only). Extra keys: description, regex (PCRE with delimiters), admin_only, module_key.
     */
    public function customFieldsFor(int $productId): array
    {
        return array_values(ProductCustomField::query()
            ->where('product_id', $productId)
            ->where('is_admin_only', false)
            ->where('show_on_order', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ProductCustomField $field): array => [
                'id' => $field->id,
                'name' => $field->name,
                'type' => $field->type->value,
                'required' => $field->is_required,
                'options' => array_map('strval', $field->options ?? []),
                'description' => $field->description,
                'regex' => $field->validation_regex === null || $field->validation_regex === '' ? null : RegexMatcher::normalise($field->validation_regex),
                'admin_only' => false,
                'module_key' => $field->module_key,
            ])
            ->all());
    }

    /**
     * Atomic conditional decrement (row-locked) so concurrent checkouts can never oversell.
     */
    public function reserveStock(int $productId): bool
    {
        return DB::transaction(function () use ($productId): bool {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first(['id', 'stock_enabled', 'stock_quantity']);

            if ($product === null) {
                return false;
            }

            if (! $product->stock_enabled) {
                return true;
            }

            $reserved = Product::query()
                ->whereKey($productId)
                ->where('stock_enabled', true)
                ->where('stock_quantity', '>', 0)
                ->decrement('stock_quantity') === 1;

            if ($reserved && (int) Product::query()->whereKey($productId)->value('stock_quantity') === 0) {
                DB::afterCommit(fn () => ProductStockDepleted::dispatch($productId));
            }

            return $reserved;
        });
    }

    /**
     * Return stock taken by reserveStock(). No-op for unknown products or when stock control is off.
     */
    public function releaseStock(int $productId, int $quantity = 1): void
    {
        if ($quantity <= 0) {
            return;
        }

        Product::withTrashed()->whereKey($productId)->where('stock_enabled', true)->increment('stock_quantity', $quantity);
    }

    private function summarise(Product $product): ProductSummary
    {
        return new ProductSummary(
            id: $product->id,
            name: $product->name,
            groupName: $product->group->name,
            type: $product->type->value,
            provisioningDriver: $product->provisioning_driver,
            serverGroupId: $product->server_group_id,
            driverConfig: $product->driver_config ?? [],
            autoSetup: $product->auto_setup->summaryValue(),
            welcomeEmail: $product->welcome_email_key,
            requiresDomain: $product->requires_domain,
            taxable: $product->is_taxable,
            stock: $product->stock_enabled ? $product->stock_quantity : null,
            active: ! $product->is_retired,
            paymentType: $product->payment_type->summaryValue(),
            prorata: $product->prorata_enabled,
            prorataDay: $product->prorata_enabled ? $product->prorata_day : null,
            prorataChargeNextMonthDay: $product->prorata_enabled ? $product->prorata_charge_next_month_day : null,
            freeDomain: $product->free_domain !== FreeDomain::None,
            freeDomainTlds: array_map('strval', $product->free_domain_tlds ?? []),
            freeDomainCycles: array_map('strval', $product->free_domain_cycles ?? []),
            freeDomainRecurring: $product->free_domain === FreeDomain::Recurring,
            suspendAfterDays: $product->suspend_days_override,
            terminateAfterDays: $product->terminate_days_override,
            recurringCycleLimit: $product->recurring_cycles_limit > 0 ? $product->recurring_cycles_limit : null,
            termDays: $product->fixed_term_days > 0 ? $product->fixed_term_days : null,
            slug: $product->slug,
            allowedGateways: $product->group->allowed_gateways === null ? null : array_map('strval', $product->group->allowed_gateways),
        );
    }

    private function groupDiscount(int $clientId): float
    {
        $percent = (float) ClientGroup::query()
            ->whereKey(Client::query()->whereKey($clientId)->select('client_group_id'))
            ->value('discount_percent');

        return min(100.0, max(0.0, $percent));
    }

    /**
     * @param  array<int, int>  $selections
     */
    private function quoteProduct(Product $product, BillingCycle $cycle, string $currency, array $selections): ?PriceQuote
    {
        if ($product->payment_type === PaymentType::Free) {
            return PriceCalculator::quote($currency, BillingCycle::Free, [['label' => $product->name, 'recurring' => 0, 'setup' => 0]]);
        }

        if (! in_array($cycle, $product->payment_type->cycles(), true)) {
            return null;
        }

        $base = $product->priceFor($currency, $cycle);

        if ($base === null) {
            return null;
        }

        $lines = [['label' => $product->name, 'recurring' => $base->amount, 'setup' => $base->setup_fee]];

        foreach ($this->optionsOf($product) as $option) {
            $line = $this->optionLine($option, $selections[$option->id] ?? null, $currency, $cycle);

            if ($line === false) {
                return null;
            }

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return PriceCalculator::quote($currency, $cycle, $lines);
    }

    /**
     * @return array{label: string, recurring: int, setup: int}|false|null null = no charge, false = not quotable (unpriced or invalid selection)
     */
    private function optionLine(ConfigOption $option, ?int $selected, string $currency, BillingCycle $cycle): array|false|null
    {
        if ($selected === null && $option->is_hidden) {
            return null;
        }

        if ($option->type === ConfigOptionType::Quantity) {
            $quantity = $selected ?? ($option->quantity_min ?? 0);

            if (! PriceCalculator::isValidQuantity($quantity, $option->quantity_min, $option->quantity_max ?? self::MAX_QUANTITY, $option->quantity_step)) {
                return false;
            }

            if ($quantity === 0) {
                return null;
            }

            $price = $this->valuePrice($option->values->first(), $currency, $cycle);

            if ($price === null) {
                return false;
            }

            return ['label' => "{$option->name} × {$quantity}", ...PriceCalculator::quantityCharge($price->amount, $price->setup_fee, $quantity)];
        }

        if ($option->type === ConfigOptionType::YesNo) {
            if ($selected === null || $selected === 0) {
                return null;
            }

            $value = $option->values->first();
            $price = $this->valuePrice($value, $currency, $cycle);

            return $price === null || $value === null ? false : ['label' => "{$option->name}: {$value->label}", 'recurring' => $price->amount, 'setup' => $price->setup_fee];
        }

        $value = $selected === null
            ? $option->values->first(fn (ConfigOptionValue $value): bool => ! $value->is_hidden)
            : $option->values->firstWhere('id', $selected);

        if ($value === null) {
            return $selected === null ? null : false;
        }

        $price = $this->valuePrice($value, $currency, $cycle);

        return $price === null ? false : ['label' => "{$option->name}: {$value->label}", 'recurring' => $price->amount, 'setup' => $price->setup_fee];
    }

    private function valuePrice(?ConfigOptionValue $value, string $currency, BillingCycle $cycle): ?Price
    {
        return $value?->priceFor($currency, $cycle);
    }

    /**
     * @return list<ConfigOption>
     */
    private function optionsOf(Product $product): array
    {
        $options = [];

        foreach ($product->configOptionGroups->sortBy('id') as $group) {
            foreach ($group->options as $option) {
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * @return list<BillingCycle>
     */
    private function cyclesOf(Product $product, string $currency): array
    {
        if ($product->payment_type === PaymentType::Free) {
            return [BillingCycle::Free];
        }

        return array_values(array_filter(
            array_map(fn (Price $price): BillingCycle => $price->cycle, $product->enabledPricesIn($currency)),
            fn (BillingCycle $cycle): bool => in_array($cycle, $product->payment_type->cycles(), true),
        ));
    }

    /**
     * @return list<array{cycle: string, recurring: int, setup: int, monthly: int|null}>
     */
    private function pricingRows(Product $product, string $currency): array
    {
        if ($product->payment_type === PaymentType::Free) {
            return [['cycle' => BillingCycle::Free->value, 'recurring' => 0, 'setup' => 0, 'monthly' => null]];
        }

        return $this->rowsFor($product->enabledPricesIn($currency), $product->payment_type->cycles());
    }

    /**
     * @param  list<Price>  $prices
     * @param  list<BillingCycle>  $cycles  cycles allowed in the output
     * @return list<array{cycle: string, recurring: int, setup: int, monthly: int|null}>
     */
    private function rowsFor(array $prices, array $cycles): array
    {
        $rows = [];

        foreach ($prices as $price) {
            if (in_array($price->cycle, $cycles, true)) {
                $rows[] = [
                    'cycle' => $price->cycle->value,
                    'recurring' => $price->amount,
                    'setup' => $price->setup_fee,
                    'monthly' => PriceCalculator::monthlyEquivalent($price->amount, $price->cycle),
                ];
            }
        }

        return $rows;
    }

    /**
     * Eager-load constraint: enabled price rows in one currency.
     *
     * @return \Closure(mixed): void
     */
    private function pricesIn(string $currency): \Closure
    {
        $currency = strtoupper($currency);

        return function ($query) use ($currency): void {
            $query->where('currency', $currency)->where('enabled', true);
        };
    }
}
