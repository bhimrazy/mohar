<?php

use App\Data\Billing\ChargeResult;
use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    FakeRazorpay::install();
    razorpayGateway();
});

/** A Rs 1,130.00 invoice paid through a Razorpay payment link and settled by the return. */
function paidRazorpayPayment(): Transaction
{
    [, $invoice, $attempt] = startRazorpay(113_000);
    razorpayPaid($attempt);
    test()->get(razorpayReturn($attempt));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    return Transaction::query()->where('gateway', RazorpayDriver::KEY)->where('type', TransactionType::Payment)->sole();
}

test('staff refund part of a payment and then the rest in paise, tagged with our refund rows, and the webhooks book nothing more', function () {
    $payment = paidRazorpayPayment();

    app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    app(RefundTransaction::class)->handle($payment->fresh(), 100_000, RefundMode::Gateway);

    $rows = Transaction::query()->where('type', TransactionType::Refund)->orderBy('id')->get();
    $sent = Http::recorded(fn (Request $request) => $request->method() === 'POST' && $request->url() === RZP_API.'payments/'.RZP_PAYMENT.'/refund')->map(fn (array $pair) => $pair[0]->data())->values()->all();
    expect($sent)->toBe([
        ['amount' => 13_000, 'speed' => 'normal', 'notes' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $rows[0]->id]],
        ['amount' => 100_000, 'speed' => 'normal', 'notes' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $rows[1]->id]],
    ])
        ->and($rows->pluck('gateway_txn_id')->all())->toBe(['rfnd_MoharRefund1', 'rfnd_MoharRefund2'])
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);

    foreach (['rfnd_MoharRefund1', 'rfnd_MoharRefund2'] as $i => $id) {
        postRazorpayWebhook(rzpEvent('refund.processed', ['refund' => ['id' => $id]]), eventId: 'MoharRefundEvent'.$i)->assertOk()->assertJson(['result' => 'duplicate']);
    }

    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(2);
});

test('a refund that timed out stays pending with its amount held, and its webhook settles that same row', function () {
    $payment = paidRazorpayPayment();
    FakeRazorpay::$refund = fn () => throw new ConnectionException('timed out');

    $pending = app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    expect($pending->status)->toBe(TransactionStatus::Pending)->and($payment->fresh()->refundable_remaining)->toBe(100_000);

    FakeRazorpay::$refunds['rfnd_MoharLate00001'] = rzpRefund('rfnd_MoharLate00001', 13_000, ['notes' => ['mohar_origin' => 'admin', 'mohar_refund' => (string) $pending->id]]);
    postRazorpayWebhook(rzpEvent('refund.processed', ['refund' => ['id' => 'rfnd_MoharLate00001']]))->assertOk()->assertJson(['result' => 'refund recorded']);

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('rfnd_MoharLate00001');
});

test('Check refund finds the refund by our reference without sending it again, and calls it not made only after the settle time', function () {
    $payment = paidRazorpayPayment();
    $posts = 0;
    FakeRazorpay::$refund = function () use (&$posts) {
        $posts++;

        throw new ConnectionException('timed out');
    };
    $first = app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    FakeRazorpay::$refunds['rfnd_MoharFound0001'] = rzpRefund('rfnd_MoharFound0001', 13_000, ['notes' => ['mohar_refund' => (string) $first->id]]);

    expect(app(RefundTransaction::class)->check($first))->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('rfnd_MoharFound0001');

    $second = app(RefundTransaction::class)->handle($payment->fresh(), 20_000, RefundMode::Gateway);
    expect(app(RefundTransaction::class)->check($second)->status)->toBe(TransactionStatus::Pending);

    $this->travel(16)->minutes();
    expect(app(RefundTransaction::class)->check($second->fresh())->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(100_000)
        ->and($posts)->toBe(2);
});

test('a refund Razorpay refuses leaves the payment refundable', function () {
    $payment = paidRazorpayPayment();
    FakeRazorpay::$refund = fn () => 400;

    expect(fn () => app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway))->toThrow(BillingException::class)
        ->and($payment->fresh()->refundable_remaining)->toBe(113_000)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed);
});

test('Check refund on a refund tagged with our reference but for another amount stays pending and takes no refund id', function () {
    $payment = paidRazorpayPayment();
    FakeRazorpay::$refund = fn () => throw new ConnectionException('timed out');
    $pending = app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    FakeRazorpay::$refunds['rfnd_MoharOther0001'] = rzpRefund('rfnd_MoharOther0001', 20_000, ['notes' => ['mohar_refund' => (string) $pending->id]]);

    $result = app(RazorpayDriver::class)->lookupRefund(
        app(GatewayManager::class)->config(RazorpayDriver::KEY),
        new RefundRequest(RZP_PAYMENT, Money::of(13_000, 'INR'), Money::of(113_000, 'INR'), reference: (string) $pending->id),
    );

    expect($result)->status->toBe(ChargeResult::ERROR)->txnId->toBeNull()->raw->toMatchArray(['refund' => 'rfnd_MoharOther0001'])
        ->and(app(RefundTransaction::class)->check($pending))->status->toBe(TransactionStatus::Pending)->gateway_txn_id->toBeNull();
});
