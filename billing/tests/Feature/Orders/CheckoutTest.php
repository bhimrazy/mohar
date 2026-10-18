<?php

use App\Events\Core\ClientRegistered;
use App\Events\Orders\OrderPlaced;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Http\Controllers\Public\CheckoutController;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CartManager;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]]);
});

/**
 * @return array<string, mixed>
 */
function checkoutPayload(array $overrides = []): array
{
    return [
        'payment_gateway' => 'bank_transfer',
        'accept_tos' => true,
        'idempotency_key' => 'key-'.uniqid(),
        ...$overrides,
    ];
}

it('registers a new client, places the order and signs them in', function () {
    Currency::factory()->create();
    Event::fake([ClientRegistered::class, OrderPlaced::class]);
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);

    $response = $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), checkoutPayload([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'Ada@Example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'country' => 'GB',
    ]));

    $order = Order::query()->sole();
    $response->assertRedirect(route('client.invoices.show', $order->invoice_id));

    $client = Client::query()->sole();
    $user = User::query()->where('email', 'ada@example.com')->sole();
    expect($order->client_id)->toBe($client->id)
        ->and($client)->email->toBe('ada@example.com')->country->toBe('GB')->currency->toBe('USD')
        ->and($client->users()->sole()->id)->toBe($user->id)
        ->and($order->placed_by_user_id)->toBe($user->id)
        ->and($order->ip_address)->toBe('127.0.0.1')
        ->and(Cart::query()->count())->toBe(0);
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(ClientRegistered::class, fn (ClientRegistered $event) => $event->clientId === $client->id && $event->userId === $user->id);
});

it('does not create the account when the order fails', function () {
    Currency::factory()->create();
    $this->fakes->catalog->addProduct(2, 'Pro Hosting', stock: 0);
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(2)]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), checkoutPayload([
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]))->assertSessionHasErrors(['cart' => 'Pro Hosting is out of stock.']);

    expect(User::query()->count())->toBe(0)->and(Client::query()->count())->toBe(0);
    $this->assertGuest();
});

it('accepts any ISO country, including India and Nepal, and rejects unknown codes', function () {
    Currency::factory()->create();
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);
    $payload = fn (string $country) => checkoutPayload([
        'first_name' => 'Ada', 'last_name' => 'L', 'email' => "ada-{$country}@example.com",
        'password' => 'password', 'password_confirmation' => 'password', 'country' => $country,
    ]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), $payload('XX'))
        ->assertSessionHasErrors(['country' => 'Choose a country from the list.']);
    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), $payload('NP'))
        ->assertSessionHasNoErrors();

    expect(Client::query()->sole()->country)->toBe('NP');
});

it('asks guests whose email is registered to log in', function () {
    Currency::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), checkoutPayload([
        'first_name' => 'A', 'last_name' => 'B', 'email' => 'TAKEN@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]))->assertSessionHasErrors(['email' => 'An account with this email already exists. Log in to continue.']);
});

it('places an order for a signed-in client with notes and the referral cookie', function () {
    Event::fake([OrderPlaced::class]);
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

    $this->withCookie('orders_ref', 'PARTNER7')
        ->post(route('store.checkout.store'), checkoutPayload(['notes' => 'Please set up quickly.']))
        ->assertRedirect();

    $order = Order::query()->sole();
    expect($order->client_id)->toBe($client->id)
        ->and($order->notes)->toBe('Please set up quickly.')
        ->and($order->referral_code)->toBe('PARTNER7');
    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->referralCode === 'PARTNER7');
});

it('sends the customer to the confirmation page when configured', function () {
    config(['orders.checkout_redirect' => 'complete_page']);
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

    $this->post(route('store.checkout.store'), checkoutPayload())
        ->assertRedirect(route('store.checkout.complete', Order::query()->sole()));
});

it('captures ?ref= into the referral cookie', function () {
    Currency::factory()->create();

    $this->get(route('store.index', ['ref' => 'PARTNER7']))->assertCookie('orders_ref', 'PARTNER7');
});

