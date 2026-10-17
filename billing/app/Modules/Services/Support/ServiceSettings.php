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
        return max(array_map(fn (BillingCycle $cycle): int => $this->leadDays($cycle), BillingCycle::recurring()));
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

    public function lockWaitSeconds(): int
    {
        return $this->int('services.module.lock_wait_seconds', 10);
    }

    public function moduleLogRetentionDays(): int
    {
        return $this->int('services.module_log.retention_days', 30);
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
