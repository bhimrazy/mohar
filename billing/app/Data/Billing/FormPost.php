<?php

namespace App\Data\Billing;

/**
 * Send the customer with an auto-submitted POST form (eSewa, ConnectIPS).
 */
final readonly class FormPost
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $action,
        public array $fields,
        public ?string $providerRef = null,
    ) {}
}
