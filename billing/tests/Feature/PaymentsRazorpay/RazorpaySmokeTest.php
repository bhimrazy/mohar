<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    FakeRazorpay::install();
});

afterEach(function () {
    putenv('RAZORPAY_SMOKE_KEY_ID');
    putenv('RAZORPAY_SMOKE_KEY_SECRET');
});

test('the smoke command opens, checks and cancels a link, and with --pay refunds the paid link in two parts, never printing the secret', function () {
    Sleep::fake();
    // The operator pays the second link while the command waits.
    Sleep::whenFakingSleep(function () {
        $link = FakeRazorpay::$links[RZP_LINK];
        FakeRazorpay::$links[RZP_LINK] = [...$link, 'status' => 'paid', 'payments' => [['payment_id' => RZP_PAYMENT, 'status' => 'captured']]];
        FakeRazorpay::$payments[RZP_PAYMENT] ??= rzpPayment($link['amount'], $link['reference_id']);
    });
    FakeRazorpay::$refund = function (Request $request) {
        $payment = FakeRazorpay::$payments[RZP_PAYMENT];
        $refunded = $payment['amount_refunded'] + $request['amount'];
        FakeRazorpay::$payments[RZP_PAYMENT] = [...$payment, 'amount_refunded' => $refunded, 'status' => $refunded >= $payment['amount'] ? 'refunded' : 'captured'];

        return rzpRefund('rfnd_MoharSmoke'.$refunded, $request['amount']);
    };
    putenv('RAZORPAY_SMOKE_KEY_ID='.RZP_KEY_ID);
    putenv('RAZORPAY_SMOKE_KEY_SECRET='.RZP_KEY_SECRET);

    $this->artisan('payments:razorpay-smoke', ['--pay' => true])
        ->expectsOutputToContain('Razorpay smoke test passed')
        ->doesntExpectOutputToContain(RZP_KEY_SECRET)
        ->assertSuccessful();

    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/refund'))->map(fn (array $pair) => $pair[0]['amount'])->values()->all())->toBe([5_000, 5_000]);
});

test('the smoke command refuses live keys and production, and takes the secret from a hidden prompt, never an option', function () {
    putenv('RAZORPAY_SMOKE_KEY_ID=rzp_live_MoharFakeKey01');
    $this->artisan('payments:razorpay-smoke')->expectsQuestion('Razorpay test key secret', RZP_KEY_SECRET)
        ->expectsOutputToContain('Live keys are refused')->assertFailed();

    putenv('RAZORPAY_SMOKE_KEY_ID='.RZP_KEY_ID);
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('payments:razorpay-smoke')->expectsOutputToContain('not allowed in this environment')->assertFailed();

    Http::assertNothingSent();
});
