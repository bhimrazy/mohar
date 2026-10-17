<?php

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceLineData;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoicePartiallyPaid;
use App\Events\Billing\InvoiceRefunded;
use App\Events\Billing\PaymentReceived;
use App\Events\Billing\PaymentReversed;
use App\Events\Orders\OrderAccepted;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\MarkInvoicePaid;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Actions\RecordManualTransaction;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Actions\RemoveCredit;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

function creditOf(int $clientId): int
{
    return app(CreditLedger::class)->balance($clientId, 'USD')->amount;
}

test('a partial payment leaves the invoice unpaid with a reduced balance', function () {
    Event::fake([InvoicePartiallyPaid::class, InvoicePaid::class, PaymentReceived::class]);
    $invoice = issueInvoice(billingClient(), [10_000]);

    payInvoice($invoice, 4_000, 'TX-1');

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(6_000)->amount_paid->toBe(4_000);
    Event::assertDispatched(InvoicePartiallyPaid::class, fn ($event) => $event->balance === 6_000);
    Event::assertDispatched(PaymentReceived::class, fn ($event) => $event->amount === 4_000 && $event->invoiceId === $invoice->id);
    Event::assertNotDispatched(InvoicePaid::class);
});

test('an overpayment pays the invoice and moves the excess to credit', function () {
    Event::fake([InvoicePaid::class]);
    $client = billingClient();
    $invoice = issueInvoice($client, [10_000]);
    payInvoice($invoice, 4_000);

    payInvoice($invoice, 7_500);

    $invoice->refresh();
    expect($invoice)->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(creditOf($client->id))->toBe(1_500)
        ->and($invoice->adjustments()->where('reason', 'overpayment')->where('kind', 'debit')->value('amount'))->toBe(1_500);
    Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $event) => $event->invoiceId === $invoice->id
        && count($event->lines) === 1 && $event->lines[0] instanceof InvoiceLineData && $event->lines[0]->unitAmount === 10_000);
});

test('the same gateway transaction is recorded once and InvoicePaid fires once', function () {
    Event::fake([InvoicePaid::class]);
    $invoice = issueInvoice(billingClient(), [5_000]);

    payInvoice($invoice, 5_000, 'TX-DUP', 'sandbox_card');
    payInvoice($invoice, 5_000, 'TX-DUP', 'sandbox_card');

    expect(Transaction::query()->where('gateway_txn_id', 'TX-DUP')->count())->toBe(1)
        ->and($invoice->fresh()->amount_paid)->toBe(5_000);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('a payment on a cancelled invoice is kept as account credit', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    app(Invoicer::class)->cancel($invoice->id);

    payInvoice($invoice, 5_000, 'LATE-1');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(creditOf($client->id))->toBe(5_000);
});

test('payments must be positive', function () {
    payInvoice(issueInvoice(billingClient()), 0);
})->throws(BillingException::class);

test('available credit is applied automatically to generated invoices', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(2_000, 'USD'), 'Goodwill');

    $invoice = issueInvoice($client, [1_500]);

    expect($invoice)->status->toBe(InvoiceStatus::Paid)->credit_applied->toBe(1_500)
        ->and(creditOf($client->id))->toBe(500);
});

test('credit is not auto-applied when the setting is off', function () {
    billingSettings(['credit.auto_apply_on_generate' => false]);
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(2_000, 'USD'), 'Goodwill');

    expect(issueInvoice($client, [1_500])->status)->toBe(InvoiceStatus::Unpaid)
        ->and(issueInvoice($client, [1_500], applyCredit: true)->status)->toBe(InvoiceStatus::Paid);
});

test('credit can be applied manually and removed again', function () {
    billingSettings(['credit.auto_apply_on_generate' => false]);
    $client = billingClient();
    $invoice = issueInvoice($client, [3_000]);
    app(CreditLedger::class)->credit($client->id, Money::of(2_000, 'USD'), 'Goodwill');

    app(ApplyCredit::class)->handle($invoice, 2_000);
    expect($invoice->fresh())->balance->toBe(1_000)->credit_applied->toBe(2_000)->and(creditOf($client->id))->toBe(0);

    app(RemoveCredit::class)->handle($invoice, 500);
    expect($invoice->fresh())->balance->toBe(1_500)->credit_applied->toBe(1_500)->and(creditOf($client->id))->toBe(500);
});

test('applying more credit than available or than the balance is rejected', function (int $amount) {
    billingSettings(['credit.auto_apply_on_generate' => false]);
    $client = billingClient();
    $invoice = issueInvoice($client, [3_000]);
    app(CreditLedger::class)->credit($client->id, Money::of(1_000, 'USD'), 'Goodwill');

    app(ApplyCredit::class)->handle($invoice, $amount);
})->with([2_000, 4_000, 0])->throws(BillingException::class);

test('the credit ledger contract refuses to go negative', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(500, 'USD'), 'Goodwill');

    app(CreditLedger::class)->debit($client->id, Money::of(600, 'USD'), 'Too much');
})->throws(DomainException::class);

test('add funds creates an untaxed add-funds invoice that credits the balance when paid', function () {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    app(CreditLedger::class)->credit($client->id, Money::of(5_000, 'USD'), 'Existing');

    $invoice = app(CreateAddFundsInvoice::class)->handle($client, 2_500);

    expect($invoice)->kind->value->toBe('add_funds')->status->toBe(InvoiceStatus::Unpaid)->credit_applied->toBe(0)->total->toBe(2_500);

    payInvoice($invoice, 2_500);

    expect(creditOf($client->id))->toBe(7_500)
        ->and(fn () => app(ApplyCredit::class)->handle(app(CreateAddFundsInvoice::class)->handle($client, 1_000), 500))->toThrow(BillingException::class);
});

