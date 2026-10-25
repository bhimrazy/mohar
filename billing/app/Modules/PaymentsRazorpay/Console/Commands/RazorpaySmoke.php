<?php

namespace App\Modules\PaymentsRazorpay\Console\Commands;

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RefundRequest;
use App\Enums\HostedSessionCancel;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * End-to-end check against a real Razorpay test account (docs/product/payments-gateways-expansion.md §13, criterion
 * 26): connection, a payment link created, looked up and cancelled; with --pay, a second link the operator pays in the
 * browser (test card or success@razorpay), confirmed by lookup and refunded in two parts through the driver. Test keys
 * only; nothing is written to our database and no secret is printed.
 */
class RazorpaySmoke extends Command
{
    /**
     * The secret comes from RAZORPAY_SMOKE_KEY_SECRET or a hidden prompt, never an option, so it stays out of shell
     * history and the process list.
     */
    protected $signature = 'payments:razorpay-smoke
        {--amount=10000 : Amount in paise}
        {--pay : Also open a link to pay in the browser, then refund it}
        {--wait=300 : Seconds to wait for the payment with --pay}';

    protected $description = 'Run a test-mode Razorpay payment link, and with --pay a payment and refund, with the given test keys';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $steps = [];

    public function handle(RazorpayDriver $driver): int
    {
        if (! SandboxPolicy::allowed()) {
            $this->error('Test money is not allowed in this environment (production). Run the smoke test elsewhere.');

            return self::FAILURE;
        }

        if (! Route::has('billing.gateways.return')) {
            $this->error('The billing.gateways.return route is not registered. Run the smoke test where the Billing module is enabled.');

            return self::FAILURE;
        }

        $id = trim((string) (getenv('RAZORPAY_SMOKE_KEY_ID') ?: $this->ask('Razorpay test key id (rzp_test_…)')));
        $secret = trim((string) (getenv('RAZORPAY_SMOKE_KEY_SECRET') ?: $this->secret('Razorpay test key secret')));

        if (! str_starts_with($id, 'rzp_test_') || $secret === '') {
            $this->error('Use a test-mode key id (rzp_test_…) and its secret. Live keys are refused.');

            return self::FAILURE;
        }

        $gateway = new GatewayConfig(RazorpayDriver::KEY, 'Razorpay (smoke test)', ['key_id' => $id, 'key_secret' => $secret], sandbox: true);
        $money = Money::of(max(100, (int) $this->option('amount')), 'INR');

        try {
            RedactionScope::during(RedactionSet::of([$secret]), fn () => $this->runChecks($driver, $gateway, $money));
        } catch (Throwable $exception) {
            $this->step('unexpected error', false, str_replace($secret, '[secret]', $exception->getMessage()));
        }

        $this->table(['Step', 'Result', 'Detail'], $this->steps);
        $failed = collect($this->steps)->contains(fn (array $step): bool => $step[1] === 'FAIL');
        $failed ? $this->error('Razorpay smoke test failed.') : $this->info('Razorpay smoke test passed. Record this output in the PR.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function runChecks(RazorpayDriver $driver, GatewayConfig $gateway, Money $money): void
    {
        $check = $driver->testConnection($gateway);

        if (! $this->step('connection', $check->ok, $check->message)) {
            return;
        }

        $ref = $this->link($driver, $gateway, $money);
        $open = $driver->lookup($gateway, $ref);
        $this->step('link lookup', $open->status === LookupResult::PENDING, $open->status);
        $this->step('link cancelled', $driver->cancelSession($gateway, $ref) === HostedSessionCancel::Closed, 'cancel');
        $cancelled = $driver->lookup($gateway, $ref);
        $this->step('cancelled link lookup', $cancelled->status === LookupResult::CANCELLED, $cancelled->status);

        if (! $this->option('pay')) {
            return;
        }

        $ref = $this->link($driver, $gateway, $money);
        $this->line('Pay this link in the browser (a test card, or UPI success@razorpay): '.$driver->resumeUrl($gateway, $ref));
        $deadline = now()->addSeconds(max(10, (int) $this->option('wait')));

        do {
            Sleep::for(5)->seconds();
            $paid = $driver->lookup($gateway, $ref);
        } while ($paid->status === LookupResult::PENDING && now()->lt($deadline));

        if (! $this->step('payment confirmed by lookup', $paid->succeeded() && $paid->amount === $money->amount, $paid->status.' '.($paid->providerTxnId ?? '').' fee '.$paid->fee)) {
            return;
        }

        $half = intdiv($money->amount, 2);

        foreach (['partial refund' => $half, 'refund of the rest' => $money->amount - $half] as $name => $amount) {
            $refund = $driver->refund($gateway, new RefundRequest((string) $paid->providerTxnId, Money::of($amount, 'INR'), $money, reference: 'smoke-'.Str::lower(Str::random(8))));
            $this->step($name, $refund->status === ChargeResult::SUCCESS, ($refund->txnId ?? '').' '.($refund->declineReason ?? (string) ($refund->raw['status'] ?? '')));
        }

        $after = $driver->lookup($gateway, $ref);
        $this->step('fully refunded at Razorpay', $after->status === LookupResult::REFUNDED, $after->status);
    }

    private function link(RazorpayDriver $driver, GatewayConfig $gateway, Money $money): PaymentAttemptRef
    {
        $reference = 'smoke-'.Str::lower(Str::random(12));
        $link = $driver->initiate($gateway, new PaymentIntent($reference, $money, 'Smoke test '.$reference, route('billing.gateways.return', RazorpayDriver::KEY), route('billing.gateways.return', RazorpayDriver::KEY)));
        $this->step('payment link created', (string) $link->providerRef !== '', $link->providerRef.' '.$link->url);

        return new PaymentAttemptRef($reference, $money, providerRef: $link->providerRef);
    }

    private function step(string $name, bool $ok, string $detail): bool
    {
        $this->steps[] = [$name, $ok ? 'OK' : 'FAIL', mb_substr($detail, 0, 160)];

        return $ok;
    }
}
