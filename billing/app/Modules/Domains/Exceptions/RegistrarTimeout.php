<?php

namespace App\Modules\Domains\Exceptions;

/**
 * The registrar did not answer in time: the command may or may not have been executed.
 * Never retried automatically for register/transfer (prevents double registration and charges).
 */
class RegistrarTimeout extends RegistrarException
{
    public function __construct(string $message = 'The registrar did not respond in time; the result is unknown.')
    {
        parent::__construct($message, retryable: false);
    }

    public function errorCode(): string
    {
        return 'registrar_timeout';
    }
}
