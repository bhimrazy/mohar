<?php

namespace App\Modules\PaymentsStripe\Console\Commands;

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RefundRequest;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\StripeAmount;
use App\Modules\PaymentsStripe\Support\StripeClient;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * End-to-end check against a real Stripe sandbox (docs/product/payments-stripe.md §9, criterion 13): connection,
 * a Checkout Session created, looked up and expired, then a server-confirmed test payment (pm_card_visa) refunded
 * in two parts through the driver. Test keys only; nothing is written to our database and no key is printed.
 */
class StripeSmoke extends Command
{
    /**
     * The key comes from STRIPE_SMOKE_SECRET_KEY or a hidden prompt, never an option, so it stays out of shell
     * history and the process list.
     */
    protected $signature = 'payments:stripe-smoke
        {--currency=usd : Currency of the test payment}
        {--amount=1000 : Amount in minor units}';

    protected $description = 'Run a test-mode Stripe payment and refund end to end with the given test key';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $steps = [];

    public function handle(StripeDriver $driver, StripeClient $client): int
    {
        if (! SandboxPolicy::allowed()) {
            $this->error('Test money is not allowed in this environment (production). Run the smoke test elsewhere.');

            return self::FAILURE;
        }

        // The return URLs are built from the Billing module's route; without Billing loaded the check cannot run.
        if (! Route::has('billing.gateways.return')) {
            $this->error('The billing.gateways.return route is not registered. Run the smoke test where the Billing module is enabled.');

            return self::FAILURE;
        }

        $key = getenv('STRIPE_SMOKE_SECRET_KEY') ?: $this->secret('Stripe test secret key (sk_test_… or rk_test_…)');
        $key = is_string($key) ? trim($key) : '';

        if (preg_match('/^(sk|rk)_test_/', $key) !== 1) {
            $this->error('Use a test-mode key (sk_test_… or rk_test_…). Live keys are refused.');

            return self::FAILURE;
        }

        $currency = strtoupper((string) $this->option('currency'));
        $amount = (int) $this->option('amount');
        $money = Money::of($amount, $currency);
        $gateway = new GatewayConfig(StripeDriver::KEY, 'Stripe (smoke test)', ['sandbox' => '1', 'secret_key' => $key], sandbox: true);

        try {
            // The key is masked by value in anything logged or reported during the checks (ADR 0023 §5).
            RedactionScope::during(RedactionSet::of([$key]), fn () => $this->runChecks($driver, $client, $gateway, $money));
        } catch (Throwable $exception) {
            $this->step('unexpected error', false, str_replace($key, '[key]', $exception->getMessage()));
        }

        $this->table(['Step', 'Result', 'Detail'], $this->steps);
        $failed = collect($this->steps)->contains(fn (array $step): bool => $step[1] === 'FAIL');
        $failed ? $this->error('Stripe smoke test failed.') : $this->info('Stripe smoke test passed. Record this output in the PR.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function runChecks(StripeDriver $driver, StripeClient $client, GatewayConfig $gateway, Money $money): void
    {
        $check = $driver->testConnection($gateway);

        if (! $this->step('connection', $check->ok, $check->message)) {
            return;
        }

        if (! $this->step('currency', StripeAmount::toStripe($money) !== null, $money->currency.' '.$money->amount.' minor units → '.(StripeAmount::toStripe($money) ?? 'not expressible').' Stripe units')) {
            return;
        }

        // 1. Checkout Session: create, look up (open), expire, look up (expired).
        $reference = 'smoke-'.Str::lower(Str::random(12));
        $session = $driver->initiate($gateway, new PaymentIntent($reference, $money, 'Smoke test '.$reference, route('billing.gateways.return', StripeDriver::KEY), route('billing.gateways.return', [StripeDriver::KEY, 'ref' => $reference, 'cancelled' => 1])));
        $this->step('checkout session created', (string) $session->providerRef !== '', $session->providerRef.' '.$session->url);
        $ref = new PaymentAttemptRef($reference, $money, providerRef: $session->providerRef);
        $open = $driver->lookup($gateway, $ref);
        $this->step('checkout session lookup', $open->status === LookupResult::PENDING, $open->status);
        $client->post($gateway, 'checkout/sessions/'.$session->providerRef.'/expire', [], 'mohar-expire-'.$reference);
        $expired = $driver->lookup($gateway, $ref);
        $this->step('checkout session expired', $expired->status === LookupResult::EXPIRED, $expired->status);

        // 2. A confirmed test payment with Stripe's test card payment method.
        $response = $client->post($gateway, 'payment_intents', [
            'amount' => StripeAmount::toStripe($money),
            'currency' => strtolower($money->currency),
            'payment_method' => 'pm_card_visa',
            'confirm' => 'true',
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'description' => 'Smoke test '.$reference,
            'metadata' => ['mohar_reference' => $reference, 'mohar_flow' => 'smoke'],
        ], 'mohar-smoke-'.$reference);
        $intent = $response->json('id');

        if (! $this->step('test payment', $response->successful() && $response->json('status') === 'succeeded' && is_string($intent), is_string($intent) ? $intent.' '.$response->json('status') : StripeClient::errorSummary($response))) {
            return;
        }

        // 3. Refund in two parts through the driver, exactly as staff would.
        $half = intdiv($money->amount, 2);
        $first = $driver->refund($gateway, new RefundRequest($intent, Money::of($half, $money->currency), $money));
        $this->step('partial refund', $first->status === ChargeResult::SUCCESS, ($first->txnId ?? '').' '.($first->declineReason ?? (string) ($first->raw['status'] ?? '')));
        $rest = $driver->refund($gateway, new RefundRequest($intent, Money::of($money->amount - $half, $money->currency), $money));
        $this->step('refund of the rest', $rest->status === ChargeResult::SUCCESS, ($rest->txnId ?? '').' '.($rest->declineReason ?? (string) ($rest->raw['status'] ?? '')));

        $after = $client->get($gateway, 'payment_intents/'.$intent, ['latest_charge']);
        $refunded = $after->json('latest_charge.amount_refunded');
        $this->step('fully refunded at Stripe', $refunded === StripeAmount::toStripe($money), 'amount_refunded '.(is_int($refunded) ? $refunded : 'unknown'));
    }

    private function step(string $name, bool $ok, string $detail): bool
    {
        $this->steps[] = [$name, $ok ? 'OK' : 'FAIL', mb_substr($detail, 0, 160)];

        return $ok;
    }
}
