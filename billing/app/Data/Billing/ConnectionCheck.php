<?php

namespace App\Data\Billing;

/**
 * Result of a gateway credential check. The message is shown to staff, so it never contains secrets or raw
 * provider bodies.
 */
final readonly class ConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}
}
