<?php

namespace App\Events\Affiliates;

use Illuminate\Foundation\Events\Dispatchable;

final class AffiliateCommissionEarned
{
    use Dispatchable;

    public function __construct(
        public int $affiliateId,
        public int $commissionId,
        public int $amount,
        public string $currency,
    ) {}
}
