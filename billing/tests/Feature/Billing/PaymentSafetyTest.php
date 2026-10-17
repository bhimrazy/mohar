<?php

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Orders\PaymentGuard;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\MarkInvoicePaid;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Actions\RecordManualTransaction;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

function refusePayments(): void
{
    app()->instance(PaymentGuard::class, new class implements PaymentGuard
    {
        public function refusalReason(int $invoiceId): ?string
        {
            return 'Order under fraud review.';
        }
    });
}

function cardToken(string $number = '4242424242424242'): string
{
    return (new SandboxCardDriver)->tokenize($number, 12, (int) now()->addYear()->format('Y'))->token;
}

// CRITICAL 2 — combined payments

test('a combined payment cannot be charged twice', function () {
    sandboxGateway();
    $client = billingClient();
    $batch = app(PaymentBatches::class)->create($client->id, [issueInvoice($client, [1_000])->id, issueInvoice($client, [2_000])->id]);
    $stale = PaymentBatch::query()->findOrFail($batch->id);

    expect(app(PaymentBatches::class)->pay($batch, 'sandbox_card', cardToken())->succeeded())->toBeTrue()
        ->and(fn () => app(PaymentBatches::class)->pay($stale, 'sandbox_card', cardToken()))->toThrow(BillingException::class, 'already been processed');

    expect(GatewayLog::query()->where('result', 'capture success')->count())->toBe(1);
});

test('a combined payment that is already in progress is refused', function () {
    sandboxGateway();
    $client = billingClient();
    $batch = app(PaymentBatches::class)->create($client->id, [issueInvoice($client, [1_000])->id]);
    $lock = Cache::lock('billing.batch.'.$batch->id, 60);
    $lock->get();

    expect(fn () => app(PaymentBatches::class)->pay($batch, 'sandbox_card', cardToken()))->toThrow(BillingException::class, 'already in progress');

    $lock->release();
});

test('the real provider id is stored once and a duplicate charge is kept as credit', function () {
    sandboxGateway();
    $client = billingClient();
    $a = issueInvoice($client, [1_000]);
    $batch = app(PaymentBatches::class)->create($client->id, [$a->id]);

    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'ch_real_1', 1_000);
    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'ch_real_1', 1_000);
    app(PaymentBatches::class)->settle($batch, 'sandbox_card', 'ch_dup_2', 1_000);

    expect($batch->fresh()->gateway_txn_id)->toBe('ch_real_1')
        ->and(Transaction::query()->where('gateway_txn_id', 'like', '%#%')->exists())->toBeFalse()
        ->and(Transaction::query()->where('invoice_id', $a->id)->count())->toBe(1)
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_dup_2')->value('amount_in'))->toBe(1_000)
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(1_000);
});

// CRITICAL 3 — PaymentGuard on every payment path

test('the payment guard blocks credit, staff payments, write-offs, manual income and combined payments', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(5_000, 'USD'), 'Goodwill');
    billingSettings(['credit.auto_apply_on_generate' => false]);
    $invoice = issueInvoice($client, [1_000]);
    refusePayments();

    expect(fn () => app(ApplyCredit::class)->handle($invoice, 500))->toThrow(BillingException::class, 'Order under fraud review.')
        ->and(fn () => payInvoice($invoice, 1_000))->toThrow(BillingException::class, 'Order under fraud review.')
        ->and(fn () => app(MarkInvoicePaid::class)->handle($invoice))->toThrow(BillingException::class, 'Order under fraud review.')
        ->and(fn () => app(RecordManualTransaction::class)->handle($client, TransactionType::ManualIncome, 1_000, 'bank_transfer', null, null, CarbonImmutable::now(), [$invoice->id]))->toThrow(BillingException::class)
        ->and(fn () => app(PaymentBatches::class)->create($client->id, [$invoice->id]))->toThrow(BillingException::class);

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(1_000);
});

test('staff entry points surface the guard refusal', function () {
    actingAsStaff(['billing.*']);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    $invoice = issueInvoice(billingClient(), [1_000]);
    refusePayments();

    $this->post(route('admin.billing.invoices.payments.store', $invoice), ['amount' => '10.00', 'gateway' => 'bank_transfer'])->assertSessionHasErrors(['billing' => 'Order under fraud review.']);
    $this->post(route('admin.billing.invoices.bulk'), ['action' => 'mark_paid', 'ids' => [$invoice->id]])->assertRedirect();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('money a gateway already took is recorded but held as credit when payment is refused', function () {
    Event::fake([InvoicePaid::class]);
    sandboxGateway();
    $client = billingClient();
    $invoice = issueInvoice($client, [1_000]);
    refusePayments();

    app(ApplyPayment::class)->handle($invoice, new PaymentData(1_000, 'sandbox_card', 'ch_held', fromGateway: true));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->where('gateway_txn_id', 'ch_held')->exists())->toBeTrue()
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(1_000);
    Event::assertNotDispatched(InvoicePaid::class);
});

test('auto-applied credit respects the guard', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(5_000, 'USD'), 'Goodwill');
    refusePayments();

    expect(issueInvoice($client, [1_000])->status)->toBe(InvoiceStatus::Unpaid);
});

// CRITICAL 4 — auto-capture eligibility

test('auto-capture never charges invoices assigned to an offline gateway', function () {
    sandboxGateway();
    Gateway::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    $client = billingClient();
    PayMethod::factory()->create(['client_id' => $client->id]);
    $bank = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'), gateway: 'bank_transfer');
    $card = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'), gateway: 'sandbox_card');

    $this->artisan('automation:run', ['--date' => '2026-05-01', '--only' => 'billing.auto_capture'])->assertSuccessful();

    expect($bank->fresh())->status->toBe(InvoiceStatus::Unpaid)->capture_attempts->toBe(0)
        ->and($card->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('customer-initiated declines do not consume the auto-capture retry budget', function () {
    sandboxGateway();
    $this->travelTo(CarbonImmutable::parse('2026-05-01'));
    [, $client] = actingAsClient();
    $saved = PayMethod::factory()->withCard('4000000000000002')->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-05-02'));

    foreach (range(1, 3) as $try) {
        $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card', 'pay_method_id' => $saved->id])->assertSessionHasErrors('payment');
    }

    expect($invoice->fresh())->capture_attempts->toBe(0)->first_capture_failure_on->toBeNull()
        ->and($saved->fresh())->failure_count->toBe(0)->disabled_at->toBeNull();

    $this->artisan('automation:run', ['--date' => '2026-05-01', '--only' => 'billing.auto_capture'])->assertSuccessful();
    expect($invoice->fresh()->capture_attempts)->toBe(1);
});
