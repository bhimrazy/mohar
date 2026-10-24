<?php

namespace App\Modules\Services\Support;

use App\Enums\BillingCycle;
use App\Modules\Core\Support\Settings;

/**
 * Typed access to the services.* settings (DB override, else Config/services.php).
 */
class ServiceSettings
{
    public function __construct(private Settings $settings) {}

    public function leadDays(BillingCycle $cycle): int
    {
        $override = $this->settings->get("services.renewals.lead_days.{$cycle->value}");

        return $override === null || $override === '' ? $this->int('services.renewals.days_before_due', 14) : (int) $override;
    }

    public function maxLeadDays(): int
    {
        return array_reduce(BillingCycle::recurring(), fn (int $max, BillingCycle $cycle): int => max($max, $this->leadDays($cycle)), 0);
    }

    public function continuousRenewals(): bool
    {
        return $this->bool('services.renewals.continuous', false);
    }

    public function suspendEnabled(): bool
    {
        return $this->bool('services.suspend.enabled', true);
    }

    public function suspendDays(): int
    {
        return $this->int('services.suspend.days_overdue', 7);
    }

    public function terminateEnabled(): bool
    {
        return $this->bool('services.terminate.enabled', false);
    }

    public function terminateDays(): int
    {
        return $this->int('services.terminate.days_overdue', 30);
    }

    public function unsuspendOnPayment(): bool
    {
        return $this->bool('services.unsuspend_on_payment', true);
    }

    public function clientCanRequestCancellation(): bool
    {
        return $this->bool('services.cancellation.client_can_request', true);
    }

    public function cancelUnpaidInvoicesOnCancellation(): bool
    {
        return $this->bool('services.cancellation.cancel_unpaid_invoices', true);
    }

    public function autoProcessCancellations(): bool
    {
        return $this->bool('services.cancellation.auto_process', true);
    }

    public function creditOnDowngrade(): bool
    {
        return $this->bool('services.upgrades.credit_on_downgrade', false);
    }

    public function unpaidChangeExpiryDays(): int
    {
        return $this->int('services.upgrades.unpaid_expiry_days', 7);
    }

    public function maxAttempts(): int
    {
        return max(1, $this->int('services.module.max_attempts', 3));
    }

    /**
     * Delay in seconds before the given (next) attempt number.
     */
    public function backoffBefore(int $nextAttempt): int
    {
        /** @var list<int> $backoff */
        $backoff = (array) config('services.module.backoff', [60, 300, 900]);

        return (int) ($backoff[$nextAttempt - 2] ?? end($backoff) ?: 60);
    }

    /** Seconds a per-service lock outlives the job that holds it. */
    public const LOCK_SLACK_SECONDS = 20;

    /**
     * Maximum run time of one module command job: the host default, raised to the driver's declared command timeout
     * (DriverMetadata::$commandTimeoutSeconds) up to services.module.max_job_timeout_seconds. The cap plus the lock
     * slack must stay below the queue connection's retry_after (240 s), and workers' --timeout at or above it.
     */
    public function jobTimeoutSeconds(?int $driverSeconds = null): int
    {
        $default = max(5, (int) config('services.module.job_timeout_seconds', 60));

        return min($this->maxJobTimeoutSeconds(), max($default, $driverSeconds ?? 0));
    }

    /**
     * Longest a command may run inside a page request (staff "run now"); a driver that needs more is queued.
     */
    public function syncTimeoutSeconds(): int
    {
        return max(5, (int) config('services.module.sync_timeout_seconds', 60));
    }

    public function maxJobTimeoutSeconds(): int
    {
        return max(5, (int) config('services.module.max_job_timeout_seconds', 180));
    }

    /**
     * Per-service lock lifetime: longer than the job holding it may run, shorter than the queue's retry_after.
     */
    public function lockTtlSeconds(?int $jobTimeoutSeconds = null): int
    {
        return max(($jobTimeoutSeconds ?? $this->jobTimeoutSeconds()) + self::LOCK_SLACK_SECONDS, (int) config('services.module.lock_ttl_seconds', 80));
    }

    public function lockWaitSeconds(): int
    {
        return $this->int('services.module.lock_wait_seconds', 10);
    }

    public function moduleLogRetentionDays(): int
    {
        return $this->int('services.module_log.retention_days', 30);
    }

    public function usageRetentionDays(): int
    {
        return max(90, $this->int('services.usage.retention_days', 395));
    }

    public function randomUsernames(): bool
    {
        return $this->bool('services.random_usernames', false);
    }

    private function int(string $key, int $default): int
    {
        return (int) $this->settings->get($key, $default);
    }

    private function bool(string $key, bool $default): bool
    {
        return filter_var($this->settings->get($key, $default), FILTER_VALIDATE_BOOL);
    }
}
