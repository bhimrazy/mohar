<?php

namespace Tests\Fakes\Services;

use App\Contracts\Billing\InvoiceQueries;

class FakeInvoiceQueries implements InvoiceQueries
{
    /** @var list<int> */
    public array $paidInvoiceIds = [];

    public function hasPayments(int $invoiceId): bool
    {
        return in_array($invoiceId, $this->paidInvoiceIds, true);
    }

    public function hasDocumentsInCurrency(string $currency): bool
    {
        return false;
    }
}
