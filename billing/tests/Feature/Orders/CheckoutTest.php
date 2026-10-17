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
        'country' => 'gb',
    ]));

    $order = Order::query()->sole();
    $response->assertRedirect(route('store.checkout.complete', $order));

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

    $this->post(route('store.checkout.store'), $payload)->assertRedirect(route('store.checkout.complete', $order));
    expect(Order::query()->count())->toBe(1);
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
