<?php

use App\Contracts\Billing\CreditLedger;
use App\Events\Billing\QuoteAccepted;
use App\Events\Billing\QuoteDeclined;
use App\Models\User;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Quote;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

function sandboxToken(string $card = '4242424242424242'): string
{
    return (new SandboxCardDriver)->tokenize($card, 12, (int) now()->addYear()->format('Y'))->token;
}

test('clients see only their own issued invoices', function () {
    [, $client] = actingAsClient();
    issueInvoice($client, [5_000]);
    issueInvoice($client, status: 'draft');
    issueInvoice(Client::factory()->create());

    $this->get(route('client.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/billing/invoices/index')
            ->has('invoices.data', 1)
            ->where('summary.unpaid_total', 5_000)
            ->where('summary.unpaid_count', 1));
});

test('clients cannot open other clients invoices, PDFs or drafts', function () {
    [, $client] = actingAsClient();
    $foreign = issueInvoice(Client::factory()->create());
    $draft = issueInvoice($client, status: 'draft');

    $this->get(route('client.invoices.show', $foreign->id))->assertNotFound();
    $this->get(route('client.invoices.pdf', $foreign->id))->assertNotFound();
    $this->get(route('client.invoices.show', $draft->id))->assertNotFound();
    $this->post(route('client.invoices.pay', $foreign->id), ['gateway' => 'sandbox_card', 'token' => sandboxToken()])->assertNotFound();
});

test('account users without the billing permission are refused', function () {
    $client = billingClient();
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['support'])]);
    $this->actingAs($user);

    $this->get(route('client.invoices.index'))->assertForbidden();
});

test('the invoice page offers active gateways and bank instructions', function () {
    Gateway::factory()->create();
    sandboxGateway();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);

    $this->get(route('client.invoices.show', $invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/billing/invoices/show')
            ->where('invoice.balance', 5_000)
            ->has('payment.gateways', 2)
            ->where('payment.gateways.0.kind', 'offline')
            ->where('payment.gateways.0.instructions', fn (string $text) => str_contains($text, $invoice->number))
            ->where('payment.gateways.1.vault_url', route('billing.sandbox.tokens')));
});

test('clients pay with a new sandbox card and may save it', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card', 'token' => sandboxToken(), 'save_card' => true])
        ->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(PayMethod::query()->where('client_id', $client->id)->value('last4'))->toBe('4242');
});

