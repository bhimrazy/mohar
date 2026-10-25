<?php

namespace App\Contracts\Billing;

/**
 * Opt-in for a gateway driver without a refund API whose money goes back some other way than through the provider's
 * merchant portal (connectIPS: a bank transfer from the merchant's account). Billing shows the note in the admin
 * refund dialog and on the gateway form instead of its generic portal text. Display text only: no refund rule changes.
 */
interface DescribesManualRefund
{
    /** One or two plain sentences for staff, e.g. "Refund by bank transfer, then record it here." */
    public function manualRefundNote(): string;
}
