<?php

namespace App\Modules\Domains\Database\Seeders;

use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use Carbon\CarbonImmutable;

class DomainsSeeder extends DemoSeeder
{
    /**
     * Yearly prices in the default currency (minor units): register, transfer, renew. Other currencies are
     * derived from the seeded exchange rates and rounded to retail-style amounts (e.g. ₹1,099.00).
     *
     * @var array<string, array{settings: array<string, mixed>, prices: array{0: int, 1: int, 2: int}}>
     */
    private const TLDS = [
        '.com' => ['settings' => ['is_spotlight' => true, 'badge' => 'hot', 'grace_days' => 30, 'redemption_days' => 30, 'redemption_fee' => 8000, 'sort_order' => 1], 'prices' => [1299, 1199, 1499]],
        '.net' => ['settings' => ['is_spotlight' => true, 'grace_days' => 30, 'redemption_days' => 30, 'redemption_fee' => 8000, 'sort_order' => 2], 'prices' => [1499, 1399, 1699]],
        '.org' => ['settings' => ['is_spotlight' => true, 'grace_days' => 30, 'grace_fee' => 1000, 'redemption_days' => 30, 'redemption_fee' => 8000, 'sort_order' => 3], 'prices' => [1399, 1299, 1599]],
        '.io' => ['settings' => ['is_spotlight' => true, 'badge' => 'new', 'max_years' => 5, 'grace_days' => 30, 'redemption_days' => 30, 'redemption_fee' => 12000, 'sort_order' => 4], 'prices' => [3999, 3899, 4499]],
        '.co.uk' => ['settings' => ['is_spotlight' => true, 'grace_days' => 30, 'redemption_days' => 60, 'redemption_fee' => 5000, 'renew_window_days' => 180, 'sort_order' => 5], 'prices' => [999, 0, 1099]],
    ];

    /** .np second levels: clients register these at the registry themselves; they only use them with hosting. */
    private const SELF_REGISTERED = ['.com.np', '.org.np', '.edu.np', '.net.np'];

    public function run(): void
    {
        RegistrarAccount::query()->firstOrCreate(['key' => 'log'], ['is_active' => true, 'test_mode' => true, 'settings' => ['api_key' => 'demo-key', 'simulate' => 'none']]);

        $tlds = [];

        foreach (self::SELF_REGISTERED as $index => $extension) {
            Tld::query()->firstOrCreate(['extension' => $extension], ['registrar_key' => null, 'registered_by_client' => true, 'auto_register' => false, 'is_active' => true, 'sort_order' => 20 + $index]);
        }

        foreach (self::TLDS as $extension => $definition) {
            $tld = Tld::query()->firstOrCreate(['extension' => $extension], [
                'registrar_key' => 'log',
                'auto_register' => true,
                'epp_required' => true,
                'min_years' => 1,
                'max_years' => 10,
                'allow_register' => true,
                'allow_transfer' => true,
                'addon_dns' => true,
                'addon_id_protection' => true,
                'is_active' => true,
                ...$definition['settings'],
            ]);
            $tlds[$extension] = $tld;

            foreach (Currency::query()->orderBy('code')->get() as $currency) {
                [$register, $transfer, $renew] = array_map(fn (int $amount): int => $this->retail($amount, $currency), $definition['prices']);

                for ($years = 1; $years <= $tld->max_years; $years++) {
                    TldPrice::query()->firstOrCreate(
                        ['tld_id' => $tld->id, 'currency' => $currency->code, 'client_group_id' => null, 'years' => $years],
                        ['register' => $register * $years, 'transfer' => $years === 1 ? $transfer : null, 'renew' => $renew * $years],
                    );
                }
            }
        }

        $demo = Client::query()->where('email', 'demo@example.com')->first();

        if ($demo === null || Domain::query()->where('client_id', $demo->id)->exists()) {
            return;
        }

        $today = CarbonImmutable::today();
        $demoDomains = [
            ['himallabs', '.com', DomainStatus::Active, $today->addDays(21)],
            ['himallabs', '.io', DomainStatus::Active, $today->addMonths(9)],
            ['demo-shop', '.co.uk', DomainStatus::Active, $today->addYears(2)->addMonths(3)],
            ['himallabs', '.net', DomainStatus::Grace, $today->subDays(6)],
            ['demo-archive', '.org', DomainStatus::Redemption, $today->subDays(40)],
            ['demo-old', '.com', DomainStatus::Expired, $today->subMonths(5)],
            ['himallabs', '.org', DomainStatus::Pending, null],
            ['demo-move', '.com', DomainStatus::PendingTransfer, null],
        ];

        foreach ($demoDomains as [$sld, $extension, $status, $expiry]) {
            $this->domain($demo, $tlds[$extension], $sld, $status, $expiry);
        }

        $others = Client::query()->whereKeyNot($demo->id)->orderBy('id')->limit(6)->get();
        $samples = ['northwind', 'globex', 'initech', 'umbrella', 'acme-hosting', 'stark-labs'];

        foreach ($others as $index => $client) {
            $extension = array_keys(self::TLDS)[$index % 4];
            $this->domain($client, $tlds[$extension], $samples[$index] ?? 'client'.$client->id, DomainStatus::Active, $today->addDays(10 + $index * 45));
        }
    }

