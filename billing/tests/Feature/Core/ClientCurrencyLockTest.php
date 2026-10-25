<?php

use App\Contracts\Billing\CreditLedger;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Domain;
use App\Modules\Orders\Models\Order;
use App\Modules\Services\Models\Service;
use App\Support\Clients\ClientCurrencyUsage;
use App\Support\Money;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * #218: once any module holds amounts for a client, staff can't change the client's currency. The edit form disables
 * the field with the reason, and the save refuses it under the client lock, naming what blocks it.
 */
beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    actingAsStaff(['core.clients.*']);
});

function saveClientCurrency(Client $client, string $currency): TestResponse
{
    return test()->from(route('admin.clients.edit', $client))->put(route('admin.clients.update', $client), [
        ...$client->only(['first_name', 'last_name', 'email']),
        'currency' => $currency,
        'status' => $client->status,
    ]);
}

test('a client with records in its currency keeps it, and the error names what blocks the change', function (Closure $record, string $records) {
    $client = Client::factory()->create(['currency' => 'NPR']);
    $record($client);

    saveClientCurrency($client, 'USD')
        ->assertRedirect(route('admin.clients.edit', $client))
        ->assertSessionHasErrors(['currency' => "This client has {$records} in NPR, so the currency can't be changed."]);

    expect($client->fresh()->currency)->toBe('NPR');
})->with([
    'an invoice' => [fn (Client $client) => Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']), 'billing records'],
    'a quote' => [fn (Client $client) => Quote::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']), 'billing records'],
    'a payment' => [fn (Client $client) => Transaction::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']), 'billing records'],
    'account credit' => [fn (Client $client) => app(CreditLedger::class)->credit($client->id, Money::of(50_000, 'NPR'), 'Goodwill'), 'billing records'],
    'a deleted service' => [fn (Client $client) => Service::factory()->create(['client_id' => $client->id, 'currency' => 'NPR'])->delete(), 'services'],
    'an order' => [fn (Client $client) => Order::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']), 'orders'],
    'a deleted domain' => [fn (Client $client) => Domain::factory()->create(['client_id' => $client->id, 'currency' => 'NPR'])->delete(), 'domains'],
]);

test('every source that holds the client is named', function () {
    $client = Client::factory()->create(['currency' => 'NPR']);
    Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);
    Service::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);
    Domain::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);

    expect(app(ClientCurrencyUsage::class)->sourcesFor($client->id))->toBe(['billing', 'domains', 'services']);

    saveClientCurrency($client, 'USD')
        ->assertSessionHasErrors(['currency' => "This client has billing records, domains and services in NPR, so the currency can't be changed."]);
});

test('a client with records still saves its other details when the currency stays the same', function () {
    $client = Client::factory()->create(['currency' => 'NPR', 'status' => 'active']);
    Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);

    $this->put(route('admin.clients.update', $client), [
        ...$client->only(['first_name', 'email']),
        'last_name' => 'Shrestha',
        'currency' => 'NPR',
        'status' => 'inactive',
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.clients.show', $client));

    expect($client->fresh())->last_name->toBe('Shrestha')->status->toBe('inactive')->currency->toBe('NPR');
});

test('a client with no records can still change its currency', function () {
    $client = Client::factory()->create(['currency' => 'NPR']);
    Invoice::factory()->create(['currency' => 'NPR']);

    saveClientCurrency($client, 'USD')->assertSessionHasNoErrors()->assertRedirect(route('admin.clients.show', $client));

    expect($client->fresh()->currency)->toBe('USD');
});

test('the edit form disables the currency with the reason when it is locked, and leaves it open otherwise', function () {
    $locked = Client::factory()->create(['currency' => 'NPR']);
    Order::factory()->create(['client_id' => $locked->id, 'currency' => 'NPR']);
    $open = Client::factory()->create(['currency' => 'NPR']);

    $this->get(route('admin.clients.edit', $locked))->assertInertia(fn (Assert $page) => $page
        ->component('admin/clients/form')
        ->where('currencyLock', "This client has orders in NPR, so the currency can't be changed."));

    $this->get(route('admin.clients.edit', $open))->assertInertia(fn (Assert $page) => $page
        ->component('admin/clients/form')
        ->where('currencyLock', null));
});

test('the client area profile form cannot change the currency', function () {
    $client = Client::factory()->create(['currency' => 'NPR']);
    $client->users()->attach($user = User::factory()->create(['email' => $client->email]), ['is_owner' => true]);
    $this->actingAs($user);

    $this->put(route('client.account.update'), [
        ...$client->only(['first_name', 'last_name', 'email', 'address_1', 'city', 'postcode', 'country']),
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();

    expect($client->fresh()->currency)->toBe('NPR');
});
