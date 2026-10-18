<?php

namespace App\Data\Billing;

/**
 * A webhook the driver refused (bad signature, unknown event, malformed body). Nothing is recorded.
 */
final readonly class CallbackRejection
{
    public const INVALID_SIGNATURE = 'invalid signature';

    public function __construct(public string $reason) {}

    public function isSignatureFailure(): bool
    {
        return $this->reason === self::INVALID_SIGNATURE;
    }
}
