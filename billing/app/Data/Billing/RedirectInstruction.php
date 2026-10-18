<?php

namespace App\Data\Billing;

/**
 * Send the customer to the provider with a GET redirect (Khalti payment_url, Fonepay, Stripe Checkout).
 */
final readonly class RedirectInstruction
{
    /**
     * @param  string|null  $providerRef  provider session id (e.g. Khalti pidx), stored on the attempt
     */
    public function __construct(
        public string $url,
        public ?string $providerRef = null,
    ) {}
}
