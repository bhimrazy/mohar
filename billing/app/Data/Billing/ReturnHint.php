<?php

namespace App\Data\Billing;

/**
 * What the browser return claims. Untrusted, even with a valid signature: it only tells us which attempt to look up.
 */
final readonly class ReturnHint
{
    /**
     * @param  array<string, mixed>  $raw  redacted return parameters
     */
    public function __construct(
        public ?string $reference,
        public ?string $providerRef = null,
        public ?string $claimedStatus = null,
        public bool $signatureValid = false,
        public array $raw = [],
    ) {}
}
