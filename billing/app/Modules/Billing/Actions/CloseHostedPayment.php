<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\CancelsHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\PaymentInProgress;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The customer chose to pay another way while a hosted attempt is still open (#123). The provider session is
 * closed first where the driver can (Stripe: expire the Checkout Session), then the attempt is settled from an
 * authoritative lookup(), as a cancel return would be. The invoice is free again only when that lookup ends the
 * attempt; a provider that reports it paid books the payment instead, and one that still holds the session open
 * keeps the invoice locked.
 *
 * Order: a cache lock per attempt (the shared lock store, ADR 0017 §4 and #64), then the provider call outside any
 * database transaction, then SettlePaymentAttempt, which locks and re-reads the attempt row itself (ADR 0021). The
 * lock outlives both provider calls, which use the short interactive timeouts. A second click waits for the first
 * and then finds the attempt closed, so nothing is sent twice. A success the provider reports later for a closed
 * attempt is still booked as a late payment (#130).
 */
class CloseHostedPayment
{
    /** Longer than the cancel and lookup calls together (interactive timeouts). */
    public const LOCK_SECONDS = 30;

    public const WAIT_SECONDS = 20;

    public function __construct(
        private GatewayManager $gateways,
        private SettlePaymentAttempt $settle,
        private GatewayLogger $log,
    ) {}

    public function handle(PaymentAttempt $attempt): AttemptStatus
    {
        try {
            return Cache::lock('billing.attempt.close.'.$attempt->id, self::LOCK_SECONDS)
                ->block(self::WAIT_SECONDS, fn (): AttemptStatus => $this->close($attempt));
        } catch (LockTimeoutException) {
            throw new BillingException(__('We are still closing this payment. Refresh the page in a moment.'));
        }
    }

    private function close(PaymentAttempt $attempt): AttemptStatus
    {
        $attempt = PaymentAttempt::query()->findOrFail($attempt->id);
        $driver = $this->gateways->hosted($attempt->gateway);

        // Already settled, closed or in staff review (an earlier click, a return or the poller got there first).
        if (! PaymentInProgress::closable($attempt) || $driver === null) {
            return $attempt->status;
        }

        if ($driver instanceof CancelsHostedPayment) {
            $outcome = $driver->cancelSession($this->gateways->config($attempt->gateway), $attempt->toRef(interactive: true));
            $this->log->log($attempt->gateway, 'request', 'hosted session close: '.$outcome->value, ['reference' => $attempt->reference], $attempt->invoice_id);
        }

        return $this->settle->handle($attempt, interactive: true, cancelReturn: true);
    }
}
