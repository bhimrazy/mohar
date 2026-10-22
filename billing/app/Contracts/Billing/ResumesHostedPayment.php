<?php

namespace App\Contracts\Billing;

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\PaymentAttemptRef;

/**
 * Opt-in for a HostedPaymentDriver whose provider page stays open after a failed try (a declined card on Stripe
 * Checkout) and can be opened again. Billing asks only for an attempt it still holds open, at the invoice owner's
 * request, and redirects to the answer at once: the URL is never logged or sent as a page prop. A provider that can't
 * return the URL later (Khalti) marks its RedirectInstruction resumable; Billing then keeps that URL encrypted on the
 * attempt and passes it back in PaymentAttemptRef::$resumeUrl, and the driver still asks the provider before using it.
 */
interface ResumesHostedPayment
{
    /**
     * The provider page where the customer can finish this attempt, read from (or, for a kept URL, confirmed with) the
     * provider now; never taken from the browser. Only an https URL on the provider's own payment host; null when the provider can't be reached,
     * its session is no longer open (paid, expired, cancelled) or the URL it returned fails that check. Billing then
     * settles through lookup().
     */
    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string;
}
