<?php

namespace App\Events\Affiliates;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class AffiliateActivated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $affiliateId,
        public int $clientId,
    ) {}
}
