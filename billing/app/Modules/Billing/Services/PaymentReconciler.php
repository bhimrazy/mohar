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

    /** How often the late sweep runs (`--only=late`, hourly in BillingServiceProvider). */
    public const LATE_RUN_EVERY_MINUTES = 60;

    /** Upper bound for `gateways.late_lookup_hours`, also enforced here for values stored directly. */
    public const MAX_LATE_LOOKUP_HOURS = 168;

    /** The late sweep stops starting lookups after this many seconds. */
    public const LATE_DEADLINE_SECONDS = 300;

    /**
     * Slack on the late interval (#222): a lookup is stamped when it finishes, seconds to minutes after its run
     * started (up to the deadline plus one provider timeout), so the next run, an hour after the last, must accept a
     * lookup stamped up to this long into the previous run. Runs are hourly and a run never looks an attempt up
     * twice, so two lookups of one attempt are still at least LATE_INTERVAL_MINUTES - this apart.
     */
    public const LATE_SLACK_MINUTES = 10;

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
        $lookedUpBefore = now()->subMinutes(self::LATE_INTERVAL_MINUTES - self::LATE_SLACK_MINUTES);
        $deadline = microtime(true) + self::LATE_DEADLINE_SECONDS;
        /** @var array<string, true> $tripped gateways that errored in this run */
        $tripped = [];
        $checked = 0;

        /** @var list<int> $seen attempts already tried in this run (a lookup that threw saved nothing) */
        $seen = [];

        // The cap counts lookups made. A gateway that errors is dropped from the next query, so one provider that is
        // down never uses up the run for the others.
        while ($checked < $cap && microtime(true) < $deadline) {
            $attempts = PaymentAttempt::query()
                ->whereIn('status', [AttemptStatus::Expired, AttemptStatus::Failed])
                ->whereNull('lookups_closed_at')
                ->where('created_at', '>=', now()->subHours($hours))
                ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', $lookedUpBefore))
                ->whereNotIn('gateway', array_keys($tripped))
                ->whereNotIn('id', $seen)
                ->orderBy('id')
                ->limit($cap - $checked)
                ->get();

            if ($attempts->isEmpty()) {
                break;
            }

            foreach ($attempts as $attempt) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                if (isset($tripped[$attempt->gateway])) {
                    continue;
                }

                $seen[] = $attempt->id;

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
        }

        return $checked;
    }

    /**
     * How long after it started a closed attempt is still looked up for a late payment (0 = never).
     */
    public function lateWindowHours(): int
    {
        return self::lateWindowHoursFrom($this->settings);
    }

    public static function lateWindowHoursFrom(BillingSettings $settings): int
    {
        return min(self::MAX_LATE_LOOKUP_HOURS, max(0, $settings->int('gateways.late_lookup_hours', 48)));
    }

    /**
     * Whether the late sweep is sure to look up an attempt again after it was closed early, at a point when any
     * payment made during its lifetime is already visible. The closing lookup can come as late as the attempt's
     * expiry; the sweep then waits LATE_INTERVAL_MINUTES after that lookup, and only starts every
     * LATE_RUN_EVERY_MINUTES. So the late window must be at least lifetime + interval + run spacing (the margin):
     * 180 minutes for a 60-minute eSewa attempt. An attempt with no expiry is never covered.
     */
    public static function lateSweepCovers(PaymentAttempt $attempt, BillingSettings $settings): bool
    {
        if ($attempt->created_at === null || $attempt->expires_at === null) {
            return false;
        }

        $lifetimeMinutes = (int) ceil($attempt->created_at->diffInMinutes($attempt->expires_at, true));

        return self::lateWindowHoursFrom($settings) * 60 >= $lifetimeMinutes + self::LATE_INTERVAL_MINUTES + self::LATE_RUN_EVERY_MINUTES;
    }

    public function inLateWindow(PaymentAttempt $attempt): bool
    {
        $hours = $this->lateWindowHours();

        return $hours > 0 && $attempt->created_at !== null && $attempt->created_at->gte(now()->subHours($hours));
    }
}
