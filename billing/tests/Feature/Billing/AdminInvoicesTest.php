<?php

use App\Events\Billing\InvoiceCreated;
use App\Events\Billing\InvoicePaymentReminder;
use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/** Sensitive staff actions require a recent password confirmation (password.recent, App\Http\Middleware\RequireRecentPasswordConfirmation). */
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

test('guests, customers and staff without permission cannot see invoices', function () {
    $this->get(route('admin.billing.invoices.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.billing.invoices.index'))->assertForbidden();

    actingAsStaff(['support.*']);
    $this->get(route('admin.billing.invoices.index'))->assertForbidden();
});

test('the invoice list filters by derived overdue status and client', function () {
    actingAsStaff(['billing.invoices.view']);
    $client = billingClient();
    Invoice::factory()->overdue()->create(['client_id' => $client->id, 'number' => 'INV-OVERDUE']);
    Invoice::factory()->create(['client_id' => $client->id]);
    Invoice::factory()->overdue()->create();

    $this->get(route('admin.billing.invoices.index', ['status' => 'overdue', 'client' => $client->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing/invoices/index')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.number', 'INV-OVERDUE')
            ->where('invoices.data.0.display_status', 'overdue')
            ->where('client.id', $client->id)
            ->where('can.manage', false));
});

test('invoice list filters validate their input', function () {
    actingAsStaff(['billing.invoices.view']);

    $this->get(route('admin.billing.invoices.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
});

test('staff create, edit and publish a draft invoice', function () {
    Event::fake([InvoiceCreated::class]);
    billingStaff();
    $client = billingClient();

    $this->post(route('admin.billing.invoices.store'), [
        'client_id' => $client->id,
        'items' => [['description' => 'Consulting', 'quantity' => 2, 'unit_amount' => '45.50', 'taxable' => true]],
    ])->assertRedirect();

    $invoice = Invoice::query()->where('client_id', $client->id)->firstOrFail();
    expect($invoice)->status->toBe(InvoiceStatus::Draft)->total->toBe(9_100)->number->toBeNull();

    $this->put(route('admin.billing.invoices.update', $invoice), [
        'client_id' => $client->id,
        'items' => [['description' => 'Consulting', 'quantity' => 3, 'unit_amount' => '45.50']],
    ])->assertRedirect(route('admin.billing.invoices.show', $invoice));
    expect($invoice->fresh()->total)->toBe(13_650);

    $this->post(route('admin.billing.invoices.publish', $invoice))->assertRedirect();

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->number->not->toBeNull();
    Event::assertDispatched(InvoiceCreated::class);
});

test('editing a line of a published invoice is rejected', function () {
    billingStaff();
    $invoice = issueInvoice(billingClient(), [5_000]);

    $this->put(route('admin.billing.invoices.update', $invoice), [
        'client_id' => $invoice->client_id,
        'items' => [['description' => 'Changed', 'quantity' => 1, 'unit_amount' => '1.00']],
    ])->assertSessionHasErrors('billing');

    expect($invoice->fresh()->total)->toBe(5_000)
        ->and($invoice->items()->first()->description)->toBe('Hosting');
    $this->get(route('admin.billing.invoices.edit', $invoice))->assertRedirect(route('admin.billing.invoices.show', $invoice));
});

test('draft validation explains how to fix lines', function () {
    billingStaff();

    $this->post(route('admin.billing.invoices.store'), ['client_id' => 999_999, 'items' => [['description' => '', 'quantity' => 0, 'unit_amount' => 'abc']]])
        ->assertSessionHasErrors(['client_id', 'items.0.description', 'items.0.quantity', 'items.0.unit_amount']);
});

test('only drafts can be deleted', function () {
    billingStaff();
    $draft = issueInvoice(billingClient(), status: 'draft');
    $issued = issueInvoice(billingClient());

    $this->delete(route('admin.billing.invoices.destroy', $draft))->assertRedirect(route('admin.billing.invoices.index'));
    $this->delete(route('admin.billing.invoices.destroy', $issued))->assertSessionHasErrors('invoice');

    expect(Invoice::query()->find($draft->id))->toBeNull()->and($issued->fresh())->not->toBeNull();
});

test('the invoice page exposes lines, transactions, credit and permissions', function () {
    actingAsStaff(['billing.invoices.view', 'billing.payments.manage']);
    $invoice = issueInvoice(billingClient(), [5_000]);
    payInvoice($invoice, 1_000, 'TX-1');

    $this->get(route('admin.billing.invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing/invoices/show')
            ->where('invoice.balance', 4_000)
            ->has('invoice.items', 1)
            ->has('transactions', 1)
            ->where('transactions.0.gateway_txn_id', 'TX-1')
            ->where('credit_available', 0)
            ->where('can', ['manage' => false, 'payments' => true, 'refunds' => false]));
});

test('staff record payments with decimal amounts', function () {
    actingAsStaff(['billing.payments.manage']);
    $invoice = issueInvoice(billingClient(), [10_000]);

    $this->post(route('admin.billing.invoices.payments.store', $invoice), ['amount' => '40.00', 'gateway' => 'bank_transfer', 'gateway_txn_id' => 'WIRE-1'])
        ->assertRedirect();

    expect($invoice->fresh())->balance->toBe(6_000)->status->toBe(InvoiceStatus::Unpaid);

    $this->post(route('admin.billing.invoices.payments.store', $invoice), ['amount' => '-5', 'gateway' => 'nope'])
        ->assertSessionHasErrors(['amount', 'gateway']);
});

test('recording payments needs the payments permission', function () {
    actingAsStaff(['billing.invoices.*']);
    $invoice = issueInvoice(billingClient());

    $this->post(route('admin.billing.invoices.payments.store', $invoice), ['amount' => '10.00', 'gateway' => 'manual'])->assertForbidden();
    $this->post(route('admin.billing.invoices.mark-paid', $invoice))->assertForbidden();
});

test('staff refund a payment through the transactions endpoint', function () {
    actingAsStaff(['billing.refunds.manage']);
    $invoice = issueInvoice(billingClient(), [5_000]);
    $payment = payInvoice($invoice, 5_000);

    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '20.00', 'mode' => 'record_only'])->assertRedirect();
    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '40.00', 'mode' => 'record_only'])->assertSessionHasErrors('billing');

    expect($payment->fresh()->refundable_remaining)->toBe(3_000)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('staff cancel, reopen, remind and write off invoices', function () {
    Event::fake([InvoicePaymentReminder::class]);
    billingStaff();
    $invoice = issueInvoice(billingClient(), [5_000]);

    $this->post(route('admin.billing.invoices.remind', $invoice))->assertRedirect();
    Event::assertDispatched(InvoicePaymentReminder::class, fn ($event) => $event->stage === 'due');

    $this->post(route('admin.billing.invoices.cancel', $invoice))->assertRedirect();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);

    $this->post(route('admin.billing.invoices.reopen', $invoice))->assertRedirect();
    $this->post(route('admin.billing.invoices.mark-paid', $invoice))->assertRedirect();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('bulk actions update only payable invoices', function () {
    billingStaff();
    $client = billingClient();
    $first = issueInvoice($client, [1_000]);
    $second = issueInvoice($client, [2_000]);
    $paid = issueInvoice($client, [3_000]);
    payInvoice($paid, 3_000);

    $this->post(route('admin.billing.invoices.bulk'), ['action' => 'cancel', 'ids' => [$first->id, $second->id, $paid->id]])->assertRedirect();

    expect($first->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($paid->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('selected lines can be cancelled with a credit note', function () {
    billingStaff();
    $invoice = issueInvoice(billingClient(), [1_000, 2_000]);
    $line = $invoice->items()->where('unit_amount', 2_000)->firstOrFail();

    $this->post(route('admin.billing.invoices.cancel-lines', $invoice), ['items' => [$line->id]])->assertRedirect();

    expect($invoice->fresh()->balance)->toBe(1_000)->and($line->fresh()->cancelled_at)->not->toBeNull();
});

test('the PDF is cached per version and regenerated after a payment', function () {
    Storage::fake('objects');
    actingAsStaff(['billing.invoices.view']);
    $invoice = issueInvoice(billingClient(), [5_000]);

    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();
    expect(Storage::disk('objects')->allFiles('billing/invoices/'.$invoice->id))->toHaveCount(1);

    payInvoice($invoice, 1_000);
    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();

    expect(Storage::disk('objects')->allFiles('billing/invoices/'.$invoice->id))->toHaveCount(2);
});

test('staff record manual income applied to invoices', function () {
    actingAsStaff(['billing.transactions.view', 'billing.transactions.manage']);
    $client = billingClient();
    $invoice = issueInvoice($client, [2_000]);

    $this->post(route('admin.billing.transactions.store'), [
        'client_id' => $client->id, 'type' => 'manual_income', 'amount' => '20.00', 'gateway' => 'bank_transfer',
        'occurred_at' => now()->toDateString(), 'invoice_ids' => [$invoice->id],
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->get(route('admin.billing.transactions.index', ['client' => $client->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/billing/transactions/index')->has('transactions.data', 1)->where('client.id', $client->id));
    expect(Transaction::query()->count())->toBe(1);
});

test('billing forms search clients without a size cap', function () {
    actingAsStaff(['billing.invoices.manage']);
    // A made-up surname and fixed decoys (name, company and email are all searched), so no random name can match.
    billingClient(['first_name' => 'Zara', 'last_name' => 'Quorvath', 'company_name' => null, 'email' => 'zara@example.test']);
    foreach (['Alex Smith', 'Mina Rai', 'Tom Lee'] as $index => $name) {
        [$first, $last] = explode(' ', $name);
        Client::factory()->create(['first_name' => $first, 'last_name' => $last, 'company_name' => "Decoy {$index} Ltd", 'email' => "decoy{$index}@example.test"]);
    }

    $this->getJson(route('admin.billing.clients.search', ['q' => 'Quorvath']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.display_name', 'Zara Quorvath');

    actingAsStaff(['billing.invoices.view']);
    $this->getJson(route('admin.billing.clients.search', ['q' => 'Quorvath']))->assertForbidden();
});

test('the transaction list date range covers both whole days and nothing after it', function () {
    actingAsStaff(['billing.transactions.view']);
    $client = billingClient();
    $first = Transaction::factory()->create(['client_id' => $client->id, 'occurred_at' => '2026-10-01 00:00:00']);
    $last = Transaction::factory()->create(['client_id' => $client->id, 'occurred_at' => '2026-10-31 23:59:59']);
    Transaction::factory()->create(['client_id' => $client->id, 'occurred_at' => '2026-11-01 00:00:00']);
    Transaction::factory()->create(['client_id' => $client->id, 'occurred_at' => '2026-09-30 23:59:59']);

    $this->get(route('admin.billing.transactions.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 2)
            ->where('transactions.data.0.id', $last->id)->where('transactions.data.1.id', $first->id));
});
