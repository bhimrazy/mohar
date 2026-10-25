<?php

namespace App\Contracts\Billing;

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\PaymentAttemptRef;
use App\Enums\HostedSessionCancel;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012), for a HostedPaymentDriver whose provider can close an open session
 * so it can no longer be paid (Stripe: expire the Checkout Session). Billing calls it before it frees an invoice for
 * another way to pay, outside any database transaction, and always settles from lookup() afterwards.
 */
interface CancelsHostedPayment
{
    /**
     * Ask the provider to close the attempt's session. Safe to repeat. A session that is already paid or closed is
     * left as it is. Called while the customer waits, so it makes one quick try and never throws for a provider
     * error. The answer goes to the gateway log only: it never marks anything paid, failed or closed.
     */
    public function cancelSession(GatewayConfig $gateway, PaymentAttemptRef $attempt): HostedSessionCancel;
}
