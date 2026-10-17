<?php

namespace App\Modules\Orders\Support\Demo;

use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Data\Services\ProvisionResult;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * LOCAL DEMO ONLY: TLD prices and a no-op registry until the Domains module is merged.
 */
class DemoDomains implements DomainPricing, DomainRegistry
{
    /** @var array<string, array{register: int, transfer: int, renew: int}> USD minor units per year */
    private const TLDS = [
        '.com' => ['register' => 1299, 'transfer' => 1099, 'renew' => 1499],
        '.net' => ['register' => 1399, 'transfer' => 1199, 'renew' => 1599],
        '.org' => ['register' => 1199, 'transfer' => 1099, 'renew' => 1399],
        '.io' => ['register' => 3900, 'transfer' => 3900, 'renew' => 4500],
        '.dev' => ['register' => 1500, 'transfer' => 1500, 'renew' => 1700],
        '.co.uk' => ['register' => 899, 'transfer' => 0, 'renew' => 999],
    ];

    public function price(string $tld, string $action, int $years, string $currency): ?int
    {
        $price = self::TLDS[strtolower($tld)][$action] ?? null;

        if ($price === null || $years < 1 || $years > 10) {
            return null;
        }

        $rate = (float) (Currency::query()->where('code', $currency)->value('exchange_rate') ?? 1);

        return (int) round($price * $years * $rate);
    }

    public function split(string $domain): ?array
    {
        $domain = strtolower($domain);
        $tlds = array_keys(self::TLDS);
        usort($tlds, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tlds as $tld) {
            if (str_ends_with($domain, $tld) && strlen($domain) > strlen($tld)) {
                return [substr($domain, 0, -strlen($tld)), $tld];
            }
        }

        return null;
    }

    public function createPending(DomainDraft $draft): int
    {
        return self::nextId('orders.demo.domain_id');
    }

    public function process(int $domainId): ProvisionResult
    {
        return new ProvisionResult(true, 'Submitted to the demo registrar.');
    }

    public function cancelPending(int $domainId, string $status = 'cancelled'): void {}

    /**
     * Sequential demo id (Cache::increment returns false for a missing key on some stores).
     */
    private static function nextId(string $key): int
    {
        Cache::add($key, 0);

        return (int) Cache::increment($key);
    }
}
