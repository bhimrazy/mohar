<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Support\Currencies\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Put a new exchange rate into force for one currency: update the currency, append to the rate history,
 * supersede any pending review and write the audit entry (who, source, old → new). Callers dispatch
 * ExchangeRatesUpdated once per batch.
 */
class ApplyExchangeRate
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * The row is locked and re-read inside the transaction, so "old" is the rate actually in force and a
     * concurrent change of the same rate is not recorded twice. The given model is refreshed afterwards.
     *
     * @param  string  $source  "manual", a rate source key, "review" or "rebase"
     * @return bool whether the rate changed
     */
    public function handle(Currency $currency, string $rate, string $source, ?CarbonImmutable $effectiveAt = null, ?User $actor = null): bool
    {
        $rate = Decimal::round($rate, 6);

        if (! Decimal::isPositive($rate)) {
            throw new InvalidArgumentException('Exchange rates must be positive.');
        }

        $changed = DB::transaction(function () use ($currency, $rate, $source, $effectiveAt, $actor): bool {
            $locked = Currency::query()->whereKey($currency->id)->lockForUpdate()->firstOrFail();
            $old = Decimal::round((string) $locked->exchange_rate, 6);

            if (Decimal::compare($old, $rate) === 0) {
                return false;
            }

            $now = CarbonImmutable::now();
            $locked->forceFill(['exchange_rate' => $rate, 'rate_updated_at' => $now])->save();

            ExchangeRateHistory::query()->create([
                'currency' => $locked->code,
                'rate' => $rate,
                'source' => $source,
                'effective_at' => $effectiveAt ?? $now,
                'user_id' => $actor !== null ? $actor->id : auth()->id(),
            ]);

            ExchangeRateReview::closePending($locked->code);

            $this->activity->log('Exchange rate changed', $locked, properties: [
                'currency' => $locked->code,
                'old' => $old,
                'new' => $rate,
                'source' => $source,
            ], event: 'currency.rate_changed', actor: $actor);

            return true;
        });

        $currency->refresh();

        return $changed;
    }

    /**
     * The first history row for a currency (created with a starting rate, or before history existed).
     */
    public function seedHistory(Currency $currency, string $source = 'manual'): void
    {
        if (ExchangeRateHistory::query()->where('currency', $currency->code)->exists()) {
            return;
        }

        ExchangeRateHistory::query()->create([
            'currency' => $currency->code,
            'rate' => Decimal::round((string) $currency->exchange_rate, 6),
            'source' => $source,
            'effective_at' => $currency->rate_updated_at ?? CarbonImmutable::now(),
            'user_id' => auth()->id(),
        ]);
    }
}
