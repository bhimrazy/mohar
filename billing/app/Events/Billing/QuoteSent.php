<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class QuoteSent
{
    use Dispatchable;

    public function __construct(
        public int $quoteId,
        public int $clientId,
    ) {}
}