    /**
     * Convert a default-currency amount and round it to a retail price: amounts of 100 or more major units
     * end in 9 (1091.16 → 1099.00); smaller amounts keep the converted cents.
     */
    private function retail(int $amount, Currency $currency): int
    {
        $converted = $currency->is_default ? $amount : $currency->fromDefault($amount);

        if ($converted < 10000) {
            return $converted;
        }

        return ((int) ceil($converted / 1000) * 10 - 1) * 100;
    }

    private function domain(Client $client, Tld $tld, string $sld, DomainStatus $status, ?CarbonImmutable $expiry): void
    {
        $today = CarbonImmutable::today();
        $name = $sld.$tld->extension;
        $renew = TldPrice::query()->where('tld_id', $tld->id)->where('currency', $client->currency)->whereNull('client_group_id')->where('years', 1)->value('renew') ?? 1499;
        $pending = in_array($status, [DomainStatus::Pending, DomainStatus::PendingTransfer], true);

        $domain = Domain::query()->create([
            'client_id' => $client->id,
            'tld_id' => $tld->id,
            'name' => $name,
            'name_unicode' => $name,
            'sld' => $sld,
            'tld' => $tld->extension,
            'type' => $status === DomainStatus::PendingTransfer ? 'transfer' : 'register',
            'registrar_key' => 'log',
            'status' => $status,
            'registration_date' => $expiry?->subYear(),
            'expiry_date' => $expiry,
            'next_due_date' => $expiry ?? $today,
            'next_invoice_date' => ($expiry ?? $today)->subDays(30),
            'billing_anchor_day' => $expiry?->day,
            'period_years' => 1,
            'first_payment_amount' => (int) $renew,
            'recurring_amount' => (int) $renew,
            'currency' => $client->currency,
            'auto_renew' => $status !== DomainStatus::Expired,
            'id_protection' => $sld === 'himallabs',
            'dns_management' => true,
            'registrar_lock' => $pending ? null : true,
            'nameservers' => ['ns1.example-host.com', 'ns2.example-host.com'],
            'epp_code' => $status === DomainStatus::PendingTransfer ? 'DEMO-EPP-4821' : null,
            'last_synced_at' => null,
            'last_sync_status' => null,
            'registrar_meta' => $status === DomainStatus::PendingTransfer ? ['submitted_at' => now()->subDays(2)->toIso8601String()] : null,
        ]);

        foreach (DomainContact::ROLES as $role) {
            $domain->contacts()->create([
                'role' => $role,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'company' => $client->company_name,
                'email' => mb_strtolower($client->email),
                'phone' => $client->phone,
                'address_1' => $client->address_1,
                'city' => $client->city,
                'state' => $client->state,
                'postcode' => $client->postcode,
                'country' => $client->country,
            ]);
        }
    }
}
