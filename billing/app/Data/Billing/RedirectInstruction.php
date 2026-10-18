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
     */
    public function __construct(
        public string $url,
        public ?string $providerRef = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
