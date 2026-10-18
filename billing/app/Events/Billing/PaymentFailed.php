<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentFailed
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public ?int $invoiceId,
        public int $clientId,
        public string $gateway,
        public string $reason,
    ) {}
}
