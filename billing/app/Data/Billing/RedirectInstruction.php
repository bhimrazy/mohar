<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Send the customer to the provider with a GET redirect (Khalti payment_url, Fonepay, Stripe Checkout).
 */
final readonly class RedirectInstruction
{
    /**
     * @param  string|null  $providerRef  provider session id (e.g. Khalti pidx), stored on the attempt
     * @param  CarbonImmutable|null  $expiresAt  when the provider's session stops accepting payment, if it says;
     *                                           replaces the driver's attemptTtl() on the attempt
     * @param  bool  $keepForResume  the provider serves $url again while its session is open but can't return it
     *                               later (Khalti's lookup has no payment_url). For a driver that implements
     *                               ResumesHostedPayment, Billing keeps it encrypted on the attempt and hands it back
     *                               in PaymentAttemptRef::$resumeUrl on the resumeUrl() call only. Stripe leaves this
     *                               false: its resumeUrl() reads the URL from the provider.
     */
    public function __construct(
        public string $url,
        public ?string $providerRef = null,
        public ?CarbonImmutable $expiresAt = null,
        public bool $keepForResume = false,
    ) {}
}
