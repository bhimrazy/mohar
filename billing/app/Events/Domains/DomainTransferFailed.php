<?php

namespace App\Events\Domains;

use Illuminate\Foundation\Events\Dispatchable;

final class DomainTransferFailed
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $reason,
    ) {}
}
