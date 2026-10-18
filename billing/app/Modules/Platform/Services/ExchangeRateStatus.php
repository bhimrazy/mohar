<?php

namespace App\Modules\Platform\Services;

use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Support\Currencies\ExchangeRateSources;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Health of exchange rates: which are stale (automatic: no successful fetch for N days; manual: not changed
 * for M days), and the history series for charts.
 */
class ExchangeRateStatus
{
    public function __construct(private Settings $settings) {}

    public function staleAfterDays(): int
    {
        return max(1, (int) $this->settings->get('platform.rates_stale_after_days', 3));
    }

    public function manualStaleAfterDays(): int
    {
        return max(1, (int) $this->settings->get('platform.rates_manual_stale_after_days', 30));
    }

    /**
     * Non-default currencies whose rate is stale: automatic ones without a successful fetch within
     * staleAfterDays(), manual ones not changed within manualStaleAfterDays().
     *
     * @return Builder<Currency>
     */
    public function staleQuery(): Builder
    {
        $autoCutoff = CarbonImmutable::now()->subDays($this->staleAfterDays());
        $manualCutoff = CarbonImmutable::now()->subDays($this->manualStaleAfterDays());

        return Currency::query()
            ->where('is_default', false)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $auto) => $auto
                    ->where('rate_source', '!=', ExchangeRateSources::MANUAL)
                    ->where(fn (Builder $old) => $old->whereNull('rate_checked_at')->orWhere('rate_checked_at', '<', $autoCutoff)))
                ->orWhere(fn (Builder $manual) => $manual
                    ->where('rate_source', ExchangeRateSources::MANUAL)
                    ->where(fn (Builder $old) => $old->where('rate_updated_at', '<', $manualCutoff)
                        ->orWhere(fn (Builder $never) => $never->whereNull('rate_updated_at')->where('created_at', '<', $manualCutoff)))));
    }

    public function isStale(Currency $currency): bool
    {
        if ($currency->is_default) {
            return false;
        }

        // A manual rate never edited since the currency was created counts from its creation.
        [$timestamp, $days] = $currency->rate_source === ExchangeRateSources::MANUAL
            ? [$currency->rate_updated_at ?? $currency->created_at, $this->manualStaleAfterDays()]
            : [$currency->rate_checked_at, $this->staleAfterDays()];

        return $timestamp === null || $timestamp->lt(CarbonImmutable::now()->subDays($days));
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

    /**
     * The latest rate changes, newest first: when, old → new, source and who (null = automatic).
     *
     * @return list<array{effective_at: string, old: string|null, new: string, source: string, by: string|null}>
     */
    public function recentChanges(Currency $currency, int $limit = 8): array
    {
        $rows = ExchangeRateHistory::query()->with('user:id,name')->where('currency', $currency->code)
            ->orderByDesc('effective_at')->orderByDesc('id')->limit($limit + 1)->get();
        $changes = [];

        foreach ($rows->take($limit)->values() as $index => $row) {
            $previous = $rows->get($index + 1);
            $changes[] = [
                'effective_at' => $row->effective_at->toIso8601String(),
                'old' => $previous !== null ? (string) $previous->rate : null,
                'new' => (string) $row->rate,
                'source' => $row->source,
                'by' => $row->user?->name,
            ];
        }

        return $changes;
    }
}
