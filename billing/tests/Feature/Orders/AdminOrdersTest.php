<?php

use App\Data\Services\ProvisionResult;
use App\Events\Orders\OrderAccepted;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', autoSetup: 'accept')->addProduct(2, 'Email', autoSetup: 'accept');
    $this->client = Client::factory()->create();
});

/**
 * @param  list<int>  $productIds
 */
function placeAdminTestOrder(Client $client, array $productIds = [1], ?string $promotionCode = null): Order
{
    return app(PlaceOrder::class)->handle(OrdersFakes::checkout($client, array_map(fn (int $id) => OrdersFakes::productItem($id), $productIds), $promotionCode, skipFraud: true))->order;
}

describe('index', function () {
    it('forbids staff without the orders permission', function () {
        actingAsStaff(['billing.*']);

        $this->get(route('admin.orders.index'))->assertForbidden();
    });

    it('redirects guests to the login page', function () {
        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));
    });

    it('filters orders by status, client and date', function () {
        actingAsStaff(['orders.orders.view']);
        $pending = Order::factory()->for($this->client)->create(['created_at' => '2026-10-10 12:00']);
        Order::factory()->for($this->client)->status(OrderStatus::Active)->create(['created_at' => '2026-10-10 12:00']);
        Order::factory()->create(['created_at' => '2026-10-10 12:00']);
        Order::factory()->for($this->client)->create(['created_at' => '2026-09-01 12:00']);

        $this->get(route('admin.orders.index', ['status' => 'pending', 'client' => $this->client->id, 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/orders/index')
                ->has('orders.data', 1)
                ->where('orders.data.0.number', $pending->number)
                ->where('client.id', $this->client->id));
    });

    it('includes both whole days of the date range and nothing after it', function () {
        actingAsStaff(['orders.orders.view']);
        $first = Order::factory()->create(['created_at' => '2026-10-01 00:00:00']);
        $last = Order::factory()->create(['created_at' => '2026-10-31 23:59:59']);
        Order::factory()->create(['created_at' => '2026-11-01 00:00:00']);
        Order::factory()->create(['created_at' => '2026-09-30 23:59:59']);

        $this->get(route('admin.orders.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 2)
                ->where('orders.data.0.id', $last->id)->where('orders.data.1.id', $first->id));
    });

    it('rejects a date filter that is not a date', function () {
        actingAsStaff(['orders.orders.view']);

        $this->get(route('admin.orders.index', ['from' => 'garbage']))->assertSessionHasErrors('from');
    });

    it('finds an order by number', function () {
        actingAsStaff(['orders.orders.view']);
        $order = Order::factory()->create(['number' => '4815162342']);
        Order::factory()->create();

        $this->get(route('admin.orders.index', ['search' => '1516']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.id', $order->id));
    });
});

it('shows an order with items, invoice status and fraud result', function () {
    actingAsStaff(['orders.orders.view']);
    $order = placeAdminTestOrder($this->client);

    $this->get(route('admin.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/show')
            ->where('order.number', $order->number)
            ->where('order.fraud_decision', 'skipped')
            ->where('order.items.0.status', 'pending')
            ->where('invoice.status', 'unpaid')
            ->where('default_run_create', [$order->items()->sole()->id])
            ->where('can.accept', false));
});

describe('accept', function () {
    it('activates the order and provisions every selected item even when one fails', function () {
        Event::fake([OrderAccepted::class]);
        actingAsStaff(['orders.orders.view', 'orders.orders.accept']);
        $order = placeAdminTestOrder($this->client, [1, 2]);
        [$first, $second] = $order->items()->orderBy('id')->get()->all();
        $this->fakes->services->outcomes[$first->item_id] = new ProvisionResult(false, 'Server full');

        $this->post(route('admin.orders.accept', $order), ['run_create' => [$first->id, $second->id]])->assertRedirect();

        expect($order->fresh()->status)->toBe(OrderStatus::Active)
            ->and($this->fakes->services->provisioned)->toBe([$first->item_id, $second->item_id])
            ->and($first->fresh()->status)->toBe(OrderItemStatus::Pending)
            ->and($second->fresh()->status)->toBe(OrderItemStatus::Active);
        Event::assertDispatched(OrderAccepted::class, fn (OrderAccepted $event) => $event->orderId === $order->id);
    });

    it('keeps the acceptance when provisioning throws', function () {
        actingAsStaff(['orders.orders.accept']);
        $order = placeAdminTestOrder($this->client);
        $item = $order->items()->sole();
        $this->fakes->services->outcomes[$item->item_id] = new RuntimeException('Driver down');

        $this->post(route('admin.orders.accept', $order))->assertSessionHasNoErrors();

        expect($order->fresh()->status)->toBe(OrderStatus::Active);
    });

    it('provisions on-accept items by default and skips unselected ones', function () {
        actingAsStaff(['orders.orders.accept']);
        $this->fakes->catalog->addProduct(3, 'Manual', autoSetup: 'manual');
        $order = placeAdminTestOrder($this->client, [1, 3]);

        $this->post(route('admin.orders.accept', $order));

        expect($this->fakes->services->provisioned)->toBe([$order->items()->where('product_id', 1)->sole()->item_id]);
    });

    it('forbids staff who may only view orders', function () {
        actingAsStaff(['orders.orders.view']);
        $order = Order::factory()->create();

        $this->post(route('admin.orders.accept', $order))->assertForbidden();
        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });

    it('provisions once when two accepts race on the same order', function () {
        $order = placeAdminTestOrder($this->client);
        $stale = Order::query()->findOrFail($order->id);
        $accept = app(AcceptOrder::class);

        $accept->handle($order);

        expect(fn () => $accept->handle($stale))->toThrow(DomainException::class, 'Only pending orders can be accepted.')
            ->and($this->fakes->services->provisioned)->toHaveCount(1);
    });

    it('refuses to accept a cancelled order', function () {
        actingAsStaff(['orders.orders.accept']);
        $order = Order::factory()->status(OrderStatus::Cancelled)->create();

        $this->post(route('admin.orders.accept', $order))->assertSessionHasErrors(['order' => 'Only pending orders can be accepted.']);
    });
});

describe('cancel', function () {
    it('cancels pending items and the unpaid invoice and leaves active ones to Services', function () {
        Event::fake([OrderCancelled::class]);
        actingAsStaff(['orders.orders.cancel']);
        $order = placeAdminTestOrder($this->client, [1, 2]);
        [$active, $pending] = $order->items()->orderBy('id')->get()->all();
        $active->update(['status' => OrderItemStatus::Active]);

        $this->post(route('admin.orders.cancel', $order))->assertRedirect();

        expect($order->fresh())->status->toBe(OrderStatus::Cancelled)->cancelled_at->not->toBeNull()
            ->and($this->fakes->services->cancelled)->toBe([$pending->item_id => 'cancelled'])
            ->and($this->fakes->billing->status($order->invoice_id))->toBe('cancelled')
            ->and($active->fresh()->status)->toBe(OrderItemStatus::Cancelled);
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event) => $event->orderId === $order->id && $event->clientId === $this->client->id);
    });

    it('releases the promotion redemption of an unpaid order', function () {
        actingAsStaff(['orders.orders.cancel']);
        $promotion = Promotion::factory()->percent('10')->create();
        $order = placeAdminTestOrder($this->client, [1], $promotion->code);
        expect($promotion->fresh()->uses)->toBe(1);

        $this->post(route('admin.orders.cancel', $order));

        expect($promotion->fresh()->uses)->toBe(0)
            ->and($order->redemptions()->whereNull('released_at')->count())->toBe(0);
    });

    it('keeps the promotion use when the invoice was paid', function () {
        actingAsStaff(['orders.orders.cancel']);
        $promotion = Promotion::factory()->percent('10')->create();
        $order = placeAdminTestOrder($this->client, [1], $promotion->code);
        $this->fakes->invoices->withPayments[] = $order->invoice_id;

        $this->post(route('admin.orders.cancel', $order));

        expect($promotion->fresh()->uses)->toBe(1);
    });
});

describe('fraud', function () {
    it('marks the order and items as fraud and cancels the invoice', function () {
        Event::fake([OrderMarkedFraud::class]);
        actingAsStaff(['orders.orders.mark_fraud']);
        $order = placeAdminTestOrder($this->client);
        $item = $order->items()->sole();

        $this->post(route('admin.orders.fraud', $order))->assertRedirect();

        expect($order->fresh()->status)->toBe(OrderStatus::Fraud)
            ->and($item->fresh()->status)->toBe(OrderItemStatus::Fraud)
            ->and($this->fakes->services->cancelled)->toBe([])
            ->and($this->fakes->billing->status($order->invoice_id))->toBe('cancelled');
        Event::assertDispatched(OrderMarkedFraud::class);
    });

    it('never provisions a live service again after fraud, set pending and accept', function () {
        actingAsStaff(['orders.orders.accept', 'orders.orders.mark_fraud']);
        $order = placeAdminTestOrder($this->client);
        $item = $order->items()->sole();

        $this->post(route('admin.orders.accept', $order), ['run_create' => [$item->id]]);
        $this->post(route('admin.orders.fraud', $order));
        expect($item->fresh()->status)->toBe(OrderItemStatus::Active);

        $this->post(route('admin.orders.pending', $order));
        $this->post(route('admin.orders.accept', $order), ['run_create' => [$item->id]]);

        expect($order->fresh()->status)->toBe(OrderStatus::Active)
            ->and($this->fakes->services->provisioned)->toBe([$item->item_id]);
    });

    it('re-issues the cancelled invoice when a fraud order is set back to pending', function () {
        actingAsStaff(['orders.orders.mark_fraud']);
        $promotion = Promotion::factory()->percent('10')->create();
        $order = placeAdminTestOrder($this->client, [1], $promotion->code);
        $firstInvoice = $order->invoice_id;
        $firstLines = $this->fakes->billing->lastDraft()->lines;
        $this->post(route('admin.orders.fraud', $order));

        $this->post(route('admin.orders.pending', $order))->assertSessionHasNoErrors();

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Pending)
            ->and($order->invoice_id)->not->toBe($firstInvoice)
            ->and($this->fakes->billing->status($order->invoice_id))->toBe('unpaid')
            ->and(array_map(fn ($line) => [$line->billableType, $line->billableId, $line->unitAmount], $this->fakes->billing->lastDraft()->lines))
            ->toBe(array_map(fn ($line) => [$line->billableType, $line->billableId, $line->unitAmount], $firstLines))
            ->and($promotion->fresh()->uses)->toBe(1);
    });

    it('cancels the services of a fraud order when it is cancelled', function () {
        actingAsStaff(['orders.orders.mark_fraud', 'orders.orders.cancel']);
        $order = placeAdminTestOrder($this->client);
        $item = $order->items()->sole();
        $this->post(route('admin.orders.fraud', $order));

        $this->post(route('admin.orders.cancel', $order))->assertSessionHasNoErrors();

        expect($this->fakes->services->cancelled)->toBe([$item->item_id => 'cancelled'])
            ->and($item->fresh()->status)->toBe(OrderItemStatus::Cancelled);
    });

    it('sets a fraud order back to pending', function () {
        actingAsStaff(['orders.orders.mark_fraud']);
        $order = Order::factory()->fraud()->create();

        $this->post(route('admin.orders.pending', $order))->assertRedirect();

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });

    it('re-runs the fraud check and records it', function () {
        actingAsStaff(['orders.orders.mark_fraud']);
        $order = Order::factory()->for($this->client)->create();

        $this->post(route('admin.orders.fraud-check', $order))->assertRedirect();

        $this->assertDatabaseHas('fraud_checks', ['order_id' => $order->id, 'provider' => 'rules', 'decision' => 'pass']);
    });
});

