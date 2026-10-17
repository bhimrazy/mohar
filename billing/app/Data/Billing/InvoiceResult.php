<?php

namespace App\Data\Billing;

final readonly class InvoiceResult
{
    public function __construct(
        public int $invoiceId,
        public string $number,
        public string $status,
        public string $currency,
        public int $subtotal,
        public int $tax,
        public int $total,
    ) {}
}
