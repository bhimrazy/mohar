<?php

use App\Contracts\Orders\PaymentGuard;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoicePaid;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Events\Orders\OrderSetPending;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\SetOrderPending;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Fraud\FraudContext;
use App\Modules\Orders\Fraud\FraudResult;
use App\Modules\Orders\Fraud\RulesFraudChecker;
use App\Modules\Orders\Jobs\ProvisionOrderItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Services\CheckoutGateways;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind();
    $this->client = Client::factory()->create();
});

function ordersFraudDecision(FraudDecision $decision): void
{
    app()->instance(RulesFraudChecker::class, new class($decision) extends RulesFraudChecker
    {
        public function __construct(private FraudDecision $decision) {}

        public function check(FraudContext $context): FraudResult
        {
            return new FraudResult($this->decision, 50, ['Test decision.']);
        }
    });
}

it('creates the order, its pending service and one unpaid invoice with a matching total', function () {
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 500]]);
    $this->fakes->billing->taxRate = 10;

    $placed = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, domain: 'acme.test')]));

    $order = $placed->order;
    expect(Order::query()->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal)->toBe(1500)
        ->and($order->setup_total)->toBe(500)
        ->and($order->tax_total)->toBe(150)
        ->and($order->total)->toBe(1650)
        ->and($order->number)->toMatch('/^[1-9]\d{9}$/');

    $serviceId = array_key_first($this->fakes->services->services);
    $draft = $this->fakes->services->services[$serviceId];
    expect($draft->clientId)->toBe($this->client->id)
        ->and($draft->orderId)->toBe($order->id)
        ->and($draft->recurringAmount)->toBe(1000)
        ->and($draft->firstPaymentAmount)->toBe(1500)
        ->and($draft->domain)->toBe('acme.test');

    expect($this->fakes->billing->issued)->toHaveCount(1)
        ->and($order->invoice_id)->toBe(array_key_first($this->fakes->billing->issued))
        ->and($this->fakes->billing->status($order->invoice_id))->toBe('unpaid');

    $invoice = $this->fakes->billing->lastDraft();
    expect($invoice->status)->toBe('unpaid')
        ->and($invoice->paymentGateway)->toBe('bank_transfer')
        ->and(array_map(fn ($line) => [$line->billableType, $line->billableId, $line->unitAmount], $invoice->lines))->toBe([
            [BillableType::Service, $serviceId, 1000],
            [BillableType::ServiceSetup, $serviceId, 500],
        ])
        ->and($invoice->lines[0]->periodStart?->toDateString())->toBe(now()->toDateString())
        ->and($invoice->lines[0]->periodEnd?->toDateString())->toBe(now()->addMonthNoOverflow()->toDateString());

    $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'item_type' => 'service', 'item_id' => $serviceId, 'status' => 'pending']);
});

it('bills addons, domains and promotion discounts with their own billable types', function () {
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]])->addAddon(1, 7, 'Backups', ['monthly' => [200, 100]]);
    $promotion = Promotion::factory()->percent('50')->forProducts([1])->create();

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [
        OrdersFakes::productItem(1, domain: 'acme.com', mode: 'register', addonIds: [7]),
    ], $promotion->code));

    $serviceId = array_key_first($this->fakes->services->services);
    $addonId = array_key_first($this->fakes->services->addons);
    $domainId = array_key_first($this->fakes->domains->created);

    expect(array_map(fn ($line) => [$line->billableType, $line->billableId, $line->unitAmount], $this->fakes->billing->lastDraft()->lines))->toBe([
        [BillableType::Service, $serviceId, 1000],
        [BillableType::Promotion, $promotion->id, -500],
        [BillableType::ServiceAddon, $addonId, 200],
        [BillableType::ServiceAddon, $addonId, 100],
        [BillableType::Domain, $domainId, 1200],
    ]);

    expect($this->fakes->services->addons[$addonId]->serviceId)->toBe($serviceId)
        ->and($this->fakes->domains->created[$domainId])->domain->toBe('acme.com')->action->toBe('register')->recurringAmount->toBe(1400);
});

it('returns the first order when the same idempotency key is submitted twice', function () {
    $this->fakes->catalog->addProduct(1);
    $data = OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], key: 'checkout-key-1');

    $first = app(PlaceOrder::class)->handle($data);
    $second = app(PlaceOrder::class)->handle($data);

    expect(Order::query()->count())->toBe(1)
        ->and($second->duplicate)->toBeTrue()
        ->and($second->order->number)->toBe($first->order->number)
        ->and($this->fakes->billing->issued)->toHaveCount(1);
});