describe('delete', function () {
    it('refuses to delete an order whose unpaid invoice already has a partial payment', function () {
        actingAsStaff(['orders.orders.delete']);
        $order = placeAdminTestOrder($this->client);
        $this->fakes->invoices->withPayments[] = $order->invoice_id;

        $this->delete(route('admin.orders.destroy', $order))->assertSessionHasErrors('order');

        expect($this->fakes->billing->status($order->invoice_id))->toBe('unpaid')
            ->and(Order::query()->whereKey($order->id)->exists())->toBeTrue();
    });

    it('refuses to delete an order whose invoice has payments', function () {
        actingAsStaff(['orders.orders.delete']);
        $order = placeAdminTestOrder($this->client);
        $this->fakes->invoices->withPayments[] = $order->invoice_id;

        $this->delete(route('admin.orders.destroy', $order))
            ->assertSessionHasErrors(['order' => 'This order has payments on its invoice and cannot be deleted. Cancel it instead.']);

        expect(Order::query()->whereKey($order->id)->exists())->toBeTrue();
    });

    it('deletes an unpaid order, cancelling its items and invoice', function () {
        actingAsStaff(['orders.orders.delete']);
        $order = placeAdminTestOrder($this->client);
        $item = $order->items()->sole();

        $this->delete(route('admin.orders.destroy', $order))->assertRedirect(route('admin.orders.index'));

        expect(Order::query()->count())->toBe(0)
            ->and(OrderItem::query()->count())->toBe(0)
            ->and($this->fakes->services->cancelled)->toBe([$item->item_id => 'cancelled'])
            ->and($this->fakes->billing->status($order->invoice_id))->toBe('cancelled');
    });
});

