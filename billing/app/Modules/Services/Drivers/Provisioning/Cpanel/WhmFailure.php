<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

use App\Enums\DriverOutcome;
use RuntimeException;

/**
 * A WHM call that did not produce a usable answer, already mapped to the outcome vocabulary (spec §5). The message is
 * our own sentence for staff: it never holds request parameters, credentials or raw response bodies.
 */
final class WhmFailure extends RuntimeException
{
    public const ADDRESS = 'address';

    public const AUTH = 'auth';

    public const TLS = 'tls';

    public const PIN = 'pin';

    public const TRANSPORT = 'transport';

    public const HTTP = 'http';

    public const BODY = 'body';

    public const REFUSED = 'refused';

    public const CONFIG = 'config';

    public function __construct(
        string $message,
        public readonly DriverOutcome $outcome,
        public readonly string $kind,
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $message, string $kind = self::REFUSED): self
    {
        return new self($message, DriverOutcome::Rejected, $kind);
    }
}
