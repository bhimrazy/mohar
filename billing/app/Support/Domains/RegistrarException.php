<?php

namespace App\Support\Domains;

use RuntimeException;
use Throwable;

/**
 * A registrar call failed. Retryable errors are transient (connection refused, rate limited) and the
 * request is known not to have been executed; anything else must not be blindly re-submitted.
 *
 * Kernel exception (ADR 0009 §4): it knows nothing about HTTP. Domains registers how it renders.
 */
class RegistrarException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'registrar_error';
    }
}
