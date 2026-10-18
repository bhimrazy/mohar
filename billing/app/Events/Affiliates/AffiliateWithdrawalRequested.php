<?php

namespace App\Events\Affiliates;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class AffiliateWithdrawalRequested implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $affiliateId,
        public int $amount,
        public string $currency,
    ) {}
}