it('marks a zero-total invoice paid and fires InvoicePaid', function () {
    Event::fake([InvoicePaid::class]);
    $this->fakes->catalog->addProduct(1, 'Free Plan', ['free' => [0, 0]]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'free')]))->order;

    expect($order->total)->toBe(0)
        ->and($this->fakes->billing->status($order->invoice_id))->toBe('paid');
    Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $event) => $event->invoiceId === $order->invoice_id);
});

it('dispatches provisioning for on-order products when the fraud check passes', function () {
    Queue::fake([ProvisionOrderItem::class]);
    ordersFraudDecision(FraudDecision::Pass);
    $this->fakes->catalog->addProduct(1, autoSetup: 'order')->addProduct(2, 'Email', autoSetup: 'payment');

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1), OrdersFakes::productItem(2)]))->order;

    $onOrderItem = $order->items()->where('product_id', 1)->sole();
    Queue::assertPushed(ProvisionOrderItem::class, 1);
    Queue::assertPushed(ProvisionOrderItem::class, fn (ProvisionOrderItem $job) => $job->orderItemId === $onOrderItem->id);
});

it('does not provision when the fraud check asks for review', function () {
    Queue::fake([ProvisionOrderItem::class]);
    ordersFraudDecision(FraudDecision::Review);
    $this->fakes->catalog->addProduct(1, autoSetup: 'order');

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect($order->fraud_decision)->toBe(FraudDecision::Review)->and($order->status)->toBe(OrderStatus::Pending);
    Queue::assertNotPushed(ProvisionOrderItem::class);
});

it('treats a fraud provider error as review when failing open', function () {
    Queue::fake([ProvisionOrderItem::class]);
    app()->instance(RulesFraudChecker::class, new class(app(Settings::class)) extends RulesFraudChecker
    {
        public function check(FraudContext $context): FraudResult
        {
            throw new RuntimeException('Provider timeout');
        }
    });
    $this->fakes->catalog->addProduct(1, autoSetup: 'order');

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect($order->fraud_decision)->toBe(FraudDecision::Error)->and($order->status)->toBe(OrderStatus::Pending);
    Queue::assertNotPushed(ProvisionOrderItem::class);
});

it('flags the order and items as fraud on a failed check and refuses payment until set back to pending', function () {
    Event::fake([OrderMarkedFraud::class, OrderPlaced::class, OrderSetPending::class]);
    ordersFraudDecision(FraudDecision::Fail);
    $this->fakes->catalog->addProduct(1);
    $promotion = Promotion::factory()->percent('10')->create(['max_uses' => 1]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], $promotion->code))->order;
    $item = $order->items()->sole();
    $guard = app(PaymentGuard::class);

    expect($order->status)->toBe(OrderStatus::Fraud)
        ->and($item->status)->toBe(OrderItemStatus::Fraud)
        ->and($this->fakes->services->cancelled)->toBe([])
        ->and($this->fakes->billing->status($order->invoice_id))->toBe('unpaid')
        ->and($this->fakes->billing->lastDraft()->notify)->toBeFalse()
        ->and($guard->refusalReason($order->invoice_id))->toBe('This order is under review. Payment will be possible once the review is complete.')
        ->and($promotion->fresh()->uses)->toBe(1);
    Event::assertDispatched(OrderMarkedFraud::class, fn (OrderMarkedFraud $event) => $event->orderId === $order->id);
    Event::assertDispatched(OrderPlaced::class);

    app(SetOrderPending::class)->handle($order);

    expect($order->fresh())->status->toBe(OrderStatus::Pending)->invoice_id->toBe($order->invoice_id)
        ->and($item->fresh()->status)->toBe(OrderItemStatus::Pending)
        ->and($guard->refusalReason($order->invoice_id))->toBeNull()
        ->and($promotion->fresh()->uses)->toBe(1);
    Event::assertDispatched(OrderSetPending::class, fn (OrderSetPending $event) => $event->orderId === $order->id);
});

it('refuses payment while the order waits for a manual fraud review', function () {
    ordersFraudDecision(FraudDecision::Review);
    $this->fakes->catalog->addProduct(1);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect(app(PaymentGuard::class)->refusalReason($order->invoice_id))->toBe('This order is waiting for a security review. You can pay once it has been approved.');
});

it('allows payment of an ordinary order and of invoices that belong to no order', function () {
    $this->fakes->catalog->addProduct(1);
    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect(app(PaymentGuard::class)->refusalReason($order->invoice_id))->toBeNull()
        ->and(app(PaymentGuard::class)->refusalReason(424242))->toBeNull();
});

