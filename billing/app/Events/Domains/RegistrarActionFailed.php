<?php

namespace App\Events\Domains;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class RegistrarActionFailed
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $action,
        public string $error,
    ) {}
}
