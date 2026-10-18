<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Send the customer with an auto-submitted POST form (eSewa, ConnectIPS).
 */
final readonly class FormPost
{
    /**
     * @param  array<string, string>  $fields
     * @param  CarbonImmutable|null  $expiresAt  when the provider's session stops accepting payment, if it says;
     *                                           replaces the driver's attemptTtl() on the attempt
     */
    public function __construct(
        public string $action,
        public array $fields,
        public ?string $providerRef = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
