<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentReceived implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $transactionId,
        public ?int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
        public string $gateway,
    ) {}
}
