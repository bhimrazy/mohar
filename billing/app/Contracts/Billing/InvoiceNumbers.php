<?php

namespace App\Contracts\Billing;

/**
 * The document numbers of invoices, so other modules show "INV-1243" instead of a database id. Implemented by Billing;
 * Core binds a null default.
 */
interface InvoiceNumbers
{
    /**
     * The number printed on each invoice (legal number, else proforma number, else the draft label), keyed by id.
     * Unknown ids are left out.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, string>
     */
    public function numbers(array $invoiceIds): array;
}
