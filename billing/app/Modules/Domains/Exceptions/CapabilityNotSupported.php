<?php

namespace App\Modules\Domains\Exceptions;

/**
 * The domain's registrar driver does not implement the requested command.
 */
class CapabilityNotSupported extends RegistrarException
{
    public function __construct(public readonly string $capability, string $registrar)
    {
        parent::__construct("The {$registrar} registrar does not support {$capability}.", retryable: false);
    }

    public function errorCode(): string
    {
        return 'capability_not_supported';
    }
}
