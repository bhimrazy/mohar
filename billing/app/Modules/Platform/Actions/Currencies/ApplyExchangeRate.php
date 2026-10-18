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
     * @param  string  $source  "manual", a rate source key, "review" or "rebase"
     * @return bool whether the rate changed
     */
    public function handle(Currency $currency, string $rate, string $source, ?CarbonImmutable $effectiveAt = null, ?User $actor = null): bool
    {
        $rate = Decimal::round($rate, 6);

        if (! Decimal::isPositive($rate)) {
            throw new InvalidArgumentException('Exchange rates must be positive.');
        }

        $old = Decimal::round((string) $currency->exchange_rate, 6);

        if (Decimal::compare($old, $rate) === 0) {
            return false;
        }

        DB::transaction(function () use ($currency, $rate, $old, $source, $effectiveAt, $actor): void {
            $now = CarbonImmutable::now();
            $currency->forceFill(['exchange_rate' => $rate, 'rate_updated_at' => $now])->save();

            ExchangeRateHistory::query()->create([
                'currency' => $currency->code,
                'rate' => $rate,
                'source' => $source,
                'effective_at' => $effectiveAt ?? $now,
                'user_id' => $actor?->id ?? auth()->id(),
            ]);

            ExchangeRateReview::query()->pending()->where('currency', $currency->code)
                ->update(['status' => ExchangeRateReview::SUPERSEDED, 'updated_at' => $now]);

            $this->activity->log('Exchange rate changed', $currency, properties: [
                'currency' => $currency->code,
                'old' => $old,
                'new' => $rate,
                'source' => $source,
            ], event: 'currency.rate_changed', actor: $actor);
        });

        return true;
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
