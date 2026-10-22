<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Enums\BillingCycle;
use App\Modules\Catalog\Enums\AddonKind;
use App\Modules\Catalog\Enums\AutoSetup;
use App\Modules\Catalog\Enums\ConfigOptionType;
use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Enums\FreeDomain;
use App\Modules\Catalog\Enums\PaymentType;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Currency;

/**
 * Demo catalog: shared hosting, VPS with configurable resources, SSL certificates and addons, priced in
 * every configured currency (USD/INR/NPR on a fresh install) from USD base prices × exchange rate.
 * Products provision with the Services "log" driver (or "none"); gateway restrictions use Billing's keys.
 * Safe to re-run.
 */
class CatalogSeeder extends DemoSeeder
{
    /** @var list<Currency> */
    private array $currencies = [];

    private ?Currency $usd = null;

    /** Cycle => [months, discount factor]. */
    private const CYCLES = [
        'monthly' => [1, 1.0],
        'quarterly' => [3, 0.97],
        'semiannually' => [6, 0.95],
        'annually' => [12, 0.85],
        'biennially' => [24, 0.8],
        'triennially' => [36, 0.75],
    ];

    public function run(): void
    {
        if (ProductGroup::query()->where('slug', 'shared-hosting')->exists()) {
            return;
        }

        $default = Currency::default();
        $this->currencies = [$default, ...Currency::query()->where('code', '!=', $default->code)->orderBy('code')->get()->all()];

        $hosting = $this->group('Shared Hosting', 'shared-hosting', 1, 'Fast, secure hosting for every website', null);
        $vps = $this->group('VPS', 'vps', 2, 'Root access on dedicated resources', ['bank_transfer', 'sandbox_card']);
        $ssl = $this->group('SSL Certificates', 'ssl-certificates', 3, 'Encrypt traffic and earn the padlock', null);
        $this->group('Legacy Plans', 'legacy-plans', 4, null, null, hidden: true);

        $starter = $this->product($hosting, 'Starter', 'starter', 399, ['monthly', 'annually', 'biennially'], [
            'tagline' => '1 website, 10 GB SSD',
            'provisioning_driver' => 'log',
            'driver_config' => ['package' => 'starter', 'disk_quota' => '10240'],
            'welcome_email_key' => 'hosting.welcome',
            'requires_domain' => true,
        ]);
        $pro = $this->product($hosting, 'Pro', 'pro', 799, ['monthly', 'annually', 'biennially'], [
            'tagline' => '10 websites, 50 GB SSD, free domain',
            'is_featured' => true,
            'provisioning_driver' => 'log',
            'driver_config' => ['package' => 'pro', 'disk_quota' => '51200'],
            'welcome_email_key' => 'hosting.welcome',
            'requires_domain' => true,
            'free_domain' => FreeDomain::Once,
            'free_domain_cycles' => ['annually', 'biennially'],
            'free_domain_tlds' => ['.com', '.net', '.org'],
        ], setupFee: 0);
        $business = $this->product($hosting, 'Business', 'business', 1499, ['monthly', 'quarterly', 'annually', 'biennially', 'triennially'], [
            'tagline' => 'Unlimited websites, 200 GB NVMe, daily backups',
            'provisioning_driver' => 'log',
            'driver_config' => ['package' => 'business', 'disk_quota' => '204800'],
            'welcome_email_key' => 'hosting.welcome',
            'requires_domain' => true,
            'prorata_enabled' => true,
            'prorata_day' => 1,
            'prorata_charge_next_month_day' => 20,
        ]);

        $vpsSmall = $this->product($vps, 'VPS S', 'vps-s', 1200, ['monthly', 'quarterly', 'annually'], [
            'type' => ProductType::Server,
            'tagline' => '1 vCPU · 2 GB RAM · 40 GB NVMe',
            'provisioning_driver' => 'log',
            'driver_config' => ['template' => 'debian-12', 'storage' => 'nvme'],
            'welcome_email_key' => 'vps.welcome',
            'auto_setup' => AutoSetup::OnOrder,
        ], setupFee: 500);
        $vpsMedium = $this->product($vps, 'VPS M', 'vps-m', 2400, ['monthly', 'quarterly', 'annually'], [
            'type' => ProductType::Server,
            'tagline' => '2 vCPU · 4 GB RAM · 80 GB NVMe',
            'provisioning_driver' => 'log',
            'driver_config' => ['template' => 'debian-12', 'storage' => 'nvme'],
            'welcome_email_key' => 'vps.welcome',
            'stock_enabled' => true,
            'stock_quantity' => 25,
        ], setupFee: 500);

        $this->product($ssl, 'Domain Validated SSL', 'ssl-dv', 2900, ['onetime'], [
            'type' => ProductType::Other,
            'payment_type' => PaymentType::OneTime,
            'tagline' => 'Issued in minutes, valid for one year',
            'auto_setup' => AutoSetup::OnPayment,
            'requires_domain' => true,
        ]);
        $this->product($hosting, 'Hosting Trial', 'hosting-trial', 0, [], [
            'payment_type' => PaymentType::Free,
            'tagline' => '14-day free trial',
            'is_hidden' => true,
            'fixed_term_days' => 14,
            'auto_setup' => AutoSetup::OnAccept,
            'provisioning_driver' => 'none',
        ]);
        $this->product($hosting, 'Classic 2019', 'classic-2019', 599, ['monthly', 'annually'], ['is_retired' => true]);

        $starter->upgradeTargets()->sync([$pro->id, $business->id]);
        $pro->upgradeTargets()->sync([$starter->id, $business->id]);
        $business->upgradeTargets()->sync([$pro->id]);
        $vpsSmall->upgradeTargets()->sync([$vpsMedium->id]);
        $vpsMedium->upgradeTargets()->sync([$vpsSmall->id]);
        $vpsSmall->update(['allow_config_option_upgrades' => true]);
        $vpsMedium->update(['allow_config_option_upgrades' => true]);

        $resources = $this->vpsResources();
        $vpsSmall->configOptionGroups()->sync([$resources->id]);
        $vpsMedium->configOptionGroups()->sync([$resources->id]);

        foreach ([$vpsSmall, $vpsMedium] as $server) {
            $server->customFields()->createMany([
                ['name' => 'Hostname', 'module_key' => 'hostname', 'type' => CustomFieldType::Text, 'description' => 'Fully qualified, e.g. server1.example.com', 'validation_regex' => '/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'is_required' => true, 'show_on_order' => true, 'sort_order' => 1],
                ['name' => 'Operating system', 'module_key' => 'os', 'type' => CustomFieldType::Dropdown, 'options' => ['Debian 12', 'Ubuntu 24.04', 'AlmaLinux 9'], 'is_required' => true, 'show_on_order' => true, 'show_on_invoice' => true, 'sort_order' => 2],
                ['name' => 'Internal notes', 'type' => CustomFieldType::Textarea, 'is_admin_only' => true, 'show_on_order' => false, 'sort_order' => 3],
            ]);
        }

        $backups = Addon::query()->create([
            'name' => 'Daily Backups', 'description' => 'Off-site backups kept for 30 days with one-click restore.',
            'kind' => AddonKind::Feature, 'payment_type' => PaymentType::Recurring, 'auto_setup' => AutoSetup::OnPayment,
            'provisioning_driver' => 'none', 'driver_config' => ['retention_days' => '30'], 'weight' => 1, 'prorata_with_parent' => true,
        ]);
        $this->prices($backups, 299, ['monthly', 'quarterly', 'annually']);
        $backups->products()->sync([$starter->id, $pro->id, $business->id, $vpsSmall->id, $vpsMedium->id]);

        $ip = Addon::query()->create([
            'name' => 'Dedicated IPv4', 'description' => 'A dedicated IPv4 address for your service.',
            'kind' => AddonKind::Independent, 'payment_type' => PaymentType::Recurring, 'auto_setup' => AutoSetup::OnPayment,
            'provisioning_driver' => 'none', 'weight' => 2, 'allow_quantity' => true,
        ]);
        $this->prices($ip, 300, ['annually']);
        $ip->products()->sync([$pro->id, $business->id]);

        $migration = Addon::query()->create([
            'name' => 'Website Migration', 'description' => 'We move your existing site for you.',
            'kind' => AddonKind::Independent, 'payment_type' => PaymentType::OneTime, 'auto_setup' => AutoSetup::Manual,
            'provisioning_driver' => 'none', 'weight' => 3, 'show_on_order' => true,
        ]);
        $this->prices($migration, 4900, ['onetime']);
        $migration->products()->sync([$starter->id, $pro->id, $business->id]);
    }

    /**
     * @param  list<string>|null  $gateways
     */
    private function group(string $name, string $slug, int $order, ?string $headline, ?array $gateways, bool $hidden = false): ProductGroup
    {
        return ProductGroup::query()->create([
            'name' => $name, 'slug' => $slug, 'sort_order' => $order, 'headline' => $headline,
            'allowed_gateways' => $gateways, 'is_hidden' => $hidden,
        ]);
    }

    /**
     * @param  list<string>  $cycles
     * @param  array<string, mixed>  $attributes
     */
    private function product(ProductGroup $group, string $name, string $slug, int $usdMonthly, array $cycles, array $attributes = [], int $setupFee = 0): Product
    {
        $product = Product::query()->create([
            'product_group_id' => $group->id,
            'type' => ProductType::SharedHosting,
            'name' => $name,
            'slug' => $slug,
            'description' => $attributes['tagline'] ?? null,
            'sort_order' => (int) Product::query()->where('product_group_id', $group->id)->max('sort_order') + 1,
            'payment_type' => PaymentType::Recurring,
            'auto_setup' => AutoSetup::OnPayment,
            'is_taxable' => true,
            'driver_config' => [],
            ...$attributes,
        ]);

        $this->prices($product, $usdMonthly, $cycles, $setupFee);

        return $product;
    }

    /**
     * Price rows in every configured currency: USD monthly base × months × cycle discount, converted with the
     * currency's exchange rate and (for products/addons) rounded to a familiar price point.
     *
     * @param  list<string>  $cycles
     */
    private function prices(Product|Addon|ConfigOptionValue $priceable, int $usdMonthly, array $cycles, int $setupFee = 0, bool $charm = true): void
    {
        foreach ($this->currencies as $currency) {
            foreach ($cycles as $cycle) {
                [$months, $discount] = $cycle === 'onetime' ? [1, 1.0] : self::CYCLES[$cycle];
                $amount = $this->fromUsd((int) round($usdMonthly * $months * $discount), $currency);

                $priceable->prices()->create([
                    'currency' => $currency->code,
                    'cycle' => BillingCycle::from($cycle),
                    'enabled' => true,
                    'amount' => $charm ? $this->pricePoint($amount) : $amount,
                    'setup_fee' => $this->fromUsd($setupFee, $currency),
                ]);
            }
        }
    }

    /**
     * 3.83 → 3.99; 331.17 → 339.00 (whole units ending in 9 from 100 upwards).
     */
    /**
     * The base prices are US dollars; the install's default currency may be another one (NPR on the Nepal demo).
     */
    private function fromUsd(int $amount, Currency $currency): int
    {
        $usd = $this->usd ??= Currency::query()->where('code', 'USD')->first();

        return $usd === null || $usd->code === $currency->code ? $amount : $usd->convertTo($amount, $currency);
    }

    private function pricePoint(int $amount): int
    {
        if ($amount < 100) {
            return $amount;
        }

        $units = (int) ceil($amount / 100);

        return $units >= 100 ? ((int) ceil(($units + 1) / 10) * 10 - 1) * 100 : $units * 100 - 1;
    }

    private function vpsResources(): ConfigOptionGroup
    {
        $group = ConfigOptionGroup::query()->create(['name' => 'VPS resources', 'description' => 'Memory, CPU and network add-ons for VPS plans.']);
        $cycles = ['monthly', 'quarterly', 'annually'];

        $ram = $this->option($group, 'Memory', 'ram', ConfigOptionType::Dropdown, 1, ['is_required' => true]);
        foreach ([['Included', '0', 0], ['+2 GB', '2048', 500], ['+6 GB', '6144', 1200]] as $index => [$label, $value, $price]) {
            $this->prices($ram->values()->create(['label' => $label, 'module_value' => $value, 'sort_order' => $index + 1]), $price, $cycles, charm: false);
        }
        $this->prices($ram->values()->create(['label' => '+14 GB (discontinued)', 'module_value' => '14336', 'is_hidden' => true, 'sort_order' => 9]), 2200, $cycles, charm: false);

        $cpu = $this->option($group, 'CPU cores', 'cores', ConfigOptionType::Radio, 2);
        foreach ([['Included', '0', 0], ['+1 core', '1', 400], ['+3 cores', '3', 1000]] as $index => [$label, $value, $price]) {
            $this->prices($cpu->values()->create(['label' => $label, 'module_value' => $value, 'sort_order' => $index + 1]), $price, $cycles, charm: false);
        }

        $ips = $this->option($group, 'Extra IPv4 addresses', 'extra_ips', ConfigOptionType::Quantity, 3, ['quantity_min' => 0, 'quantity_max' => 8]);
        $this->prices($ips->values()->create(['label' => 'Unit']), 200, $cycles, charm: false);

        $managed = $this->option($group, 'Managed support', 'managed', ConfigOptionType::YesNo, 4);
        $this->prices($managed->values()->create(['label' => 'Yes', 'module_value' => '1']), 1500, $cycles, charm: false);

        return $group;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function option(ConfigOptionGroup $group, string $name, string $key, ConfigOptionType $type, int $order, array $attributes = []): ConfigOption
    {
        return $group->options()->create(['name' => $name, 'module_key' => $key, 'type' => $type, 'sort_order' => $order, ...$attributes]);
    }
}