it('cancels several orders at once and reports skipped ones', function () {
    actingAsStaff(['orders.orders.cancel']);
    $orders = [placeAdminTestOrder($this->client), placeAdminTestOrder($this->client)];
    $alreadyCancelled = Order::factory()->status(OrderStatus::Cancelled)->create();

    $this->post(route('admin.orders.bulk'), ['action' => 'cancel', 'ids' => [$orders[0]->id, $orders[1]->id, $alreadyCancelled->id]])
        ->assertRedirect();

    expect(Order::query()->where('status', OrderStatus::Cancelled)->count())->toBe(3);
});

it('requires the matching permission for bulk actions', function () {
    actingAsStaff(['orders.orders.cancel']);
    $order = Order::factory()->create();

    $this->post(route('admin.orders.bulk'), ['action' => 'delete', 'ids' => [$order->id]])->assertForbidden();
});

describe('staff orders', function () {
    it('places an order for a client with a price override and no fraud check', function () {
        actingAsStaff(['orders.orders.view', 'orders.orders.create']);

        $this->post(route('admin.orders.store'), [
            'client_id' => $this->client->id,
            'product_id' => 1,
            'cycle' => 'monthly',
            'price_override' => '7.50',
            'payment_gateway' => 'bank_transfer',
            'skip_fraud' => true,
            'send_invoice' => false,
        ])->assertRedirect();

        $order = Order::query()->sole();
        expect($order->total)->toBe(750)
            ->and($order->placed_by_staff_id)->not->toBeNull()
            ->and($order->fraud_decision?->value)->toBe('skipped')
            ->and($this->fakes->billing->lastDraft()->notify)->toBeFalse()
            ->and($order->items()->sole()->item_type)->toBe(OrderItemType::Service);
    });

    it('validates the price override and billing cycle strictly', function () {
        actingAsStaff(['orders.orders.create']);

        $this->post(route('admin.orders.store'), [
            'client_id' => $this->client->id, 'product_id' => 1, 'cycle' => 'weekly',
            'price_override' => '1e3', 'payment_gateway' => 'bank_transfer',
        ])->assertSessionHasErrors(['cycle', 'price_override' => 'Enter a price like 10 or 12.50.']);

        expect(Order::query()->count())->toBe(0);
    });

    it('forbids staff without the create permission', function () {
        actingAsStaff(['orders.orders.view']);

        $this->get(route('admin.orders.create'))->assertForbidden();
        $this->post(route('admin.orders.store'), ['client_id' => $this->client->id])->assertForbidden();
    });
});
