<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

final class ClientRegistered
{
    use Dispatchable;

    public function __construct(
        public int $clientId,
        public ?int $userId,
    ) {}
}
