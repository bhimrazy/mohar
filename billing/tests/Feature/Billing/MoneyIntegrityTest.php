<?php

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Billing\Invoicer;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CurrencyConverter;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

require_once __DIR__.'/WebhookHelpers.php';

/**
 * Sandbox driver that records what it was asked to refund and can be told to fail.
 */
class RecordingSandboxDriver extends SandboxCardDriver
{
    /** @var list<RefundRequest> */
    public static array $refunds = [];

    public static bool $fail = false;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        self::$refunds[] = $request;

        return self::$fail ? new ChargeResult(ChargeResult::ERROR, declineReason: 'provider_down', raw: ['error' => 'down']) : parent::refund($gateway, $request);
    }
}

beforeEach(function () {
    RecordingSandboxDriver::$refunds = [];
    RecordingSandboxDriver::$fail = false;
    app(GatewayManager::class)->register(new RecordingSandboxDriver);
    sandboxGateway();
});

test('cancelling an invoice returns the applied credit to the client', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(500, 'USD'), 'Goodwill');
    $invoice = issueInvoice($client, [2_000]);
    expect($invoice->credit_applied)->toBe(500);

    app(Invoicer::class)->cancel($invoice->id);

    expect(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(500)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0)->credit_applied->toBe(0);
});

test('cancelling lines down to a zero balance settles the invoice', function () {
    Event::fake([InvoicePaid::class]);
    $invoice = issueInvoice(billingClient(), [5_000, 5_000]);
    payInvoice($invoice, 5_000);
    $second = $invoice->items()->orderByDesc('id')->firstOrFail();

    app(CancelInvoiceItems::class)->handle($invoice, [$second->id]);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0);
    Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $event) => count($event->lines) === 1);
});

test('cancelling a discount line raises the balance again', function () {
    $invoice = issueInvoice(billingClient(), [5_000, -1_000]);
    $discount = $invoice->items()->where('unit_amount', -1_000)->firstOrFail();

    app(CancelInvoiceItems::class)->handle($invoice, [$discount->id]);

    expect($invoice->fresh()->balance)->toBe(5_000);
});

test('negative-total invoices are rejected', function () {
    issueInvoice(billingClient(), [-500]);
})->throws(BillingException::class, 'can\'t be negative');

test('gateway refunds of converted payments are sent in the charged currency', function () {
    Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'exchange_rate' => 1, 'is_default' => false]);
    $client = Client::factory()->create(['currency' => 'EUR']);
    $invoice = issueInvoice($client, [10_000]);
    $payment = app(ApplyPayment::class)->handle($invoice, new PaymentData(
        10_000, 'sandbox_card', 'ch_fx', gatewayAmount: 11_000, gatewayCurrency: 'USD', exchangeRate: 1.1, fromGateway: true,
    ));

    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::Gateway);

    expect(RecordingSandboxDriver::$refunds[0])
        ->providerTxnId->toBe('ch_fx')
        ->and(RecordingSandboxDriver::$refunds[0]->amount->amount)->toBe(5_500)
        ->and(RecordingSandboxDriver::$refunds[0]->amount->currency)->toBe('USD')
        ->and(RecordingSandboxDriver::$refunds[0]->originalAmount->amount)->toBe(11_000);
});

test('issued invoices and recorded payments keep the exchange rate in force when they were created', function () {
    Currency::default(); // USD
    Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'exchange_rate' => '0.900000', 'is_default' => false]);
    $client = Client::factory()->create(['currency' => 'EUR']);
    $invoice = issueInvoice($client, [10_000]);
    $converter = app(CurrencyConverter::class);
    $rate = $converter->rate('EUR', 'USD'); // the rate in force when the gateway charges: 1 / 0.9 → 1.111111
    $payment = app(ApplyPayment::class)->handle($invoice, new PaymentData(
        10_000, 'sandbox_card', 'ch_locked', gatewayAmount: $converter->convert(10_000, $rate), gatewayCurrency: 'USD', exchangeRate: $rate, fromGateway: true,
    ));
    $total = $invoice->fresh()->total;

    Currency::query()->where('code', 'EUR')->update(['exchange_rate' => '0.500000']); // a later rate update
    app(RefundTransaction::class)->handle($payment, 5_000, RefundMode::Gateway);

    expect($rate)->toBe(1.111111)
        ->and($invoice->fresh()->total)->toBe($total)
        ->and($payment->fresh())->exchange_rate->toBe('1.111111')->gateway_amount->toBe(11_111)
        ->and(RecordingSandboxDriver::$refunds[0]->amount->amount)->toBeIn([5_555, 5_556]); // stored rate; the new one would give 10,000
});

