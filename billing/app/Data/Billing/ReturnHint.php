<?php

namespace App\Data\Billing;

/**
 * What the browser return claims. Untrusted, even with a valid signature: it only tells us which attempt to look up.
 */
final readonly class ReturnHint
{
    /**
     * @param  array<string, mixed>  $raw  redacted return parameters
     * @param  bool  $signedPaid  the return carries the provider's own valid signature over a paid status (eSewa
     *                            COMPLETE). Still only a hint for settling, but evidence that money was taken: a later
     *                            "not found" must go to staff review, never end the attempt
     */
    public function __construct(
        public ?string $reference,
        public ?string $providerRef = null,
        public ?string $claimedStatus = null,
        public bool $signatureValid = false,
        public array $raw = [],
        public bool $signedPaid = false,
    ) {}
}
