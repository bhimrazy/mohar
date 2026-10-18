<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class InvoicePaymentReminder
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  string  $stage  before_due|due|overdue_1|overdue_2|overdue_3
     */
    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public string $stage,
    ) {}
}