it('holds account credit back until the fraud check allows it', function (FraudDecision $decision, bool $credit) {
    ordersFraudDecision($decision);
    $this->fakes->catalog->addProduct(1);
    $data = OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]);

    app(PlaceOrder::class)->handle(new CheckoutData(...[...get_object_vars($data), 'applyCredit' => true]));

    expect($this->fakes->billing->lastDraft()->applyCredit)->toBe($credit);
})->with([
    'pass' => [FraudDecision::Pass, true],
    'review' => [FraudDecision::Review, false],
    'fail' => [FraudDecision::Fail, false],
]);

it('accepts a zero-total order automatically when auto-accept is on', function () {
    config(['orders.auto_accept_on_payment' => true]);
    ordersFraudDecision(FraudDecision::Pass);
    $this->fakes->catalog->addProduct(1, 'Free Plan', ['free' => [0, 0]]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'free')]))->order;

    expect($order->status)->toBe(OrderStatus::Active);
});

it('auto-accepts paid orders whose fraud check was skipped', function () {
    config(['orders.auto_accept_on_payment' => true]);
    $this->fakes->catalog->addProduct(1, 'Free Plan', ['free' => [0, 0]]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'free')], skipFraud: true))->order;

    expect($order->fraud_decision)->toBe(FraudDecision::Skipped)->and($order->status)->toBe(OrderStatus::Active);
});

it('does not auto-accept a paid order that needs a fraud review', function () {
    config(['orders.auto_accept_on_payment' => true]);
    ordersFraudDecision(FraudDecision::Review);
    $this->fakes->catalog->addProduct(1, 'Free Plan', ['free' => [0, 0]]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'free')]))->order;

    expect($order->status)->toBe(OrderStatus::Pending);
});

it('refuses a reused idempotency key with a different cart and keeps the first order', function () {
    $this->fakes->catalog->addProduct(1)->addProduct(2, 'VPS');
    $first = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], key: 'key-1'))->order;

    expect(fn () => app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(2)], key: 'key-1')))
        ->toThrow(ValidationException::class, 'This checkout was already submitted with different details. Reload the page and try again.');

    expect(Order::query()->sole()->id)->toBe($first->id);
});

it('refuses another account replaying an idempotency key', function () {
    $this->fakes->catalog->addProduct(1);
    $item = OrdersFakes::productItem(1);
    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [$item], key: 'key-1'));

    app(PlaceOrder::class)->handle(OrdersFakes::checkout(Client::factory()->create(), [$item], key: 'key-1'));
})->throws(ValidationException::class, 'This checkout was already submitted with different details.');

it('passes recurring promotion terms to the service for renewals', function () {
    $this->fakes->catalog->addProduct(1);
    $promotion = Promotion::factory()->percent('20')->create(['recurring' => true, 'recur_count' => 2]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], $promotion->code))->order;
    $draft = $this->fakes->services->services[$order->items()->sole()->item_id];

    expect($draft->promotionId)->toBe($promotion->id)
        ->and($draft->promotionCyclesRemaining)->toBe(2)
        ->and($draft->renewalDiscount)->toBe(200)
        ->and($draft->recurringAmount)->toBe(1000);
});

it('quotes products and domains for the ordering client so group pricing applies', function () {
    $this->fakes->catalog->addProduct(1);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, domain: 'acme.com', mode: 'register')]));

    expect(array_unique($this->fakes->catalog->quotedFor))->toBe([$this->client->id])
        ->and(array_unique($this->fakes->domains->pricedFor))->toBe([$this->client->id]);
});

it('registers the bundled domain for free on qualifying cycles', function (string $cycle, bool $free) {
    $this->fakes->catalog->addProduct(1, pricing: ['monthly' => [1000, 0], 'annually' => [10000, 0]], freeDomain: true, freeDomainTlds: ['.com'], freeDomainCycles: ['annually']);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, $cycle, 'acme.com', 'register')]));

    $domain = $this->fakes->domains->created[array_key_first($this->fakes->domains->created)];
    expect($domain->firstPaymentAmount)->toBe($free ? 0 : 1200)
        ->and($domain->recurringAmount)->toBe(1400);
})->with([
    'annual plan' => ['annually', true],
    'monthly plan' => ['monthly', false],
]);

it('keeps the bundled domain free on renewals when the offer recurs', function () {
    $this->fakes->catalog->addProduct(1, pricing: ['annually' => [10000, 0]], freeDomain: true, freeDomainTlds: ['.com'], freeDomainCycles: ['annually'], freeDomainRecurring: true);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'annually', 'acme.com', 'register')]));

    expect($this->fakes->domains->created[array_key_first($this->fakes->domains->created)]->recurringAmount)->toBe(0);
});

