<?php

use App\Events\Billing\InvoicePaid;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Support\Events\WebhookEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

/** Every type that was in Notifications' closed map before ADR 0011's registry, with its payload source. */
const WEBHOOK_TYPES_BEFORE_REGISTRY = [
    'client.registered' => ['client', 'clientId'], 'client.updated' => ['client', 'clientId'],
    'order.placed' => ['order', 'orderId'], 'order.fraud' => ['order', 'orderId'], 'order.cancelled' => ['order', 'orderId'],
    'invoice.created' => ['invoice', 'invoiceId'], 'invoice.paid' => ['invoice', 'invoiceId'], 'invoice.overdue' => ['invoice', 'invoiceId'],
    'invoice.cancelled' => ['invoice', 'invoiceId'], 'invoice.refunded' => ['invoice', 'invoiceId'],
    'payment.received' => ['transaction', 'transactionId'], 'payment.failed' => ['invoice', 'invoiceId'],
    'payment.reversed' => ['transaction', 'transactionId'], 'payment.reinstated' => ['transaction', 'transactionId'],
    'quote.accepted' => ['quote', 'quoteId'],
    'service.activated' => ['service', 'serviceId'], 'service.suspended' => ['service', 'serviceId'], 'service.unsuspended' => ['service', 'serviceId'],
    'service.renewed' => ['service', 'serviceId'], 'service.upgraded' => ['service', 'serviceId'], 'service.cancelled' => ['service', 'serviceId'],
    'service.terminated' => ['service', 'serviceId'],
    'domain.registered' => ['domain', 'domainId'], 'domain.renewed' => ['domain', 'domainId'], 'domain.transferred' => ['domain', 'domainId'],
    'domain.expired' => ['domain', 'domainId'],
    'ticket.opened' => ['ticket', 'ticketId'], 'ticket.replied' => ['ticket', 'ticketId'], 'ticket.closed' => ['ticket', 'ticketId'],
    'affiliate.commission_earned' => ['affiliate', 'affiliateId'],
];

test('every type that was listed before the registry is still registered, with its entity and id property', function () {
    $registry = app(WebhookEvents::class);

    expect($registry->keys())->toEqualCanonicalizing(array_keys(WEBHOOK_TYPES_BEFORE_REGISTRY));

    foreach ($registry->events() as $event) {
        $registered = $registry->forEvent($event);

        expect($event)->toBeString()
            ->and([$registered['entity'], $registered['idProperty']])->toBe(WEBHOOK_TYPES_BEFORE_REGISTRY[$registered['type']]);
    }

    expect($registry->events())->toHaveCount(30)->and($registry->types())->toContain('webhook.ping');
});

test('the endpoint form lists the registered types', function () {
    actingAsStaff(['notifications.webhooks.manage']);
    $this->get(route('admin.notifications.webhooks.create'))
        ->assertInertia(fn ($page) => $page->where('eventTypes', app(WebhookEvents::class)->types()));
});

test('a type or an event class is registered once unless replace is passed, and a replacement is logged', function () {
    Log::spy();
    $registry = new WebhookEvents;
    $registry->register(InvoicePaid::class, 'invoice.paid', 'invoice', 'invoiceId');

    expect(fn () => $registry->register(InvoicePaid::class, 'invoice.settled'))->toThrow(LogicException::class)
        ->and(fn () => $registry->register(stdClass::class, 'invoice.paid'))->toThrow(LogicException::class);

    $registry->register(InvoicePaid::class, 'invoice.settled', replace: true);

    expect($registry->keys())->toBe(['invoice.settled'])
        ->and($registry->forEvent(InvoicePaid::class))->toBe(['type' => 'invoice.settled', 'entity' => null, 'idProperty' => null]);
    Log::shouldHaveReceived('info')->once()->with('Webhook event replaced.', ['type' => 'invoice.settled', 'event' => InvoicePaid::class]);
});

test('types are <area>.<action> in lowercase, up to 50 characters, and the test event is reserved', function (string $type) {
    (new WebhookEvents)->register(stdClass::class, $type);
})->with(['', 'invoice', 'Invoice.paid', 'invoice-paid.sent', 'webhook.ping', str_repeat('a', 49).'.b'])->throws(InvalidArgumentException::class);

test('registering resolves nothing', function () {
    (new WebhookEvents)->register('Acme\\Missing\\SomethingHappened', 'acme.happened');
})->throwsNoExceptions();

test('a type registered by a package is offered, accepted and delivered', function () {
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $event = new class(5)
    {
        public function __construct(public int $widgetId) {}
    };
    app(WebhookEvents::class)->register($event::class, 'acme.widget_built', idProperty: 'widgetId');
    WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/in', 'events' => ['acme.widget_built'], 'secret' => 'whsec_test']);

    actingAsStaff(['notifications.webhooks.manage']);
    $this->get(route('admin.notifications.webhooks.create'))
        ->assertInertia(fn ($page) => $page->where('eventTypes', fn ($types) => collect($types)->contains('acme.widget_built')));
    confirmPassword();
    $this->post(route('admin.notifications.webhooks.store'), ['url' => 'https://hooks.example.com/two', 'events' => ['acme.widget_built'], 'is_active' => true])->assertSessionHasNoErrors();

    app(WebhookPublisher::class)->publishEvent($event);

    Http::assertSent(fn (Request $request): bool => $request->header('X-Webhook-Event')[0] === 'acme.widget_built' && $request['data']['ids'] === ['widget_id' => 5]);
    expect(WebhookDelivery::query()->count())->toBe(2);
});

test('an unregistered type is refused by the endpoint form', function () {
    actingAsStaff(['notifications.webhooks.manage']);
    confirmPassword();
    $this->post(route('admin.notifications.webhooks.store'), ['url' => 'https://hooks.example.com/in', 'events' => ['acme.unknown'], 'is_active' => true])
        ->assertSessionHasErrors('events.0');
});
