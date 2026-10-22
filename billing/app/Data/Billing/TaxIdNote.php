<?php

namespace App\Data\Billing;

/**
 * Something staff should know about a client's tax number (App\Contracts\Billing\TaxIdRules). Advice, never a refusal.
 * The message is a fixed sentence that may name states or countries, never the number.
 */
final readonly class TaxIdNote
{
    /** The number looks wrong for the country, or decides something staff may not expect. */
    public const WARNING = 'warning';

    /** Worth knowing; nothing looks wrong. */
    public const INFO = 'info';

    /**
     * @param  self::WARNING|self::INFO  $severity
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
    ) {}
}