it('does not give a free domain for TLDs outside the offer', function () {
    $this->fakes->catalog->addProduct(1, pricing: ['annually' => [10000, 0]], freeDomain: true, freeDomainTlds: ['.com'], freeDomainCycles: ['annually']);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, 'annually', 'acme.net', 'register')]));

    expect($this->fakes->domains->created[array_key_first($this->fakes->domains->created)]->firstPaymentAmount)->toBe(1300);
});

it('re-checks custom fields at checkout when the catalog rules changed', function () {
    $this->fakes->catalog->addProduct(1);
    $this->fakes->catalog->customFields[1] = [['id' => 7, 'name' => 'Hostname', 'type' => 'text', 'required' => true, 'options' => [], 'regex' => '/^[a-z0-9.-]+\.[a-z]{2,}$/i']];
    $item = new CartItem(CartItem::newKey(), CartItemKind::Product, productId: 1, cycle: BillingCycle::Monthly, customFields: [7 => 'web01']);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [$item]));
})->throws(ValidationException::class, 'Hostname is not in the expected format.');

it('asks the fraud provider before opening the order transaction', function () {
    $this->fakes->catalog->addProduct(1);
    $seen = new ArrayObject;
    $baseline = DB::transactionLevel();
    app()->instance(RulesFraudChecker::class, new class($seen) extends RulesFraudChecker
    {
        public function __construct(private ArrayObject $seen) {}

        public function check(FraudContext $context): FraudResult
        {
            $this->seen['level'] = DB::transactionLevel();
            $this->seen['context'] = $context;

            return new FraudResult(FraudDecision::Pass);
        }
    });

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect($seen['level'])->toBe($baseline)
        ->and($seen['context']->orderId)->toBeNull()
        ->and($seen['context']->total)->toBe(1000)
        ->and($seen['context']->timeoutMs)->toBe(5000)
        ->and($order->fraud_decision)->toBe(FraudDecision::Pass)
        ->and($order->fraudChecks()->count())->toBe(1);
});

it('counts the order being placed against the pending-orders limit', function () {
    config(['orders.fraud.max_pending_orders' => 1]);
    $this->fakes->catalog->addProduct(1);
    Order::factory()->for($this->client)->create();

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]))->order;

    expect($order->fraud_decision)->toBe(FraudDecision::Fail);
});

it('passes custom field values to the service keyed by field id', function () {
    $this->fakes->catalog->addProduct(1);
    $item = new CartItem(CartItem::newKey(), CartItemKind::Product, productId: 1, cycle: BillingCycle::Monthly, customFields: [7 => 'web01.example.com']);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [$item]))->order;

    expect($this->fakes->services->services[$order->items()->sole()->item_id]->customFields)->toBe([7 => 'web01.example.com']);
});

it('stores the remaining promotion cycles of a recurring promotion on the service item', function () {
    $this->fakes->catalog->addProduct(1);
    $promotion = Promotion::factory()->percent('20')->create(['recurring' => true, 'recur_count' => 2]);

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], $promotion->code))->order;
    $item = $order->items()->sole();

    expect($item->promotion_cycles_remaining)->toBe(2)
        ->and($item->discount_amount)->toBe(200)
        ->and($this->fakes->services->services[$item->item_id]->promotionId)->toBe($promotion->id)
        ->and($promotion->fresh()->uses)->toBe(1);
    $this->assertDatabaseHas('promotion_redemptions', ['promotion_id' => $promotion->id, 'order_id' => $order->id, 'client_id' => $this->client->id]);
});

it('does not attach a one-off promotion to the service for renewals', function () {
    $this->fakes->catalog->addProduct(1);
    $promotion = Promotion::factory()->percent('20')->create();

    $order = app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], $promotion->code))->order;

    expect($this->fakes->services->services[$order->items()->sole()->item_id]->promotionId)->toBeNull();
});

it('refuses checkout when a product needs a domain and none was chosen', function () {
    $this->fakes->catalog->addProduct(1, requiresDomain: true);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]));
})->throws(ValidationException::class, 'Choose a domain for Starter Hosting.');

it('refuses checkout of an out-of-stock product', function () {
    $this->fakes->catalog->addProduct(1, stock: 0);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]));
})->throws(ValidationException::class, 'Starter Hosting is out of stock.');

