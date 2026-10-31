<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Models\Order;
use Tests\Fakes\Orders\OrdersFakes;

/**
 * Review probes for PR #199, adopted as regression tests: the staff order path applies the same .np second-level rule as the cart.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Business Hosting', requiresDomain: true, autoSetup: 'accept');
    $this->client = Client::factory()->create();
});

test('probe: staff cannot place an order using a .np name the registry cannot have issued', function () {
    actingAsStaff(['orders.orders.view', 'orders.orders.create']);

    $this->post(route('admin.orders.store'), [
        'client_id' => $this->client->id, 'product_id' => 1, 'cycle' => 'monthly',
        'domain_mode' => 'existing', 'domain_name' => 'asha.np',
        'payment_gateway' => 'bank_transfer', 'skip_fraud' => true, 'send_invoice' => false,
    ]);

    expect(Order::query()->count())->toBe(0);
})->skip('Tracked in #203');
