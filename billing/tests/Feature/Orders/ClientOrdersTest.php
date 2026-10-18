<?php

use App\Modules\Orders\Models\Order;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only the current client account orders', function () {
    [, $client] = actingAsClient();
    $mine = Order::factory()->for($client)->create();
    Order::factory()->create();

    $this->get(route('client.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/orders/index')
            ->has('orders.data', 1)
            ->where('orders.data.0.number', $mine->number)
            ->missing('orders.data.0.fraud_decision'));
});

it('shows one of the client orders', function () {
    [, $client] = actingAsClient();
    $order = Order::factory()->for($client)->create();

    $this->get(route('client.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->component('client/orders/show')->where('order.id', $order->id));
});

it('returns 404 for another client order', function () {
    actingAsClient();
    $other = Order::factory()->create();

    $this->get(route('client.orders.show', $other))->assertNotFound();
});

it('forbids contacts without billing access', function () {
    [$user, $client] = actingAsClient();
    $client->users()->updateExistingPivot($user->id, ['is_owner' => false, 'permissions' => json_encode(['support'])]);

    $this->get(route('client.orders.index'))->assertForbidden();
});
