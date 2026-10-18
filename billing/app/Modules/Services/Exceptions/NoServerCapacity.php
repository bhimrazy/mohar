<?php

namespace App\Modules\Services\Exceptions;

use RuntimeException;

final class NoServerCapacity extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No server capacity');
    }
}
