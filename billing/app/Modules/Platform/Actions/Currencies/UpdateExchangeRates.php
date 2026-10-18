<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Data\Currencies\RateSnapshot;
use App\Events\Core\ExchangeRatesUpdated;
use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Refresh every currency whose rate source is not "manual" (daily automation, "Update rates now").
 * Each source is fetched once; rates are derived through the default currency with exact decimal maths.
 * A rate that moved more than platform.rates_max_change_percent is not applied: it waits as a review.
 */
class UpdateExchangeRates
{
    public function __construct(
        private ExchangeRateSources $sources,
        private ApplyExchangeRate $apply,
        private ActivityLogger $activity,
        private Settings $settings,
    ) {}

    /**
     * @return array{updated: list<string>, held: list<string>, unchanged: list<string>, failed: array<string, string>}
     */
    public function handle(?User $actor = null): array
    {
        $result = ['updated' => [], 'held' => [], 'unchanged' => [], 'failed' => []];
        $default = Currency::default();

        /** @var Collection<string, Collection<int, Currency>> $bySource */
        $bySource = Currency::query()
            ->where('is_default', false)
            ->where('rate_source', '!=', ExchangeRateSources::MANUAL)
            ->orderBy('code')->orderBy('id')
            ->get()
            ->groupBy('rate_source');

        foreach ($bySource as $key => $currencies) {
            $source = $this->sources->get((string) $key);

            if ($source === null) {
                foreach ($currencies as $currency) {
                    $result['failed'][$currency->code] = __('The rate source ":source" is not available.', ['source' => $key]);
                }

                continue;
            }

            try {
                $snapshot = $source->fetch();
            } catch (ExchangeRateUnavailable $exception) {
                foreach ($currencies as $currency) {
                    $result['failed'][$currency->code] = $exception->getMessage();
                }

                $this->activity->log('Exchange-rate update failed', properties: [
                    'source' => $source->key(),
                    'currencies' => $currencies->pluck('code')->all(),
                    'error' => $exception->getMessage(),
                ], event: 'currency.rate_update_failed', actor: $actor);

                continue;
            }

            foreach ($currencies as $currency) {
                $outcome = $this->updateOne($currency, $default, $snapshot, $actor);

                if ($outcome === null) {
                    $result['failed'][$currency->code] = __(':source does not quote :code against :default.', ['source' => $source->label(), 'code' => $currency->code, 'default' => $default->code]);
                } else {
                    $result[$outcome][] = $currency->code;
                }
            }
        }

        if ($result['updated'] !== []) {
            ExchangeRatesUpdated::dispatch($result['updated'], 'automatic');
        }

        return $result;
    }

    /**
     * @return 'updated'|'held'|'unchanged'|null null when the snapshot lacks the currency or the default
     */
    private function updateOne(Currency $currency, Currency $default, RateSnapshot $snapshot, ?User $actor): ?string
    {
        $proposed = $snapshot->crossRate($default->code, $currency->code);

        if ($proposed === null || ! Decimal::isPositive($proposed)) {
            return null;
        }

        return DB::transaction(function () use ($currency, $proposed, $snapshot, $actor): string {
            $current = Decimal::round((string) $currency->exchange_rate, 6);
            $currency->forceFill(['rate_checked_at' => CarbonImmutable::now()])->save();

            if (Decimal::compare($current, $proposed) === 0) {
                ExchangeRateReview::query()->pending()->where('currency', $currency->code)
                    ->update(['status' => ExchangeRateReview::SUPERSEDED, 'updated_at' => CarbonImmutable::now()]);

                return 'unchanged';
            }

            $change = Decimal::percentChange($current, $proposed);

            if ($this->exceedsLimit($change)) {
                $this->hold($currency, $current, $proposed, $change, $snapshot);

                return 'held';
            }

            $this->apply->handle($currency, $proposed, $snapshot->source, $snapshot->effectiveAt, $actor);

            return 'updated';
        });
    }

    private function exceedsLimit(string $changePercent): bool
    {
        $limit = (string) $this->settings->get('platform.rates_max_change_percent', 10);

        if (! Decimal::isValid($limit) || ! Decimal::isPositive($limit)) {
            return false; // 0 turns the guard off
        }

        return Decimal::compare(Decimal::abs($changePercent), $limit) > 0;
    }

    private function hold(Currency $currency, string $current, string $proposed, string $change, RateSnapshot $snapshot): void
    {
        $pending = ExchangeRateReview::query()->pending()->where('currency', $currency->code)->get();

        // A repeat of the same proposal keeps the open review; anything else replaces it.
        if ($pending->contains(fn (ExchangeRateReview $review): bool => Decimal::compare((string) $review->proposed_rate, $proposed) === 0)) {
            return;
        }

        ExchangeRateReview::query()->whereKey($pending->modelKeys())->update(['status' => ExchangeRateReview::SUPERSEDED, 'updated_at' => CarbonImmutable::now()]);

        ExchangeRateReview::query()->create([
            'currency' => $currency->code,
            'current_rate' => $current,
            'proposed_rate' => $proposed,
            'change_percent' => Decimal::round($change, 4),
            'source' => $snapshot->source,
            'effective_at' => $snapshot->effectiveAt,
            'status' => ExchangeRateReview::PENDING,
        ]);

        $this->activity->log('Exchange-rate change held for review', $currency, properties: [
            'currency' => $currency->code,
            'old' => $current,
            'proposed' => $proposed,
            'change_percent' => Decimal::round($change, 2),
            'source' => $snapshot->source,
        ], event: 'currency.rate_held');
    }
}
