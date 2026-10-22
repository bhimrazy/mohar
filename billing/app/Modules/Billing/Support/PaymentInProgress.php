<?php

namespace App\Modules\Billing\Support;

use App\Contracts\Billing\CancelsHostedPayment;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;

/**
 * A hosted payment the customer started and we haven't confirmed yet. It stays visible on the invoice (client and
 * staff) until it settles, and while it is still payable at the provider no second payment can be started, so
 * the customer can't easily pay twice. Once the attempt's provider session has expired (it may still be polled
 * during the grace period), paying another way is allowed again.
 *
 * The customer can reopen the provider page of an open attempt where the driver supports it, or close the attempt to
 * pay another way (CloseHostedPayment, #123). An attempt the customer ended without the provider confirming that its
 * session can never be paid (an eSewa cancel) stays on the invoice until its session time is over, with the
 * "don't pay again" warning, because a stale form paid later is still booked.
 */
class PaymentInProgress
{
    public function __construct(private GatewayManager $gateways) {}

    public function attempt(Invoice $invoice): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [AttemptStatus::Pending, AttemptStatus::Ambiguous])
            ->where('created_at', '>=', now()->subDays(14))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The attempt to show on the invoice: an open one, else the latest one if a lookup ended it while the provider
     * could still take a payment for it (lookups open, session time not over). A start the provider refused was never
     * looked up and is not shown.
     */
    public function shown(Invoice $invoice): ?PaymentAttempt
    {
        $open = $this->attempt($invoice);

        if ($open !== null) {
            return $open;
        }

        $latest = PaymentAttempt::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->first();

        return $latest !== null && $latest->status->isClosed() && $latest->last_lookup_at !== null && $latest->lookups_closed_at === null && $latest->transaction_id === null
            && $latest->expires_at !== null && $latest->expires_at->isFuture() ? $latest : null;
    }

    /**
     * Whether the attempt can still be paid at the provider (its session hasn't expired).
     */
    public static function blocks(PaymentAttempt $attempt): bool
    {
        return $attempt->status->isOpen() && ($attempt->expires_at === null || $attempt->expires_at->isFuture());
    }

    /**
     * A pending attempt that still blocks the invoice: the customer may reopen it or close it to pay another way.
     */
    public static function closable(PaymentAttempt $attempt): bool
    {
        return $attempt->status === AttemptStatus::Pending && self::blocks($attempt);
    }

    /**
     * Refuse a new payment while an earlier one can still complete at the provider.
     */
    public function assertCanStart(Invoice $invoice): void
    {
        $attempt = $this->attempt($invoice);

        if ($attempt !== null && self::blocks($attempt)) {
            throw new BillingException(__('A payment with :gateway is already in progress for this invoice. Check its status before paying again.', [
                'gateway' => $this->gateways->has($attempt->gateway) ? $this->gateways->gateway($attempt->gateway)->display_name : $attempt->gateway,
            ]));
        }
    }

    /**
     * @return array{id: int|null, reference: string, gateway: string, gateway_name: string, logo: array{url: string, dark_url: string|null, alt: string}|null, amount: int, currency: string, status: string, started_at: string|null, expires_at: string|null, check_url: string, can_pay_another_way: bool, can_resume: bool, can_close: bool, review_reason: string|null}|null
     */
    public function forInvoice(Invoice $invoice, bool $staff = false): ?array
    {
        $attempt = $this->shown($invoice);

        if ($attempt === null) {
            return null;
        }

        $known = $this->gateways->has($attempt->gateway);

        return [
            'id' => $staff ? $attempt->id : null,
            'reference' => $attempt->reference,
            'gateway' => $attempt->gateway,
            'gateway_name' => $known ? $this->gateways->gateway($attempt->gateway)->display_name : $attempt->gateway,
            'logo' => $known ? $this->gateways->logo($attempt->gateway) : null,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'status' => $attempt->status->value,
            'started_at' => $attempt->created_at?->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'check_url' => route('billing.gateways.return', [$attempt->gateway, 'ref' => $attempt->reference]),
            'can_pay_another_way' => ! self::blocks($attempt),
            // Only a pending attempt the provider may still take: one in staff review might already be paid.
            // Only while the invoice still has something to pay (ResumeHostedPayment checks again on the click).
            'can_resume' => ! $staff && $known && self::closable($attempt) && $invoice->status->isPayable() && $invoice->balance > 0
                && $this->gateways->driver($attempt->gateway) instanceof ResumesHostedPayment,
            // Only drivers that can close the provider session (Stripe), never eSewa or Khalti (CTO decision on #266).
            'can_close' => ! $staff && $known && self::closable($attempt) && $this->gateways->driver($attempt->gateway) instanceof CancelsHostedPayment,
            'review_reason' => $staff ? $attempt->review_reason : null,
        ];
    }
}
