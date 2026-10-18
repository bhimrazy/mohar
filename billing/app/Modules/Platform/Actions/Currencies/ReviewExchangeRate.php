<?php

namespace App\Modules\Platform\Actions\Currencies;

use App\Events\Core\ExchangeRatesUpdated;
use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Support\ExchangeRateLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff decision on a held exchange-rate change: approve puts the proposed rate into force, reject keeps
 * the current one. Both are audit-logged.
 */
class ReviewExchangeRate
{
    public function __construct(private ApplyExchangeRate $apply, private ActivityLogger $activity) {}

    public function approve(ExchangeRateReview $review, User $user): void
    {
        $changed = ExchangeRateLock::run(fn (): bool => DB::transaction(function () use ($review, $user): bool {
            $review = $this->lockPending($review);
            $currency = Currency::query()->where('code', $review->currency)->lockForUpdate()->first();

            if ($currency === null || $currency->is_default) {
                throw ValidationException::withMessages(['review' => __('This currency no longer takes an exchange rate.')]);
            }

            $review->update(['status' => ExchangeRateReview::APPROVED, 'pending_currency' => null, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()]);
            $this->activity->log('Exchange-rate change approved', $currency, properties: [
                'currency' => $review->currency,
                'old' => $review->current_rate,
                'new' => $review->proposed_rate,
                'source' => $review->source,
            ], event: 'currency.rate_review_approved', actor: $user);

            return $this->apply->handle($currency, (string) $review->proposed_rate, $review->source, $review->effective_at, $user);
        }));

        if ($changed) {
            ExchangeRatesUpdated::dispatch([$review->currency], 'review');
        }
    }

    public function reject(ExchangeRateReview $review, User $user): void
    {
        ExchangeRateLock::run(fn () => DB::transaction(function () use ($review, $user): void {
            $review = $this->lockPending($review);
            $review->update(['status' => ExchangeRateReview::REJECTED, 'pending_currency' => null, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()]);

            $this->activity->log('Exchange-rate change rejected', properties: [
                'currency' => $review->currency,
                'current' => $review->current_rate,
                'rejected' => $review->proposed_rate,
                'source' => $review->source,
            ], event: 'currency.rate_review_rejected', actor: $user);
        }));
    }

    private function lockPending(ExchangeRateReview $review): ExchangeRateReview
    {
        $locked = ExchangeRateReview::query()->whereKey($review->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== ExchangeRateReview::PENDING) {
            throw ValidationException::withMessages(['review' => __('This rate change was already decided or replaced by a newer rate.')]);
        }

        return $locked;
    }
}
