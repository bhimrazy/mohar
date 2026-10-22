<?php

use App\Contracts\Billing\CreditLedger;
use App\Data\Billing\CallbackEvent;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentReinstated;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Billing\Services\InvoicePdf;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/WebhookHelpers.php';

/**
 * #191: a dispute the merchant wins puts back what its chargeback booked, once per dispute id, and never more.
 */
beforeEach(function () {
    sandboxGateway();
});

function wonBalance(Client $client): int
{
    return app(CreditLedger::class)->balance($client->id, 'USD')->amount;
}

function wonTodo(string $key): int
{
    return collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', $key)['count'] ?? 0;
}

/**
 * An 8,000 payment of a 5,000 invoice (3,000 to credit), disputed in full: invoice in Collections, credit taken back.
 *
 * @return array{Client, Invoice}
 */
function disputedOverpayment(string $id): array
{
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => "ch_$id", 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => "dp_$id", 'parent_txn_id' => "ch_$id", 'amount' => 8_000, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'chargeback recorded; credit taken back']);

    return [$client, $invoice];
}

function winDispute(string $id, int $amount = 8_000): TestResponse
{
    return sandboxWebhook(['type' => 'charge.dispute.won', 'txn_id' => "dp_$id", 'parent_txn_id' => "ch_$id", 'amount' => $amount, 'currency' => 'USD']);
}

test('a won dispute pays the invoice again and restores the credit taken back, once', function () {
    [$client, $invoice] = disputedOverpayment('w1');
    expect($invoice->fresh())->status->toBe(InvoiceStatus::Collections)->balance->toBe(5_000)
        ->and(wonBalance($client))->toBe(0);

    winDispute('w1')->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);
    winDispute('w1')->assertOk()->assertJson(['result' => 'duplicate']);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)->amount_paid->toBe(8_000)
        ->and(wonBalance($client))->toBe(3_000)
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_w1')->sole()->refundable_remaining)->toBe(5_000)
        ->and(Transaction::query()->where('type', 'chargeback_reversal')->sole())->amount_in->toBe(8_000)->review_amount->toBeNull()
        ->and(CreditLedgerEntry::query()->where('external_ref', 'dp_w1:won')->sole()->amount)->toBe(3_000);
});

test('a won dispute clears a negative balance and the owing to-do', function () {
    $client = billingClient();
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => issueInvoice($client, [5_000])->id, 'txn_id' => 'ch_w2', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    app(CreditLedgerService::class)->post($client->id, Money::of(-3_000, 'USD'), CreditReason::AppliedToInvoice);
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w2', 'parent_txn_id' => 'ch_w2', 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    expect(wonBalance($client))->toBe(-3_000)->and(wonTodo('billing.credit_owed'))->toBe(1);

    winDispute('w2')->assertOk();

    expect(wonBalance($client))->toBe(0)->and(wonTodo('billing.credit_owed'))->toBe(0);
});

test('an invoice paid again since the dispute is left alone and the money goes to staff review, not to credit', function () {
    [$client, $invoice] = disputedOverpayment('w3');
    payInvoice($invoice->fresh(), 5_000);

    winDispute('w3')->assertOk()->assertJson(['result' => 'dispute won; needs staff review']);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->amount_paid->toBe(8_000)
        ->and(wonBalance($client))->toBe(3_000)
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_w3')->sole()->refundable_remaining)->toBe(0)
        ->and(Transaction::query()->where('type', 'chargeback_reversal')->sole()->review_amount)->toBe(5_000)
        ->and(wonTodo('billing.reversals_review'))->toBe(1);
});

test('a win that arrives before its dispute is refused for a retry, then booked once', function () {
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'ch_w4', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    winDispute('w4', 5_000)->assertStatus(409)->assertJson(['result' => 'dispute not booked yet; retry expected']);
    expect(Transaction::query()->where('type', 'chargeback_reversal')->exists())->toBeFalse();

    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w4', 'parent_txn_id' => 'ch_w4', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    winDispute('w4', 5_000)->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)->and(wonBalance($client))->toBe(0);
});

test('a won dispute of a deposit gives back exactly the deposit credit the chargeback removed', function () {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    $deposit = app(CreateAddFundsInvoice::class)->handle($client, 5_000);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $deposit->id, 'txn_id' => 'ch_w5', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    expect(wonBalance($client))->toBe(5_000);
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w5', 'parent_txn_id' => 'ch_w5', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();
    expect(wonBalance($client))->toBe(0);

    winDispute('w5', 5_000)->assertOk();
    winDispute('w5', 5_000)->assertOk()->assertJson(['result' => 'duplicate']);

    expect(wonBalance($client))->toBe(5_000)
        ->and($deposit->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0);
});

test('a won dispute of a combined charge reinstates every invoice of the batch', function () {
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);
    app(PaymentBatches::class)->settle(app(PaymentBatches::class)->create($client->id, [$a->id, $b->id]), 'sandbox_card', 'ch_w6', 3_000);
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w6', 'parent_txn_id' => 'ch_w6', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();
    expect([$a->fresh()->status, $b->fresh()->status])->toBe([InvoiceStatus::Collections, InvoiceStatus::Collections]);

    winDispute('w6', 3_000)->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);

    expect([$a->fresh()->status, $b->fresh()->status])->toBe([InvoiceStatus::Paid, InvoiceStatus::Paid])
        ->and(Transaction::query()->where('type', 'chargeback_reversal')->sole()->amount_in)->toBe(3_000)
        ->and(wonBalance($client))->toBe(0);
});