it('rolls the whole order back when stock runs out while reserving', function () {
    $this->fakes->catalog->addProduct(1, stock: 1);

    try {
        app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1), OrdersFakes::productItem(1)]));
        $this->fail('Expected the checkout to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['cart'][0])->toBe('Starter Hosting is out of stock.');
    }

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and($this->fakes->billing->issued)->toBe([]);
});

it('blocks checkout when the products share no payment gateway', function () {
    $this->fakes->catalog->addProduct(1, allowedGateways: ['card'])->addProduct(2, 'VPS', allowedGateways: ['paypal']);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1), OrdersFakes::productItem(2)], gateway: 'card'));
})->throws(ValidationException::class, 'The products in your cart cannot be paid with the same payment method. Order them separately.');

it('offers only the gateways every product allows', function () {
    $this->fakes->catalog->addProduct(1, allowedGateways: ['card', 'paypal'])->addProduct(2, 'VPS', allowedGateways: ['paypal', 'bank_transfer']);

    expect(app(CheckoutGateways::class)->forProducts([1, 2], 'USD'))->toBe(['paypal' => 'PayPal']);
});

it('refuses a gateway that is not offered', function () {
    $this->fakes->catalog->addProduct(1);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], gateway: 'bitcoin'));
})->throws(ValidationException::class, 'Choose one of the available payment methods.');

it('rejects a promotion whose last use was taken while checking out', function () {
    $this->fakes->catalog->addProduct(1);
    $promotion = Promotion::factory()->percent('10')->create(['max_uses' => 1]);
    $data = OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)], $promotion->code);
    app(PlaceOrder::class)->handle($data);

    app(PlaceOrder::class)->handle(OrdersFakes::checkout(Client::factory()->create(), [OrdersFakes::productItem(1)], $promotion->code));
})->throws(ValidationException::class, 'Usage limit reached');

it('emits OrderPlaced with the created items and the referral code', function () {
    Event::fake([OrderPlaced::class]);
    $this->fakes->catalog->addProduct(1)->addAddon(1, 7);
    $data = OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, domain: 'acme.com', mode: 'register', addonIds: [7])], referralCode: 'PARTNER1');

    $order = app(PlaceOrder::class)->handle($data)->order;

    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->orderId === $order->id
        && $event->clientId === $this->client->id
        && $event->invoiceId === $order->invoice_id
        && $event->referralCode === 'PARTNER1'
        && $event->items === [
            ['type' => 'service', 'id' => array_key_first($this->fakes->services->services), 'product_id' => 1],
            ['type' => 'service_addon', 'id' => array_key_first($this->fakes->services->addons), 'product_id' => null],
            ['type' => 'domain', 'id' => array_key_first($this->fakes->domains->created), 'product_id' => null],
        ]);
});

it('refuses an order whose client currency changed after the checkout was priced (#218)', function () {
    Currency::factory()->npr()->create();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]]);
    $checkout = OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1)]);
    // Gateways and any staff price override were chosen in USD; the client row is NPR by the time the lock is taken.
    Client::query()->whereKey($this->client->id)->update(['currency' => 'NPR']);

    expect(fn () => app(PlaceOrder::class)->handle($checkout))
        ->toThrow(ValidationException::class, 'The account currency changed while this order was placed.');
    expect(Order::query()->where('client_id', $this->client->id)->exists())->toBeFalse();
});

it('shows extension details the registrar refuses at checkout on the cart, naming the domain', function () {
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]]);
    $this->fakes->domains->createFails = ValidationException::withMessages(['tld_attributes.purpose' => 'Choose what the domain is for.']);

    try {
        app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, domain: 'acme.com', mode: 'register')]));
        $thrown = null;
    } catch (ValidationException $exception) {
        $thrown = $exception->errors();
    }

    expect($thrown)->toHaveKey('cart')
        ->and($thrown['cart'][0])->toContain('acme.com')->toContain('Choose what the domain is for.')
        ->and(Order::query()->count())->toBe(0);
});

it('names the domain when the registrar cannot take orders for its extension at checkout', function () {
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 0]]);
    $this->fakes->domains->createFails = ValidationException::withMessages(['tld_attributes' => 'We cannot take .com registrations right now. Please try again later.']);

    try {
        app(PlaceOrder::class)->handle(OrdersFakes::checkout($this->client, [OrdersFakes::productItem(1, domain: 'acme.com', mode: 'register')]));
        $thrown = null;
    } catch (ValidationException $exception) {
        $thrown = $exception->errors();
    }

    expect($thrown['cart'][0])->toStartWith('acme.com: We cannot take .com registrations right now.');
});
