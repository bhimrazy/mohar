<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

beforeEach(function () {
    Currency::factory()->create();
});

it('passes an ordinary order', function () {
    $order = Order::factory()->create();

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Pass);
    $this->assertDatabaseHas('fraud_checks', ['order_id' => $order->id, 'decision' => 'pass', 'provider' => 'rules']);
});

it('fails a client with too many pending orders', function () {
    config(['orders.fraud.max_pending_orders' => 2]);
    $client = Client::factory()->create();
    Order::factory()->for($client)->count(2)->create();
    $order = Order::factory()->for($client)->create();

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Fail)
        ->and($order->fresh()->fraud_payload['reasons'][0])->toBe('Client has 3 pending orders (limit 2).');
});

it('fails too many orders from one IP address in 24 hours', function () {
    config(['orders.fraud.max_orders_per_ip' => 1]);
    Order::factory()->create(['ip_address' => '198.51.100.7']);
    $order = Order::factory()->create(['ip_address' => '198.51.100.7']);

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Fail);
});

it('fails banned email domains', function () {
    config(['orders.fraud.banned_email_domains' => 'mailinator.com, spam.test']);
    $order = Order::factory()->for(Client::factory()->state(['email' => 'x@Spam.test']))->create();

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Fail);
});

it('skips clients that already have an active service', function () {
    $client = Client::factory()->create();
    OrderItem::factory()->for(Order::factory()->for($client)->status(OrderStatus::Active))->status(OrderItemStatus::Active)->create();
    $order = Order::factory()->for($client)->create();

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Skipped);
});

it('passes everything with the none driver', function () {
    config(['orders.fraud.driver' => 'none', 'orders.fraud.banned_email_domains' => 'spam.test']);
    $order = Order::factory()->for(Client::factory()->state(['email' => 'x@spam.test']))->create();

    expect(app(FraudScreening::class)->screen($order))->toBe(FraudDecision::Pass);
});

it('treats provider errors as fail when not failing open', function () {
    config(['orders.fraud.fail_open' => false]);

    expect(app(FraudScreening::class)->effectiveDecision(FraudDecision::Error))->toBe(FraudDecision::Fail);
});