test('a declined card shows a friendly error and leaves the invoice unpaid', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card', 'token' => sandboxToken('4000000000000002')])
        ->assertSessionHasErrors(['payment' => 'Payment failed: card declined. Try another card.']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('clients cannot pay with another clients saved card or a forged token', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $foreignCard = PayMethod::factory()->create();

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card', 'pay_method_id' => $foreignCard->id])->assertSessionHasErrors('billing');
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card', 'token' => 'sbx_forged.token'])->assertSessionHasErrors('payment');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('offline gateways cannot be charged online', function () {
    Gateway::factory()->create();
    [, $client] = actingAsClient();

    $this->post(route('client.invoices.pay', issueInvoice($client)->id), ['gateway' => 'bank_transfer', 'token' => 'x'])->assertSessionHasErrors('billing');
});

test('the sandbox vault tokenises valid cards without storing them', function () {
    sandboxGateway();
    actingAsClient();

    $response = $this->postJson(route('billing.sandbox.tokens'), ['card_number' => '4242 4242 4242 4242', 'exp_month' => 12, 'exp_year' => now()->year + 1])
        ->assertOk()
        ->assertJsonPath('last4', '4242')
        ->assertJsonPath('brand', 'visa');

    expect($response->json('token'))->toStartWith('sbx_')->not->toContain('4242424242424242');

    $this->postJson(route('billing.sandbox.tokens'), ['card_number' => '1234', 'exp_month' => 13, 'exp_year' => 2000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['card_number', 'exp_month', 'exp_year']);
});

test('card data is never flashed into the session', function () {
    sandboxGateway();
    actingAsClient();

    $this->post(route('billing.sandbox.tokens'), ['card_number' => '4242424242424241', 'exp_month' => 12, 'exp_year' => now()->year + 1])->assertForbidden();
    expect(session()->getOldInput())->not->toHaveKey('card_number');

    $this->from('/x')->post(route('client.pay-methods.store'), ['gateway' => '', 'token' => '', 'card_number' => '4242424242424242', 'cvc' => '123'])
        ->assertSessionHasErrors('gateway');
    expect(session()->getOldInput())->not->toHaveKeys(['card_number', 'cvc']);
});

test('clients manage their stored pay methods', function () {
    sandboxGateway();
    [, $client] = actingAsClient();

    $this->post(route('client.pay-methods.store'), ['gateway' => 'sandbox_card', 'token' => sandboxToken()])->assertRedirect();
    $this->post(route('client.pay-methods.store'), ['gateway' => 'sandbox_card', 'token' => sandboxToken('5555555555554444')])->assertRedirect();
    [$first, $second] = PayMethod::query()->where('client_id', $client->id)->orderBy('id')->get()->all();

    expect($first->is_default)->toBeTrue()->and($second->is_default)->toBeFalse()->and($second->brand)->toBe('mastercard');

    $this->post(route('client.pay-methods.default', $second->id))->assertRedirect();
    expect($second->fresh()->is_default)->toBeTrue()->and($first->fresh()->is_default)->toBeFalse();

    $this->get(route('client.pay-methods.index'))->assertInertia(fn (Assert $page) => $page
        ->component('client/billing/pay-methods/index')
        ->has('pay_methods', 2)
        ->missing('pay_methods.0.token'));

    $foreign = PayMethod::factory()->create();
    $this->delete(route('client.pay-methods.destroy', $foreign->id))->assertNotFound();
    $this->delete(route('client.pay-methods.destroy', $second->id))->assertRedirect();

    expect($first->fresh()->is_default)->toBeTrue();
});

test('pay method removal can be disabled', function () {
    billingSettings(['allow_pay_method_removal' => false]);
    [, $client] = actingAsClient();
    $method = PayMethod::factory()->create(['client_id' => $client->id]);

    $this->delete(route('client.pay-methods.destroy', $method->id))->assertSessionHasErrors('billing');
    expect($method->fresh())->not->toBeNull();
});

test('clients add funds within the limits', function () {
    [, $client] = actingAsClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);

    $this->post(route('client.credit.add-funds'), ['amount' => '5.00'])->assertSessionHasErrors('billing');
    $this->post(route('client.credit.add-funds'), ['amount' => '25.00'])->assertRedirect();

    $invoice = Invoice::query()->where('client_id', $client->id)->firstOrFail();
    expect($invoice)->kind->value->toBe('add_funds')->total->toBe(2_500);

    $this->get(route('client.credit.index'))->assertInertia(fn (Assert $page) => $page
        ->component('client/billing/credit/index')
        ->where('balance', 0)
        ->where('add_funds.unavailable_reason', null));
});

test('add funds is unavailable without an accepted order', function () {
    actingAsClient();

    $this->get(route('client.credit.index'))->assertInertia(fn (Assert $page) => $page->where('add_funds.unavailable_reason', fn ($reason) => $reason !== null));
    $this->post(route('client.credit.add-funds'), ['amount' => '25.00'])->assertSessionHasErrors('billing');
});

test('clients apply credit to an invoice', function () {
    billingSettings(['credit.auto_apply_on_generate' => false]);
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    app(CreditLedger::class)->credit($client->id, Money::of(2_000, 'USD'), 'Goodwill');

    $this->post(route('client.invoices.credit', $invoice->id), ['amount' => '20.00'])->assertRedirect();

    expect($invoice->fresh()->balance)->toBe(3_000);
});

test('clients download their invoice PDF', function () {
    Storage::fake('objects');
    [, $client] = actingAsClient();

    $this->get(route('client.invoices.pdf', issueInvoice($client)->id))->assertOk()->assertDownload();
});

test('accepting a quote with auto-invoice creates one invoice; a second conversion is refused', function () {
    Event::fake([QuoteAccepted::class]);
    [, $client] = actingAsClient();
    $quote = Quote::factory()->delivered()->create(['client_id' => $client->id]);

    $this->post(route('client.quotes.accept', $quote->id))->assertRedirect();

    $quote->refresh();
    expect($quote->stage->value)->toBe('accepted')
        ->and(Invoice::query()->where('client_id', $client->id)->where('status', InvoiceStatus::Unpaid)->count())->toBe(1)
        ->and(fn () => app(Quotes::class)->convert($quote))->toThrow(BillingException::class);
    Event::assertDispatched(QuoteAccepted::class, fn ($event) => $event->invoiceId === $quote->invoice_id);
});

test('clients decline quotes but cannot touch drafts or other clients quotes', function () {
    Event::fake([QuoteDeclined::class]);
    [, $client] = actingAsClient();
    $quote = Quote::factory()->delivered()->create(['client_id' => $client->id]);
    $draft = Quote::factory()->create(['client_id' => $client->id]);
    $foreign = Quote::factory()->delivered()->create();

    $this->post(route('client.quotes.decline', $quote->id))->assertRedirect();
    $this->get(route('client.quotes.show', $draft->id))->assertNotFound();
    $this->post(route('client.quotes.accept', $foreign->id))->assertNotFound();

    expect($quote->fresh()->stage->value)->toBe('declined');
    Event::assertDispatched(QuoteDeclined::class);

    $this->get(route('client.quotes.index'))->assertInertia(fn (Assert $page) => $page->component('client/billing/quotes/index')->has('quotes.data', 1));
});

test('clients pay all unpaid invoices in one batch', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $a = issueInvoice($client, [1_000]);
    $b = issueInvoice($client, [2_000]);

    $this->post(route('client.invoices.batches.store'))->assertRedirect();
    $batch = PaymentBatch::query()->firstOrFail();

    $this->get(route('client.invoices.batches.show', $batch->id))->assertInertia(fn (Assert $page) => $page
        ->component('client/billing/invoices/batch')
        ->where('batch.total', 3_000)
        ->has('invoices', 2));

    $this->post(route('client.invoices.batches.pay', $batch->id), ['gateway' => 'sandbox_card', 'token' => sandboxToken()])
        ->assertRedirect(route('client.invoices.index'));

    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($b->fresh()->status)->toBe(InvoiceStatus::Paid);
});
