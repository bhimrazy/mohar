<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Contracts\Currencies\ExchangeRateSource;
use App\Data\Currencies\RateSnapshot;
use App\Events\Core\ExchangeRatesUpdated;
use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Support\ExchangeRateLock;
use App\Support\Currencies\Decimal;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Currencies\ExchangeRateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refresh every currency whose rate source is not "manual" (daily automation, "Update rates now").
 * Each source is fetched once; rates are derived through the default currency with exact decimal maths.
 * A rate that moved more than platform.rates_max_change_percent is not applied: it waits as a review.
 * Runs under ExchangeRateLock, and each currency row is locked and re-read before old → new is computed.
 */
class UpdateExchangeRates
{
    /** Plausible units per 1 default unit; anything outside is treated as a garbled feed. */
    public const MIN_RATE = '0.000000001';

    public const MAX_RATE = '1000000000';

    /** Largest change stored on a review (the column is decimal(10,4)). */
    private const MAX_CHANGE_PERCENT = '999999.9999';

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
        $result = ExchangeRateLock::run(fn (): array => $this->run($actor));

        if ($result['updated'] !== []) {
            ExchangeRatesUpdated::dispatch($result['updated'], 'automatic');
        }

        return $result;
    }

    /**
     * @return array{updated: list<string>, held: list<string>, unchanged: list<string>, failed: array<string, string>}
     */
    private function run(?User $actor): array
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
                    $result['failed'][$currency->code] = self::text('The rate source ":source" is not available.', ['source' => $key]);
                }

                continue;
            }

            $snapshot = $this->fetch($source, $currencies, $actor);

            if (is_string($snapshot)) {
                foreach ($currencies as $currency) {
                    $result['failed'][$currency->code] = $snapshot;
                }

                continue;
            }

            foreach ($currencies as $currency) {
                [$outcome, $error] = $this->updateOne($currency, $default, $snapshot, $source, $actor);

                if ($outcome === 'failed') {
                    $result['failed'][$currency->code] = (string) $error;
                } else {
                    $result[$outcome][] = $currency->code;
                }
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, Currency>  $currencies
     * @return RateSnapshot|string the snapshot, or the failure message (already audit-logged)
     */
    private function fetch(ExchangeRateSource $source, Collection $currencies, ?User $actor): RateSnapshot|string
    {
        try {
            return $source->fetch();
        } catch (ExchangeRateUnavailable $exception) {
            $message = $exception->getMessage();
        } catch (Throwable $exception) {
            // A garbled response must not abort the run or reach the page as a 500.
            report($exception);
            $message = self::text(':source returned data that could not be read.', ['source' => $source->label()]);
        }

        $this->activity->log('Exchange-rate update failed', properties: [
            'source' => $source->key(),
            'currencies' => $currencies->pluck('code')->all(),
            'error' => $message,
        ], event: 'currency.rate_update_failed', actor: $actor);

        return $message;
    }

    /**
     * @return array{0: 'updated'|'held'|'unchanged'|'failed', 1: string|null}
     */
    private function updateOne(Currency $currency, Currency $default, RateSnapshot $snapshot, ExchangeRateSource $source, ?User $actor): array
    {
        try {
            $proposed = $snapshot->crossRate($default->code, $currency->code);
        } catch (Throwable $exception) {
            report($exception);
            $proposed = null;
        }

        if ($proposed === null) {
            return ['failed', $this->failure($source, $currency, self::text(':source does not quote :code against :default.', ['source' => $source->label(), 'code' => $currency->code, 'default' => $default->code]), $actor)];
        }

        if (Decimal::compare($proposed, self::MIN_RATE) < 0 || Decimal::compare($proposed, self::MAX_RATE) > 0) {
            return ['failed', $this->failure($source, $currency, self::text(':source published an implausible :code rate; the current rate was kept.', ['source' => $source->label(), 'code' => $currency->code]), $actor, $proposed)];
        }

        return DB::transaction(function () use ($currency, $default, $proposed, $snapshot, $source, $actor): array {
            $locked = Currency::query()->whereKey($currency->id)->lockForUpdate()->first();

            // The rate was derived against $default; if the default changed meanwhile it no longer applies.
            if ($locked === null || $locked->is_default || Currency::default()->code !== $default->code) {
                return ['failed', self::text('The default currency changed during the update; run it again.')];
            }

            $current = Decimal::round((string) $locked->exchange_rate, Currency::RATE_SCALE);
            $locked->forceFill(['rate_checked_at' => CarbonImmutable::now()])->save();

            if (ApplyExchangeRate::isNegligible($current, $proposed)) {
                ExchangeRateReview::closePending($locked->code);

                return ['unchanged', null];
            }

            $change = Decimal::percentChange($current, $proposed);

            if ($this->exceedsLimit($change)) {
                $this->hold($locked, $current, $proposed, $change, $snapshot);

                return ['held', null];
            }

            $this->apply->handle($locked, $proposed, $source->key(), $snapshot->effectiveAt, $actor);

            return ['updated', null];
        });
    }

    private function failure(ExchangeRateSource $source, Currency $currency, string $message, ?User $actor, ?string $value = null): string
    {
        $this->activity->log('Exchange-rate update failed', $currency, properties: array_filter([
            'source' => $source->key(),
            'currencies' => [$currency->code],
            'error' => $message,
            'value' => $value,
        ]), event: 'currency.rate_update_failed', actor: $actor);

        return $message;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    private function exceedsLimit(string $changePercent): bool
    {
        $limit = (string) $this->settings->get('platform.rates_max_change_percent', 10);

        if (! Decimal::isValid($limit) || ! Decimal::isPositive($limit)) {
            return false; // 0 turns the guard off
        }

        return Decimal::compare(Decimal::abs($changePercent), $limit) > 0;
    }

    /**
     * Upsert the one pending review of the currency (unique pending_currency): a repeat of the same proposal
     * keeps it; a different proposal replaces it in place.
     */
    private function hold(Currency $currency, string $current, string $proposed, string $change, RateSnapshot $snapshot): void
    {
        $change = Decimal::round($change, 4);

        if (Decimal::compare(Decimal::abs($change), self::MAX_CHANGE_PERCENT) > 0) {
            $change = Decimal::isPositive($change) ? self::MAX_CHANGE_PERCENT : '-'.self::MAX_CHANGE_PERCENT;
        }

        $review = ExchangeRateReview::query()->where('pending_currency', $currency->code)->lockForUpdate()->first();

        if ($review !== null && Decimal::compare((string) $review->proposed_rate, $proposed) === 0) {
            return;
        }

        ExchangeRateReview::query()->updateOrCreate(['pending_currency' => $currency->code], [
            'currency' => $currency->code,
            'current_rate' => $current,
            'proposed_rate' => $proposed,
            'change_percent' => $change,
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
