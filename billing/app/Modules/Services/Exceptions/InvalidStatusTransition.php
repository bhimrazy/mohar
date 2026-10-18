<?php

namespace App\Modules\Services\Exceptions;

use App\Modules\Services\Enums\ServiceStatus;
use DomainException;

final class InvalidStatusTransition extends DomainException
{
    public function __construct(public readonly ServiceStatus $from, public readonly ServiceStatus $to)
    {
        parent::__construct("A {$from->value} service cannot become {$to->value}.");
    }
}
