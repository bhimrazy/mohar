<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

final class ClientUpdated
{
    use Dispatchable;

    public function __construct(
        public int $clientId,
    ) {}
}
