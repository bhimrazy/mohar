<?php

namespace App\Events\Domains;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An EPP/transfer code was retrieved; actor is "client" or "staff".
 */
final class DomainEppCodeRetrieved
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $domainId,
        public int $clientId,
        public string $actor,
        public ?int $userId,
        public bool $emailed,
    ) {}
}
