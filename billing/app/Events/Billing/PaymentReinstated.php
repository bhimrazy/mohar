<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chargeback was undone: the merchant won the dispute and the provider returned the money (#221). The counterpart
 * of PaymentReversed, fired once per won dispute, whether or not the invoice is settled again (InvoicePaid follows
 * only when it is).
 *
 * $transactionId is the `chargeback_reversal` row, $chargebackTransactionId the chargeback it undoes. $amount (minor
 * units of $currency) is everything the chargeback booked out and the win booked back in: $reviewAmount of it could
 * not go back on the invoice (paid again or cancelled since) and waits for staff. $invoiceId is null for a payment
 * that was not on an invoice.
 */
final class PaymentReinstated implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public ?int $invoiceId,
        public int $clientId,
        public int $transactionId,
        public int $chargebackTransactionId,
        public int $amount,
        public string $currency,
        public int $reviewAmount = 0,
    ) {}
}