test('a won dispute clears the rest nobody covered from staff review', function () {
    $client = billingClient();
    payInvoice(issueInvoice($client, [5_000]), 5_000, 'ch_w7', 'sandbox_card');
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w7', 'parent_txn_id' => 'ch_w7', 'amount' => 5_500, 'currency' => 'USD'])
        ->assertOk()->assertJson(['result' => 'chargeback needs staff review']);
    expect(wonTodo('billing.reversals_review'))->toBe(1);

    winDispute('w7', 5_500)->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);

    expect(wonTodo('billing.reversals_review'))->toBe(0)
        ->and(Transaction::query()->where('type', 'chargeback_reversal')->sole()->amount_in)->toBe(5_500);
});

test('every won dispute fires PaymentReinstated once, also when the invoice still owes more or the money goes to review (#221)', function (string $case, int $amount, int $review, bool $paid) {
    Event::fake([PaymentReinstated::class, InvoicePaid::class]);
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    // "owing": 3,000 of 5,000 paid, so the win leaves 2,000 to pay; "settled": paid in full; "review": paid again since.
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => "ch_$case", 'amount' => $amount, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => "dp_$case", 'parent_txn_id' => "ch_$case", 'amount' => $amount, 'currency' => 'USD'])->assertOk();

    if ($case === 'review') {
        payInvoice($invoice->fresh(), 5_000);
    }

    $paidBefore = Event::dispatched(InvoicePaid::class)->count();
    winDispute($case, $amount)->assertOk();
    winDispute($case, $amount)->assertOk()->assertJson(['result' => 'duplicate']);

    $won = Transaction::query()->where('type', 'chargeback_reversal')->sole();
    $chargeback = Transaction::query()->where('type', 'chargeback')->sole();
    Event::assertDispatchedTimes(PaymentReinstated::class, 1);
    Event::assertDispatched(PaymentReinstated::class, fn (PaymentReinstated $event): bool => $event->invoiceId === $invoice->id
        && $event->clientId === $client->id && $event->transactionId === $won->id && $event->chargebackTransactionId === $chargeback->id
        && $event->amount === $amount && $event->currency === 'USD' && $event->reviewAmount === $review);
    // InvoicePaid comes with the win only when it settles the invoice.
    expect(Event::dispatched(InvoicePaid::class)->count() - $paidBefore)->toBe($paid ? 1 : 0);
})->with([
    'invoice still owes more' => ['owing', 3_000, 0, false],
    'invoice settled' => ['settled', 5_000, 0, true],
    'money to staff review' => ['review', 5_000, 5_000, false],
]);

test('webhook subscribers get payment.reversed for the chargeback and payment.reinstated for the win (#221)', function () {
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/billing', 'events' => ['payment.reversed', 'payment.reinstated'], 'secret' => 'whsec_test']);
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'ch_w9', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_w9', 'parent_txn_id' => 'ch_w9', 'amount' => 3_000, 'currency' => 'USD'])->assertOk();

    winDispute('w9', 3_000)->assertOk()->assertJson(['result' => 'dispute won; payment reinstated']);
    winDispute('w9', 3_000)->assertOk()->assertJson(['result' => 'duplicate']);

    $deliveries = WebhookDelivery::query()->orderBy('id')->get();
    $won = Transaction::query()->where('type', 'chargeback_reversal')->sole();
    expect($deliveries->pluck('event_type')->all())->toBe(['payment.reversed', 'payment.reinstated'])
        ->and($deliveries[1]->payload['data']['object'])->toMatchArray(['id' => $won->id, 'type' => 'chargeback_reversal', 'reference' => 'dp_w9', 'invoice_id' => $invoice->id])
        ->and($deliveries[1]->payload['data']['ids'])->toMatchArray(['invoice_id' => $invoice->id, 'client_id' => $client->id, 'transaction_id' => $won->id])
        ->and($invoice->fresh()->balance)->toBe(2_000);
});

test('the won-dispute marker (#232) moves no money: hidden from the client invoice, credit page and PDF, never refundable, no ledger line', function () {
    [, $client] = actingAsClient(Client::factory()->create());
    $invoice = issueInvoice($client, [5_000]);
    sandboxWebhook(['type' => 'payment.succeeded', 'invoice_id' => $invoice->id, 'txn_id' => 'ch_w10', 'amount' => 5_000, 'currency' => 'USD'])->assertOk();

    $result = app(GatewayCallbackHandler::class)->process('sandbox_card', new CallbackEvent(CallbackEvent::DISPUTE_WON, null, 'dp_w10', 5_000, 'USD',
        parentTxnId: 'ch_w10', raw: ['dispute' => 'dp_w10'], fundsReinstated: true));

    $marker = Transaction::query()->where('type', 'chargeback_reversal')->sole();
    expect($result['result'])->toBe(GatewayCallbackHandler::DISPUTE_WON_UNBOOKED)
        ->and($marker)->amount_in->toBe(0)->amount_out->toBe(0)->refundable_remaining->toBe(0)
        ->and($marker->isRefundable())->toBeFalse()
        ->and(CreditLedgerEntry::query()->exists())->toBeFalse()
        ->and($invoice->fresh())->amount_paid->toBe(5_000)->balance->toBe(0);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->has('transactions', 1)->where('transactions.0.type', 'payment'));
    $this->get(route('client.credit.index'))->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    expect(view('billing::pdf.invoice', app(InvoicePdf::class)->viewData($invoice->fresh()))->render())->not->toContain('Dispute won');
});