test('refund and chargeback webhooks are converted back to the invoice currency', function () {
    Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'exchange_rate' => 1, 'is_default' => false]);
    $client = Client::factory()->create(['currency' => 'EUR']);
    $invoice = issueInvoice($client, [10_000]);
    $payment = app(ApplyPayment::class)->handle($invoice, new PaymentData(
        10_000, 'sandbox_card', 'ch_fx2', gatewayAmount: 11_000, gatewayCurrency: 'USD', exchangeRate: 1.1, fromGateway: true,
    ));

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_fx', 'parent_txn_id' => 'ch_fx2', 'amount' => 2_200, 'currency' => 'USD'])->assertOk();
    expect($payment->fresh()->refundable_remaining)->toBe(8_000);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_fx', 'parent_txn_id' => 'ch_fx2', 'amount' => 8_800, 'currency' => 'USD'])->assertOk();
    expect($payment->fresh()->refundable_remaining)->toBe(0)
        ->and(Transaction::query()->where('gateway_txn_id', 'dp_fx')->value('amount_out'))->toBe(8_000);
});

test('unknown callback types are ignored, not booked as chargebacks', function () {
    $payment = payInvoice(issueInvoice(billingClient(), [1_000]), 1_000, 'ch_x', 'sandbox_card');

    $outcome = app(GatewayCallbackHandler::class)->process('sandbox_card', new CallbackEvent('dispute.won', $payment->invoice_id, 'evt_1', 1_000, 'USD', 'ch_x'));

    expect($outcome)->toBe(['status' => 200, 'result' => 'ignored event type'])
        ->and(Transaction::query()->where('type', 'chargeback')->count())->toBe(0)
        ->and($payment->fresh()->refundable_remaining)->toBe(1_000);
});

test('a refund of money that went to credit is acknowledged for staff review', function () {
    $invoice = issueInvoice(billingClient(), [1_000]);
    app(Invoicer::class)->cancel($invoice->id);
    payInvoice($invoice, 1_000, 'ch_closed', 'sandbox_card');

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_closed', 'parent_txn_id' => 'ch_closed', 'amount' => 1_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'refund needs staff review']);
});

test('refunds of combined payments go to the real batch charge', function () {
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);
    $batch = app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]);
    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'ch_batch_real', 3_000);
    $allocation = Transaction::query()->where('invoice_id', $b->id)->firstOrFail();

    app(RefundTransaction::class)->handle($allocation, 2_000, RefundMode::Gateway);

    expect(RecordingSandboxDriver::$refunds[0]->providerTxnId)->toBe('ch_batch_real')
        ->and(RecordingSandboxDriver::$refunds[0]->originalAmount->amount)->toBe(3_000);

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_batch', 'parent_txn_id' => 'ch_batch_real', 'amount' => 1_000, 'currency' => 'USD'])->assertOk();
    expect($a->fresh()->status)->toBe(InvoiceStatus::Collections);
});

test('a failed gateway refund keeps its log and restores the refundable amount', function () {
    RecordingSandboxDriver::$fail = true;
    $payment = payInvoice(issueInvoice(billingClient(), [1_000]), 1_000, 'ch_fail', 'sandbox_card');

    expect(fn () => app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway))->toThrow(BillingException::class, 'provider_down');

    expect($payment->fresh()->refundable_remaining)->toBe(1_000)
        ->and(Transaction::query()->where('type', 'refund')->value('status'))->toBe(TransactionStatus::Failed)
        ->and(GatewayLog::query()->where('result', 'refund error')->exists())->toBeTrue();
});
