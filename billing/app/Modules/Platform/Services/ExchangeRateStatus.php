<?php

namespace App\Modules\Platform\Services;

use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Support\Currencies\ExchangeRateSources;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Health of automatically sourced exchange rates: which are stale, and the history series for charts.
 */
class ExchangeRateStatus
{
    public function __construct(private Settings $settings) {}

    public function staleAfterDays(): int
    {
        return max(1, (int) $this->settings->get('platform.rates_stale_after_days', 3));
    }

    /**
     * Auto-sourced currencies whose last successful fetch is older than the stale threshold.
     *
     * @return Builder<Currency>
     */
    public function staleQuery(): Builder
    {
        $cutoff = CarbonImmutable::now()->subDays($this->staleAfterDays());

        return Currency::query()
            ->where('is_default', false)
            ->where('rate_source', '!=', ExchangeRateSources::MANUAL)
            ->where(fn (Builder $query) => $query->whereNull('rate_checked_at')->orWhere('rate_checked_at', '<', $cutoff));
    }

    public function isStale(Currency $currency): bool
    {
        if ($currency->is_default || $currency->rate_source === ExchangeRateSources::MANUAL) {
            return false;
        }

        return $currency->rate_checked_at === null || $currency->rate_checked_at->lt(CarbonImmutable::now()->subDays($this->staleAfterDays()));
    }

    /**
     * The rate in force at the end of each of the last $days days (carried forward between changes).
     *
     * @return list<array{x: string, y: float}>
     */
    public function dailySeries(Currency $currency, int $days = 90): array
    {
        $start = CarbonImmutable::today()->subDays($days - 1);
        $history = ExchangeRateHistory::query()->where('currency', $currency->code)
            ->where('effective_at', '>=', $start)
            ->orderBy('effective_at')->orderBy('id')
            ->get(['rate', 'effective_at']);
        $before = ExchangeRateHistory::query()->where('currency', $currency->code)
            ->where('effective_at', '<', $start)
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->value('rate');

        if ($history->isEmpty() && $before === null) {
            return [];
        }

        $rate = $before ?? $history->first()?->rate;
        $points = [];
        $index = 0;

        for ($day = $start; $day->lte(CarbonImmutable::today()); $day = $day->addDay()) {
            while ($index < $history->count() && $history[$index]->effective_at->lt($day->addDay())) {
                $rate = $history[$index]->rate;
                $index++;
            }

            // Floats only for drawing the chart; stored rates stay exact decimals.
            $points[] = ['x' => $day->toDateString(), 'y' => (float) $rate];
        }

        return $points;
    }
}
