<?php

namespace App\Data\Billing;

use App\Support\Money;

/**
 * What a hosted-payment driver is asked to collect. Amount is in a currency the driver settles in.
 */
final readonly class PaymentIntent
{
    /**
     * @param  string  $reference  our unique attempt reference, [a-z0-9-]{3,20}
     */
    public function __construct(
        public string $reference,
        public Money $amount,
        public string $description,
        public string $returnUrl,
        public string $cancelUrl,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?int $invoiceId = null,
        public ?int $batchId = null,
    ) {}
}
