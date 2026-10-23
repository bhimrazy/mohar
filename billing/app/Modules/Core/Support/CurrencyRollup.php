<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Currency;
use App\Support\Currencies\MoneyFormatter;

/**
 * Consolidates per-currency amounts (minor units) into the default currency at today's rates, for dashboard
 * "All currencies" figures. Amounts in a currency that is not configured are left out of totals rather than
 * mixed in unconverted.
 */
final class CurrencyRollup
{
    private Currency $default;

    /** @var array<string, Currency> */
    private array $currencies;

    public function __construct()
    {
        $this->default = Currency::default();
        $this->currencies = Currency::query()->get()->keyBy('code')->all();
    }

    public function defaultCode(): string
    {
        return $this->default->code;
    }

    /**
     * Display name of a currency ("Indian Rupee"), or the code when unknown.
     */
    public function name(string $code): string
    {
        return $this->currencies[$code]->name ?? $code;
    }

    /**
     * Formats minor units with the currency's configured display (MoneyFormatter, the single source).
     */
    public function format(int $amount, string $code): string
    {
        return app(MoneyFormatter::class)->amount($amount, $code);
    }

    public function toDefault(int $amount, string $code): ?int
    {
        if ($code === $this->default->code) {
            return $amount;
        }

        $currency = $this->currencies[$code] ?? null;

        return $currency === null ? null : $currency->convertTo($amount, $this->default);
    }

    /**
     * toDefault() for many amounts of one currency, with the rate arithmetic done once. Null when the currency is
     * not configured (its amounts are left out of totals).
     *
     * @return (\Closure(int): int)|null
     */
    public function converter(string $code): ?\Closure
    {
        if ($code === $this->default->code) {
            return fn (int $amount): int => $amount;
        }

        return ($this->currencies[$code] ?? null)?->converterTo($this->default);
    }

    /**
     * @param  array<string, int>  $byCurrency
     */
    public function total(array $byCurrency): int
    {
        $total = 0;

        foreach ($byCurrency as $code => $amount) {
            $total += $this->toDefault($amount, (string) $code) ?? 0;
        }

        return $total;
    }

    /**
     * Point-wise total of aligned per-currency series.
     *
     * @param  array<string, list<int>>  $series
     * @return list<int>
     */
    public function totalSeries(array $series, int $length): array
    {
        $total = array_fill(0, $length, 0);

        foreach ($series as $code => $points) {
            foreach ($points as $index => $amount) {
                $total[$index] += $this->toDefault($amount, (string) $code) ?? 0;
            }
        }

        return array_values($total);
    }

    /**
     * Every configured currency plus any extra codes seen in data, default first: the reporting-currency options.
     * Widgets send a variant for each, so the default currency always has a view even with no activity.
     *
     * @param  iterable<string>  $seen
     * @return list<string>
     */
    public function codes(iterable $seen = []): array
    {
        return $this->order([...array_keys($this->currencies), $this->default->code, ...$seen]);
    }

    /**
     * Default currency first, then the rest alphabetically.
     *
     * @param  iterable<string>  $codes
     * @return list<string>
     */
    public function order(iterable $codes): array
    {
        $codes = array_values(array_unique([...$codes]));
        usort($codes, fn (string $a, string $b): int => [$a !== $this->default->code, $a] <=> [$b !== $this->default->code, $b]);

        return $codes;
    }
}