test('add funds enforces the deposit limits', function (int $amount) {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);

    app(CreateAddFundsInvoice::class)->handle($client, $amount);
})->with([500, 200_000])->throws(BillingException::class);

test('add funds requires an accepted order when configured', function () {
    $client = billingClient();
    $action = app(CreateAddFundsInvoice::class);

    expect($action->unavailableReason($client))->not->toBeNull();

    event(new OrderAccepted(1, $client->id));

    expect($action->unavailableReason($client))->toBeNull();
});

test('a record-only partial refund keeps the invoice paid', function () {
    Event::fake([InvoiceRefunded::class, PaymentReversed::class]);
    $invoice = issueInvoice(billingClient(), [5_000]);
    $payment = payInvoice($invoice, 5_000);

    app(RefundTransaction::class)->handle($payment, 2_000, RefundMode::RecordOnly);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and($payment->fresh()->refundable_remaining)->toBe(3_000)
        ->and($invoice->adjustments()->where('reason', 'refund')->where('kind', 'credit')->value('amount'))->toBe(2_000);
    Event::assertDispatched(InvoiceRefunded::class, fn ($event) => $event->amount === 2_000 && ! $event->fullRefund);
    Event::assertNotDispatched(PaymentReversed::class);

    app(RefundTransaction::class)->handle($payment->fresh(), 3_000, RefundMode::RecordOnly, reverseEffects: true);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
    Event::assertDispatched(InvoiceRefunded::class, fn ($event) => $event->fullRefund);
    Event::assertDispatched(PaymentReversed::class);
});

test('a refund larger than the refundable remainder is rejected', function () {
    $payment = payInvoice(issueInvoice(billingClient(), [5_000]), 5_000);
    app(RefundTransaction::class)->handle($payment, 2_000, RefundMode::RecordOnly);

    app(RefundTransaction::class)->handle($payment->fresh(), 3_001, RefundMode::RecordOnly);
})->throws(BillingException::class);

test('refunds can go to account credit', function () {
    $client = billingClient();
    $payment = payInvoice(issueInvoice($client, [5_000]), 5_000);

    $refund = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::ToCredit);

    expect($refund)->type->toBe(TransactionType::Refund)->amount_out->toBe(1_000)->parent_transaction_id->toBe($payment->id)
        ->and(creditOf($client->id))->toBe(1_000);
});

test('gateway refunds go through the driver', function () {
    sandboxGateway();
    $payment = payInvoice(issueInvoice(billingClient(), [5_000]), 5_000, 'sbx_ch_1', 'sandbox_card');

    $refund = app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::Gateway);

    expect($refund->gateway_txn_id)->toStartWith('sbx_re_');
});

test('offline gateways cannot refund automatically', function () {
    $payment = payInvoice(issueInvoice(billingClient(), [5_000]), 5_000);

    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::Gateway);
})->throws(BillingException::class);

test('staff mark paid records a write-off instead of income', function () {
    Event::fake([InvoicePaid::class]);
    $invoice = issueInvoice(billingClient(), [5_000]);
    payInvoice($invoice, 1_000);

    app(MarkInvoicePaid::class)->handle($invoice);

    $invoice->refresh();
    expect($invoice)->status->toBe(InvoiceStatus::Paid)->amount_paid->toBe(1_000)
        ->and($invoice->adjustments()->where('reason', 'write_off')->value('amount'))->toBe(4_000)
        ->and($invoice->transactions()->where('amount_in', 0)->where('gateway', 'manual')->exists())->toBeTrue();
    Event::assertDispatched(InvoicePaid::class);
});

test('a manual income transaction is applied to invoices in order with the remainder to credit', function () {
    $client = billingClient();
    $first = issueInvoice($client, [1_000]);
    $second = issueInvoice($client, [2_000]);

    $transactions = app(RecordManualTransaction::class)->handle($client, TransactionType::ManualIncome, 3_500, 'bank_transfer', 'WIRE-9', null, now()->toImmutable(), [$second->id, $first->id]);

    expect($transactions)->toHaveCount(3)
        ->and($first->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($second->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(creditOf($client->id))->toBe(500);
});

test('a payment batch pays several invoices and credits allocations of invoices paid meanwhile', function () {
    sandboxGateway();
    billingSettings(['credit.auto_apply_on_generate' => false]);
    $client = billingClient();
    $a = issueInvoice($client, [1_000], now()->addDay()->toImmutable());
    $b = issueInvoice($client, [2_000], now()->addDays(2)->toImmutable());
    $batch = app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]);

    expect($batch->total)->toBe(3_000);

    payInvoice($a, 1_000);
    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'sbx_ch_batch', 3_000);

    expect($b->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(creditOf($client->id))->toBe(1_000)
        ->and($batch->fresh()->status)->toBe('paid');
});

test('a payment batch charged by card pays both invoices', function () {
    sandboxGateway();
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);
    $batch = app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]);
    $token = (new SandboxCardDriver)->tokenize('4242424242424242', 12, 2030)->token;

    $result = app(PaymentBatches::class)->pay($batch, 'sandbox_card', $token);

    expect($result->succeeded())->toBeTrue()
        ->and($a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::Paid);
});
