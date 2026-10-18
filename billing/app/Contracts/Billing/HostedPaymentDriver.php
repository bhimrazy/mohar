<?php

namespace App\Contracts\Billing;

use App\Data\Billing\AmountLimits;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\ReturnHint;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;

/**
 * Opt-in for hosted/redirect gateways (Khalti, eSewa, ConnectIPS, Fonepay, …). Billing stores a payment attempt,
 * sends the customer away with the instruction, and only ever marks it paid from an authoritative lookup()
 * (on return and from the reconciliation poller), after checking amount, currency and reference.
 */
interface HostedPaymentDriver extends GatewayDriver
{
    /**
     * @return list<string> ISO codes the provider settles in
     */
    public function currencies(): array;

    public function limits(GatewayConfig $gateway): AmountLimits;

    /** How long an attempt stays payable at the provider. */
    public function attemptTtl(GatewayConfig $gateway): CarbonInterval;

    /** Server-side preparation; may call the provider (e.g. Khalti initiate). */
    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload;

    /** Parse the browser return (verify any signature) into hints. Never marks anything paid. */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint;

    /** Authoritative status from the provider. Must be safe to call repeatedly. */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult;
}
