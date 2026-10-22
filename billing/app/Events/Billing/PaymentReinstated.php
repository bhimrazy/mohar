<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chargeback was undone: the merchant won the dispute and the provider returned the money (#221). The counterpart
 * of PaymentReversed, one to one: a won dispute fires one PaymentReinstated for each PaymentReversed its chargeback
 * fired, with the same invoice and amount (a combined charge fires one per invoice; a dispute that took back only
 * account credit fired neither). Fired whether or not the invoice is settled again (InvoicePaid follows only when it
 * is).
 *
 * $transactionId is the win's `chargeback_reversal` row (shared by the events of one dispute), $chargebackTransactionId
 * the chargeback row whose PaymentReversed this answers. $amount (minor units of $currency) is that invoice part;
 * $reviewAmount of it could not go back on the invoice (paid again or cancelled since) and waits for staff review.
 */
final class PaymentReinstated implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $transactionId,
        public int $chargebackTransactionId,
        public int $amount,
        public string $currency,
        public int $reviewAmount = 0,
    ) {}
}
