<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Billing\InvoiceQueries;

class FakeInvoiceQueries implements InvoiceQueries
{
    /** @var list<int> invoice ids with a recorded payment, credit or refund */
    public array $withPayments = [];

    public function hasPayments(int $invoiceId): bool
    {
        return in_array($invoiceId, $this->withPayments, true);
    }

    public function hasDocumentsInCurrency(string $currency): bool
    {
        return false;
    }
}
