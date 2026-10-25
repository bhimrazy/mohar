<?php

/*
| Review-board re-check probe for PR #152 at f8cbf197: the late sweep's per-run cap is applied in SQL before the
| per-gateway breaker skips rows, so one failing provider with a backlog uses up every slot of the hourly run.
*/

use App\Contracts\Billing\GatewayRegistry;
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
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;

require_once __DIR__.'/helpers.php';

/**
 * Lookup-only wallet registered under a key of the test's choosing; answers are set per attempt reference.
 */
class LateRound2WalletDriver extends AbstractGatewayDriver implements HostedPaymentDriver
{
    /** @var array<string, LookupResult> */
    public static array $provider = [];

    public function __construct(private string $gatewayKey) {}

    public function key(): string
    {
        return $this->gatewayKey;
    }

    public function name(): string
    {
        return 'Wallet '.$this->gatewayKey;
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['USD'];
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 1, max: 100_000_000);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(15);
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        return new FormPost('https://wallet.test/form', ['ref' => $intent->reference]);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        return new ReturnHint($request->string('ref')->toString() ?: null);
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        return self::$provider[$attempt->reference] ?? new LookupResult(LookupResult::NOT_FOUND);
    }
}

beforeEach(function () {
    LateRound2WalletDriver::$provider = [];

    foreach (['wallet_down', 'wallet_up'] as $key) {
        app(GatewayRegistry::class)->register(new LateRound2WalletDriver($key));
        Gateway::query()->create(['key' => $key, 'display_name' => $key, 'is_active' => true, 'show_on_order_form' => true, 'config' => []]);
    }
});

function lateRound2Attempt(string $gateway): PaymentAttempt
{
    return PaymentAttempt::query()->create([
        'reference' => PaymentAttempt::newReference(), 'gateway' => $gateway, 'client_id' => billingClient()->id,
        'amount' => 1_000, 'currency' => 'USD', 'document_amount' => 1_000, 'document_currency' => 'USD',
        'status' => AttemptStatus::Expired, 'expires_at' => now()->subHour(),
    ]);
}

test('a provider that is down does not use up the late sweep cap for the other providers', function () {
    billingSettings(['gateways.late_lookups_per_run' => 2]);
    // The down provider has the oldest backlog (lower ids): its first answer trips the breaker.
    $down = [lateRound2Attempt('wallet_down'), lateRound2Attempt('wallet_down')];
    $up = lateRound2Attempt('wallet_up');
    LateRound2WalletDriver::$provider[$down[0]->reference] = new LookupResult(LookupResult::PENDING, raw: ['error' => 'provider down'], retryable: true);

    $this->artisan('billing:reconcile-payments', ['--only' => 'late'])->assertSuccessful();
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'late'])->assertSuccessful();

    // Skipped rows keep their place at the front, so the healthy provider is never reached while the other is down.
    expect($up->fresh()->lookup_count)->toBeGreaterThan(0);
});
