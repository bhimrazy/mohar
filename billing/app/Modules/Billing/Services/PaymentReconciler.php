<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reconciliation poller for hosted attempts whose customer never came back (no Nepali gateway pushes webhooks).
 * Pending and ambiguous attempts are looked up at most once per interval, with a backoff for long-open ones.
 */
class PaymentReconciler
{
    public function __construct(
        private SettlePaymentAttempt $settle,
        private BillingSettings $settings,
    ) {}

    public function run(): int
    {
        $interval = max(1, $this->settings->int('gateways.lookup_interval_minutes', 5));
        $checked = 0;

        PaymentAttempt::query()
            ->whereIn('status', [AttemptStatus::Pending, AttemptStatus::Ambiguous])
            ->where('created_at', '>=', now()->subDays(14))
            ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', now()->subMinutes($interval)))
            ->orderBy('id')
            ->chunkById(100, function (Collection $attempts) use ($interval, &$checked): void {
                foreach ($attempts as $attempt) {
                    // Back off: after 12 lookups, check at most once per 6 intervals.
                    if ($attempt->lookup_count >= 12 && $attempt->last_lookup_at !== null && $attempt->last_lookup_at->gt(now()->subMinutes($interval * 6))) {
                        continue;
                    }

                    try {
                        $this->settle->handle($attempt);
                        $checked++;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $checked;
    }
}
