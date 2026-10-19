<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Jobs\LookUpPaymentAttempt;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Support\Collection;

/**
 * Reconciliation poller for hosted attempts whose customer never came back (no Nepali gateway pushes webhooks).
 * Pending and ambiguous attempts are looked up at most once per interval, with a backoff for long-open ones. The
 * sweep only selects attempts and queues one lookup job each on the payments queue (ADR 0017 §2).
 */
class PaymentReconciler
{
    public function __construct(private BillingSettings $settings) {}

    /**
     * @return int the number of lookups queued
     */
    public function run(): int
    {
        $interval = max(1, $this->settings->int('gateways.lookup_interval_minutes', 5));
        $queued = 0;

        PaymentAttempt::query()
            ->whereIn('status', [AttemptStatus::Pending, AttemptStatus::Ambiguous])
            ->where('created_at', '>=', now()->subDays(14))
            ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', now()->subMinutes($interval)))
            ->orderBy('id')
            ->chunkById(100, function (Collection $attempts) use ($interval, &$queued): void {
                foreach ($attempts as $attempt) {
                    // Back off: after 12 lookups, check at most once per 6 intervals.
                    if ($attempt->lookup_count >= 12 && $attempt->last_lookup_at !== null && $attempt->last_lookup_at->gt(now()->subMinutes($interval * 6))) {
                        continue;
                    }

                    LookUpPaymentAttempt::dispatch($attempt->id);
                    $queued++;
                }
            });

        return $queued;
    }
}
