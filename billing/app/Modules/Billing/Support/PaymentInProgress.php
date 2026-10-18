<?php

namespace App\Modules\Billing\Support;

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
     * Whether the attempt can still be paid at the provider (its session hasn't expired).
     */
    public static function blocks(PaymentAttempt $attempt): bool
    {
        return $attempt->status->isOpen() && ($attempt->expires_at === null || $attempt->expires_at->isFuture());
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
     * @return array{id: int|null, reference: string, gateway: string, gateway_name: string, logo: array{url: string, dark_url: string|null, alt: string}|null, amount: int, currency: string, status: string, started_at: string|null, expires_at: string|null, check_url: string, can_pay_another_way: bool, review_reason: string|null}|null
     */
    public function forInvoice(Invoice $invoice, bool $staff = false): ?array
    {
        $attempt = $this->attempt($invoice);

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
            'review_reason' => $staff ? $attempt->review_reason : null,
        ];
    }
}
