<?php

namespace App\Data\Integrations;

use Closure;

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

    /**
     * A copy with every text (message, step details, details) passed through $mask, so a host can mask what a driver
     * said before showing it.
     *
     * @param  Closure(string): string  $mask
     */
    public function mapText(Closure $mask): self
    {
        return new self(
            $this->ok,
            $mask($this->message),
            array_map(fn (ConnectionCheckStep $step): ConnectionCheckStep => new ConnectionCheckStep($step->label, $step->status, $step->detail === null ? null : $mask($step->detail)), $this->steps),
            array_map($mask, $this->details),
        );
    }
}
