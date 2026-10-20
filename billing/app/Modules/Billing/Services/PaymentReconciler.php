<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Support\BillingSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reconciliation poller for hosted attempts whose customer never came back (no Nepali gateway pushes webhooks).
 * Pending and ambiguous attempts are looked up at most once per interval, with a backoff for long-open ones.
 * Closed attempts (expired, failed) are looked up once an hour for `gateways.late_lookup_hours` after they were
 * started, so a payment the provider completes late is still booked (#130).
 */
class PaymentReconciler
{
    public const LATE_INTERVAL_MINUTES = 60;

    public function __construct(
        private SettlePaymentAttempt $settle,
        private BillingSettings $settings,
    ) {}

    public function run(): int
    {
        $interval = max(1, $this->settings->int('gateways.lookup_interval_minutes', 5));
        $checked = 0;

        $this->sweep(
            PaymentAttempt::query()
                ->whereIn('status', [AttemptStatus::Pending, AttemptStatus::Ambiguous])
                ->where('created_at', '>=', now()->subDays(14))
                ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', now()->subMinutes($interval))),
            // Back off: after 12 lookups, check at most once per 6 intervals.
            fn (PaymentAttempt $attempt): bool => $attempt->lookup_count >= 12 && $attempt->last_lookup_at !== null && $attempt->last_lookup_at->gt(now()->subMinutes($interval * 6)),
            $checked,
        );

        $lateHours = $this->settings->int('gateways.late_lookup_hours', 48);

        if ($lateHours > 0) {
            $this->sweep(
                PaymentAttempt::query()
                    ->whereIn('status', [AttemptStatus::Expired, AttemptStatus::Failed])
                    ->where('created_at', '>=', now()->subHours($lateHours))
                    ->where(fn ($query) => $query->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<=', now()->subMinutes(self::LATE_INTERVAL_MINUTES))),
                fn (PaymentAttempt $attempt): bool => false,
                $checked,
            );
        }

        return $checked;
    }

    /**
     * @param  Builder<PaymentAttempt>  $query
     * @param  Closure(PaymentAttempt): bool  $skip
     */
    private function sweep(Builder $query, Closure $skip, int &$checked): void
    {
        $query->orderBy('id')->chunkById(100, function (Collection $attempts) use ($skip, &$checked): void {
            foreach ($attempts as $attempt) {
                if ($skip($attempt)) {
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
    }
}