it('requires accepting the terms of service', function () {
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

    $this->post(route('store.checkout.store'), checkoutPayload(['accept_tos' => false]))
        ->assertSessionHasErrors(['accept_tos' => 'Accept the terms of service to place your order.']);

    expect(Order::query()->count())->toBe(0);
});

it('returns the first order on a double submit', function () {
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);
    $payload = checkoutPayload();

    $this->post(route('store.checkout.store'), $payload);
    $order = Order::query()->sole();

    $this->post(route('store.checkout.store'), $payload)->assertRedirect(route('client.invoices.show', $order->invoice_id));
    expect(Order::query()->count())->toBe(1);
});

it('stops checkout when prices changed since the customer reviewed them', function () {
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);
    $this->fakes->catalog->setPrice(1, 'monthly', 1200);

    $this->post(route('store.checkout.store'), checkoutPayload(['expected_net_total' => 1000]))
        ->assertSessionHasErrors(['cart' => 'Prices in your cart have changed. Review the updated total and place your order again.']);

    expect(Order::query()->count())->toBe(0);
});

it('does not treat a tax change from the new address as a price change', function () {
    Currency::factory()->create();
    $this->fakes->billing->taxRate = 20;
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), checkoutPayload([
        'expected_net_total' => 1000,
        'first_name' => 'Ada', 'last_name' => 'L', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]))->assertSessionHasNoErrors();

    expect(Order::query()->sole()->total)->toBe(1200);
});

/**
 * A login attached to $client as a non-owner with the given client-area permissions.
 *
 * @param  list<string>  $areas
 */
function ordersContactFor(Client $client, array $areas): User
{
    $contact = User::factory()->create();
    $client->users()->attach($contact, ['is_owner' => false, 'permissions' => json_encode($areas)]);

    return $contact;
}

it('refuses checkout and credit for a contact without billing access', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $this->actingAs(ordersContactFor($client, ['support']));
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])
        ->post(route('store.checkout.store'), checkoutPayload(['apply_credit' => true]))
        ->assertSessionHasErrors(['cart' => 'Your login cannot place orders for this account. Ask the account owner for billing access.']);

    expect(Order::query()->count())->toBe(0)
        ->and($this->fakes->billing->issued)->toBe([])
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and(Cart::query()->where('client_id', $client->id)->exists())->toBeFalse();
});

it('lets a contact with billing access order for the account', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $this->actingAs(ordersContactFor($client, ['billing']));
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

    $this->post(route('store.checkout.store'), checkoutPayload())->assertSessionHasNoErrors();

    expect(Order::query()->sole()->client_id)->toBe($client->id);
});

it('hides the account orders from a contact without billing access', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $order = Order::factory()->for($client)->create();
    $this->actingAs(ordersContactFor($client, ['support']));

    $this->get(route('store.checkout.complete', $order))->assertNotFound();
});

it('refuses checkout for staff without a client account', function () {
    Currency::factory()->create();
    actingAsStaff();
    $cart = OrdersFakes::cartFor(null, [OrdersFakes::productItem(1)]);

    $this->withSession([CartManager::SESSION_TOKEN => $cart->token])->post(route('store.checkout.store'), checkoutPayload())
        ->assertSessionHasErrors('cart');
});

it('shows the checkout with gateways, totals and a stable idempotency key', function () {
    [, $client] = actingAsClient();
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

    $this->get(route('store.checkout'))->assertInertia(fn (Assert $page) => $page
        ->component('public/checkout/index')
        ->where('cart.total', 1000)
        ->where('gateways.0', ['value' => 'bank_transfer', 'label' => 'Bank transfer'])
        ->where('account.email', $client->email)
        ->where('idempotency_key', session(CheckoutController::SESSION_KEY)));
});

it('sends an empty cart back to the cart page', function () {
    Currency::factory()->create();

    $this->get(route('store.checkout'))->assertRedirect(route('store.cart'));
});

it('shows the completion page only to the buyer', function () {
    [, $client] = actingAsClient();
    $order = Order::factory()->for($client)->create();
    $other = Order::factory()->create();

    $this->get(route('store.checkout.complete', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/checkout/complete')
        ->where('order.number', $order->number));
    $this->get(route('store.checkout.complete', $other))->assertNotFound();
});
