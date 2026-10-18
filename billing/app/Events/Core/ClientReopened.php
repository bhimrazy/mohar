<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class ClientReopened
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $clientId,
    ) {}
}
