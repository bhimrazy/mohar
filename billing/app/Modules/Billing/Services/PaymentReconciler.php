<?php

namespace App\Modules\Billing\Services;

use App\Data\Billing\LookupResult;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reconciliation poller for hosted attempts whose customer never came back (no Nepali gateway pushes webhooks).
 *
 * - run(): pending and ambiguous attempts, looked up at most once per interval, with a backoff for long-open ones
 *   (every five minutes).
 * - runLate(): closed attempts (expired, failed) still inside the late window, looked up at most once an hour, so a
 *   payment the provider completes late is still booked (#130). Hourly, on its own lock, and bounded: a per-run
 *   cap, a deadline, and a per-gateway breaker after the first error answer.
 */
class PaymentReconciler
{
    public const LATE_INTERVAL_MINUTES = 60;

    /** Upper bound for `gateways.late_lookup_hours`, also enforced here for values stored directly. */
    public const MAX_LATE_LOOKUP_HOURS = 168;

    /** The late sweep stops starting lookups after this many seconds. */
    public const LATE_DEADLINE_SECONDS = 300;

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

    public function runLate(): int
    {
        $hours = $this->lateWindowHours();

        if ($hours === 0) {
            return 0;
        }

        $cap = min(1000, max(1, $this->settings->int('gateways.late_lookups_per_run', 100)));
        $deadline = microtime(true) + self::LATE_DEADLINE_SECONDS;
        /** @var array<string, true> $tripped gateways that errored in this run */
        $tripped = [];
        $checked = 0;

        $attempts = PaymentAttempt::query()
            ->whereIn('status', [AttemptStatus::Expired, AttemptStatus::Failed])
            ->whereNull('lookups_closed_at')
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', now()->subMinutes(self::LATE_INTERVAL_MINUTES)))
            ->orderBy('id')
            ->limit($cap)
            ->get();

        foreach ($attempts as $attempt) {
            if (microtime(true) >= $deadline) {
                break;
            }

            if (isset($tripped[$attempt->gateway])) {
                continue;
            }

            try {
                $this->settle->handle($attempt);
                $checked++;
                $result = $this->settle->lastResult;

                // One error answer and that provider is left alone until the next run.
                if ($result !== null && ($result->retryable || $result->status === LookupResult::AMBIGUOUS)) {
                    $tripped[$attempt->gateway] = true;
                }
            } catch (Throwable $exception) {
                $tripped[$attempt->gateway] = true;
                report($exception);
            }
        }

        return $checked;
    }

    /**
     * How long after it started a closed attempt is still looked up for a late payment (0 = never).
     */
    public function lateWindowHours(): int
    {
        return min(self::MAX_LATE_LOOKUP_HOURS, max(0, $this->settings->int('gateways.late_lookup_hours', 48)));
    }

    public function inLateWindow(PaymentAttempt $attempt): bool
    {
        $hours = $this->lateWindowHours();

        return $hours > 0 && $attempt->created_at !== null && $attempt->created_at->gte(now()->subHours($hours));
    }
}
