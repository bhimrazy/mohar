<?php

namespace App\Data\Integrations;

/**
 * Result of an integration's credential or connection check (gateway, server, registrar). The message is shown to
 * staff, so it never contains secrets or raw provider bodies.
 */
final readonly class ConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}
}
