<?php

namespace Tests\Fakes\Billing;

use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\ReturnHint;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;

/** A connectIPS-like driver: signs with an uploaded certificate, and its return carries only its own reference. */
class FakeSigningGatewayDriver extends AbstractGatewayDriver implements HostedPaymentDriver
{
    /** @var list<string> provider references initiate() hands out, in order */
    public static array $providerRefs = [];

    public static int $initiated = 0;

    public function key(): string
    {
        return 'fake_signing';
    }

    public function name(): string
    {
        return 'Fake signing gateway';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'string', 'required' => true],
            ['key' => 'signing_key', 'label' => 'Signing certificate', 'type' => 'certificate', 'required' => true],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 100);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(30);
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        self::$initiated++;

        return new FormPost('https://signing.test/pay', ['TXNAMT' => (string) $intent->amount->amount], providerRef: array_shift(self::$providerRefs));
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        return new ReturnHint(null, providerRef: $request->string('TXNID')->toString() ?: null);
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        return new LookupResult(LookupResult::PENDING);
    }
}
