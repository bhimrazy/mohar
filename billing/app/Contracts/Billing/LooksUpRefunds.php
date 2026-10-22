<?php

namespace App\Contracts\Billing;

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012): a gateway that can be asked about a refund Billing sent but never
 * saw answered (a timeout, a server error). Billing offers "Check refund" only for a driver that implements this;
 * for any other driver staff settle such a refund by hand.
 */
interface LooksUpRefunds extends GatewayDriver
{
    /**
     * Answer exactly as refund() would have answered had its response arrived. The request is the one the refund was
     * sent with, and `reference` is always set (it is the idempotency key). SUCCESS: the provider accepted the refund
     * (even one still completing), `txnId` is its refund id. DECLINED: there is definitely no such refund, or it
     * failed or was cancelled; Billing then releases the amount. PENDING: only where refund() would answer PENDING.
     * ERROR: unknown or unreachable, nothing changes. A driver compares the refund it found with `amount`
     * (value and currency) and answers ERROR on a mismatch. A lookup may repeat the original request when the
     * provider's idempotency makes that safe; it must never create a second refund. `sentAt` says when the refund
     * was first sent, for providers whose idempotency window ends.
     */
    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult;
}
