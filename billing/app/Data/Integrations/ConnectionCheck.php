<?php

namespace App\Data\Integrations;

/**
 * Result of an integration's credential or connection check (gateway, server, registrar). The message is shown to
 * staff, so it never contains secrets or raw provider bodies.
 */
final readonly class ConnectionCheck
{
    /**
     * @param  list<ConnectionCheckStep>  $steps  an optional checklist, in the order the steps ran
     * @param  array<string, string>  $details  facts worth keeping or showing, e.g. `version`, `account_kind`,
     *                                          `certificate_spki_sha256`, `token_expires_at`; never secrets
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public array $steps = [],
        public array $details = [],
    ) {}
}
