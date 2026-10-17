<?php

namespace App\Events\Affiliates;

use Illuminate\Foundation\Events\Dispatchable;

final class AffiliateActivated
{
    use Dispatchable;

    public function __construct(
        public int $affiliateId,
        public int $clientId,
    ) {}
}
