<?php

use App\Models\User;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;

require_once __DIR__.'/helpers.php';

/*
 * The Billing Form Requests authorize before they validate, and the client ones keep the account scope (review of #577).
 */

test('resolving a refund that is no longer waiting is refused without the refunds permission, whatever the body', function () {
    $settled = Transaction::factory()->create(['type' => TransactionType::Refund, 'status' => TransactionStatus::Succeeded]);

    actingAsStaff(['billing.transactions.view']);
    confirmPassword();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), [])->assertForbidden();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), ['outcome' => 'not_refunded'])->assertForbidden();

    actingAsStaff(['billing.transactions.view', 'billing.refunds.manage']);
    confirmPassword();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), [])->assertUnprocessable();
    $this->postJson(route('admin.billing.transactions.refund.resolve', $settled), ['outcome' => 'not_refunded'])->assertNotFound();
});

test('an account user without the billing area is refused before their input is validated', function () {
    app(Settings::class)->set('billing.quotes.require_tos', true);
    $client = Client::factory()->create();
    $quote = Quote::factory()->delivered()->create(['client_id' => $client->id]);
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['support'])]);
    $this->actingAs($user);

    $this->post(route('client.invoices.batches.store'), ['invoice_ids' => 'not-an-array'])->assertForbidden();
    $this->post(route('client.quotes.accept', $quote->id), [])->assertForbidden();
});

test('a client cannot accept another client\'s quote, and the terms check reveals nothing about it', function () {
    app(Settings::class)->set('billing.quotes.require_tos', true);
    [, $client] = actingAsClient();
    $own = Quote::factory()->delivered()->create(['client_id' => $client->id]);
    $foreign = Quote::factory()->delivered()->create();

    // Without the terms both answer the same validation error; with them, the foreign quote is 404.
    $this->post(route('client.quotes.accept', $own->id), [])->assertSessionHasErrors('accept_tos');
    $this->post(route('client.quotes.accept', $foreign->id), [])->assertSessionHasErrors('accept_tos');
    $this->post(route('client.quotes.accept', $foreign->id), ['accept_tos' => '1'])->assertNotFound();
    $this->post(route('client.quotes.accept', $own->id), ['accept_tos' => '1'])->assertRedirect();
    expect($foreign->fresh()->stage->value)->toBe('delivered');
});

test('a client batch cannot include another client\'s invoice', function () {
    sandboxGateway();
    [, $client] = actingAsClient();
    $foreign = issueInvoice(Client::factory()->create(), [1_000]);

    $this->post(route('client.invoices.batches.store'), ['invoice_ids' => [$foreign->id]]);

    expect(PaymentBatch::query()->where('client_id', $client->id)->get()
        ->flatMap(fn ($batch) => $batch->invoices()->pluck('invoices.id'))->all())->not->toContain($foreign->id);
});
