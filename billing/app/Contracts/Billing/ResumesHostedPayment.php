<?php

namespace App\Contracts\Billing;

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\PaymentAttemptRef;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012), for a HostedPaymentDriver whose provider page stays open after a
 * failed try (a declined card on Stripe Checkout, a closed Khalti tab) and can be opened again. Billing asks only for
 * an attempt it still holds open, at the invoice owner's request, and sends the answer to the browser only as the
 * redirect target: it is never logged, put in a page prop or returned in JSON. A driver whose provider can't return
 * the URL later (Khalti) sets RedirectInstruction::$keepForResume; Billing then keeps that URL encrypted on the
 * attempt and passes it back in PaymentAttemptRef::$resumeUrl, on this call only, and the driver confirms with the
 * provider before returning it.
 */
interface ResumesHostedPayment
{
    /**
     * The provider page where the customer can finish this attempt, read from the provider now, or for a kept URL
     * confirmed with it now; never taken from the browser. Only an https URL on the provider's own payment host; null
     * when the provider can't be reached, its session is no longer open (paid, expired, cancelled) or the URL fails
     * that check. Billing then settles through lookup(), which a driver may answer from the call it just made here.
     */
    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string;
}
