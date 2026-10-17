<?php

namespace App\Events\Affiliates;

use Illuminate\Foundation\Events\Dispatchable;

final class AffiliateWithdrawalRequested
{
    use Dispatchable;

    public function __construct(
        public int $affiliateId,
        public int $amount,
        public string $currency,
    ) {}
}
