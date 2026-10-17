<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

final class RegistrarActionFailed
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $action,
        public string $error,
    ) {}
}
