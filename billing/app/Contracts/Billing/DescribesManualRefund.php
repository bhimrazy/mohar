<?php

namespace App\Contracts\Billing;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012): a gateway driver without a refund API whose money goes back some
 * other way than through the provider's merchant portal (connectIPS: a bank transfer from the merchant's account).
 * Billing reads it only when the driver does not declare REFUND, and shows the note in the admin refund dialog and on
 * the gateway form instead of its generic portal text. Display text only: no refund rule changes.
 */
interface DescribesManualRefund
{
    /** One or two plain-text sentences for staff, no markup, e.g. "Refund by bank transfer, then record it here." */
    public function manualRefundNote(): string;
}
