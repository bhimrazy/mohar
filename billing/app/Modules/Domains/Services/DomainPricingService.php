<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\DomainPricing;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Domains\Support\DomainName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * TLD pricing (spec §4.1). Lookup order: client-group slab row, then base row; a missing currency
 * row means the TLD is not sellable in that currency (never converted). Bound as a scoped service,
 * so the memoised catalog lives for one request or job.
 */
class DomainPricingService implements DomainPricing
{
    /** Registries cap registrations at 10 years from today. */
    public const MAX_HORIZON_YEARS = 10;

    /** @var array<string, Tld>|null */
    private ?array $tlds = null;

    /** @var array<int, Collection<int, TldPrice>> */
    private array $prices = [];

    /**
     * @param  'register'|'transfer'|'renew'  $action
     */
    public function price(string $tld, string $action, int $years, string $currency, ?int $clientGroupId = null): ?int
    {
        $model = $this->tld($tld);

        if ($model === null || ! $this->offers($model, $action) || $years < $model->min_years || $years > $model->max_years) {
            return null;
        }

        if ($action === 'transfer' && $years !== $this->transferTerm($model, $currency, $clientGroupId)) {
            return null;
        }

        return $this->amount($model, $action, $years, $currency, $clientGroupId);
    }

    /**
     * Terms (years) offered for an action in a currency; transfers are sold for the lowest term only.
     *
     * @param  'register'|'transfer'|'renew'  $action
     * @return list<int>
     */
    public function terms(string $tld, string $action, string $currency, ?int $clientGroupId = null): array
    {
        $model = $this->tld($tld);

        if ($model === null || ! $this->offers($model, $action)) {
            return [];
        }

        $terms = [];

        for ($years = $model->min_years; $years <= $model->max_years; $years++) {
            if ($this->amount($model, $action, $years, $currency, $clientGroupId) !== null) {
                $terms[] = $years;
            }
        }

        return $action === 'transfer' ? array_slice($terms, 0, 1) : $terms;
    }

    /**
     * Price table for one TLD in a currency: one row per offered term.
     *
     * @return list<array{years: int, register: int|null, transfer: int|null, renew: int|null}>
     */
    public function priceTable(string $tld, string $currency, ?int $clientGroupId = null): array
    {
        $model = $this->tld($tld);

        if ($model === null) {
            return [];
        }

        $rows = [];

        for ($years = $model->min_years; $years <= $model->max_years; $years++) {
            $row = [
                'years' => $years,
                'register' => $this->price($tld, 'register', $years, $currency, $clientGroupId),
                'transfer' => $this->price($tld, 'transfer', $years, $currency, $clientGroupId),
                'renew' => $this->price($tld, 'renew', $years, $currency, $clientGroupId),
            ];

            if ($row['register'] !== null || $row['transfer'] !== null || $row['renew'] !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Renewal terms a client may order for an existing domain at current prices (spec §4.1):
     * expiry + N years must stay within today + 10 years − 1 day, and the renew window must be open.
     *
     * @return list<array{years: int, price: int}>
     */
    public function renewalTerms(Domain $domain, string $currency, ?int $clientGroupId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $expiry = $domain->expiry_date;
        $model = $this->tld($domain->tld);

        if ($expiry === null || $model === null) {
            return [];
        }

        if ($model->renew_window_days !== null && $today->lt($expiry->subDays($model->renew_window_days))) {
            return [];
        }

        $horizon = $today->addYearsNoOverflow(self::MAX_HORIZON_YEARS)->subDay();
        $terms = [];

        foreach ($this->terms($domain->tld, 'renew', $currency, $clientGroupId) as $years) {
            $price = $this->amount($model, 'renew', $years, $currency, $clientGroupId);

            if ($price !== null && $expiry->addYearsNoOverflow($years)->lte($horizon)) {
                $terms[] = ['years' => $years, 'price' => $price];
            }
        }

        return $terms;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function split(string $domain): ?array
    {
        try {
            $ascii = DomainName::toAscii($domain);
        } catch (InvalidArgumentException) {
            return null;
        }

        $labels = explode('.', $ascii);

        for ($index = 1; $index < count($labels); $index++) {
            $extension = '.'.implode('.', array_slice($labels, $index));

            if ($this->tld($extension) !== null) {
                return $index === 1 ? [$labels[0], $extension] : null;
            }
        }

        return null;
    }

    /**
     * The active catalog entry for an extension (".com", "com" or ".CO.UK").
     */
    public function tld(string $extension): ?Tld
    {
        $this->tlds ??= Tld::query()->active()->get()->keyBy('extension')->all();

        return $this->tlds[Tld::normaliseExtension($extension)] ?? null;
    }

    /**
     * Forget memoised catalog data (after admins edit TLDs or prices in the same request).
     */
    public function flush(): void
    {
        $this->tlds = null;
        $this->prices = [];
    }

    private function offers(Tld $tld, string $action): bool
    {
        return match ($action) {
            'register' => $tld->allow_register,
            'transfer' => $tld->allow_transfer,
            'renew' => true,
            default => false,
        };
    }

    private function transferTerm(Tld $tld, string $currency, ?int $clientGroupId): ?int
    {
        for ($years = $tld->min_years; $years <= $tld->max_years; $years++) {
            if ($this->amount($tld, 'transfer', $years, $currency, $clientGroupId) !== null) {
                return $years;
            }
        }

        return null;
    }

    private function amount(Tld $tld, string $action, int $years, string $currency, ?int $clientGroupId): ?int
    {
        $prices = $this->prices[$tld->id] ??= TldPrice::query()->where('tld_id', $tld->id)->get();
        $currency = strtoupper($currency);

        $matching = $prices->filter(fn (TldPrice $price): bool => $price->currency === $currency && $price->years === $years);

        if ($clientGroupId !== null) {
            $groupAmount = $matching->firstWhere('client_group_id', $clientGroupId)?->amountFor($action);

            if ($groupAmount !== null) {
                return $groupAmount;
            }
        }

        return $matching->first(fn (TldPrice $price): bool => $price->client_group_id === null)?->amountFor($action);
    }
}
