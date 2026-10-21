<?php

use App\Enums\BillableType;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\Service;
use App\Support\Dashboard\Dashboard;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    $this->kit = ServicesTestKit::bind();
    [$this->user, $this->client] = actingAsClient();
});

test('clients see only their own services', function () {
    $mine = Service::factory()->create(['client_id' => $this->client->id]);
    Service::factory()->create();

    $this->get(route('client.services.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/services/index')->has('services.data', 1)->where('services.data.0.id', $mine->id)->missing('services.data.0.admin_notes'));
});

test('clients cannot view or act on another account\'s service', function () {
    $foreign = Service::factory()->onServer()->create(['client_id' => Client::factory()->create()->id]);

    $this->get(route('client.services.show', $foreign->id))->assertNotFound();
    $this->post(route('client.services.cancel', $foreign->id), ['type' => 'immediate', 'reason' => 'not mine'])->assertNotFound();
    $this->post(route('client.services.password', $foreign->id), ['password' => 'Abcdefgh123', 'password_confirmation' => 'Abcdefgh123'])->assertNotFound();
    $this->post(route('client.services.change', $foreign->id), ['product_id' => 2, 'cycle' => 'monthly'])->assertNotFound();

    expect(CancellationRequest::query()->exists())->toBeFalse();
});

test('users without the services area permission are forbidden', function () {
    $other = User::factory()->create();
    $this->client->users()->attach($other, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($other);

    $this->get(route('client.services.index'))->assertForbidden();
});

test('the detail page shows status, due date, upgrade previews and actions', function () {
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn(today()->addDays(15))->onServer()->create(['client_id' => $this->client->id, 'product_id' => 1, 'admin_notes' => 'internal']);

    $this->get(route('client.services.show', $service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/services/show')
            ->where('service.id', $service->id)
            ->where('service.next_due_date', today()->addDays(15)->toDateString())
            ->missing('service.admin_notes')
            ->missing('service.password')
            ->where('can.cancel', true)
            ->where('can.changePassword', true)
            ->where('creditOnDowngrade', false)
            ->missing('changeOptions')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('changeOptions.available', true)
                ->where('changeOptions.options.0.product_name', 'Business')
                ->where('changeOptions.options.0.amount_due', fn ($amount) => $amount > 0)));
});

test('clients request cancellation once', function () {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id]);

    $this->post(route('client.services.cancel', $service->id), ['type' => 'end_of_period', 'reason' => 'Too expensive'])->assertRedirect(route('client.services.show', $service->id));
    $this->post(route('client.services.cancel', $service->id), ['type' => 'end_of_period', 'reason' => 'Again'])->assertSessionHasErrors(['reason']);

    expect(CancellationRequest::query()->sole())->requested_by_user_id->toBe($this->user->id)->by_staff->toBeFalse();
});

test('cancellation requests are validated and can be disabled for clients', function () {
    $service = Service::factory()->create(['client_id' => $this->client->id]);

    $this->post(route('client.services.cancel', $service->id), ['type' => 'whenever'])->assertSessionHasErrors(['type', 'reason']);

    app(Settings::class)->set('services.cancellation.client_can_request', false);
    $this->post(route('client.services.cancel', $service->id), ['type' => 'immediate', 'reason' => 'Bye'])->assertSessionHasErrors(['reason']);
});

test('clients order an upgrade and get an invoice', function () {
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    $service = Service::factory()->dueOn(today()->addDays(10))->create(['client_id' => $this->client->id, 'product_id' => 1]);

    $this->post(route('client.services.change', $service->id), ['product_id' => 2, 'cycle' => 'monthly'])->assertRedirect(route('client.services.show', $service->id));

    $change = $service->changes()->sole();
    expect($this->kit->invoicer->invoicesFor(BillableType::ServiceUpgrade, $change->id))->toHaveCount(1)
        ->and($service->refresh()->product_id)->toBe(1);
});

test('clients cannot upgrade to a product without an upgrade path', function () {
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(9, 'Dedicated', ['monthly' => 9000]);
    $service = Service::factory()->dueOn(today()->addDays(10))->create(['client_id' => $this->client->id, 'product_id' => 1]);

    $this->post(route('client.services.change', $service->id), ['product_id' => 9, 'cycle' => 'monthly'])->assertSessionHasErrors(['product_id']);
});

test('clients change their service password through the driver', function () {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id]);

    $this->post(route('client.services.password', $service->id), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors(['password']);
    $this->post(route('client.services.password', $service->id), ['password' => 'Str0ngPassword', 'password_confirmation' => 'Str0ngPassword'])->assertSessionHasNoErrors();

    expect($service->refresh()->password)->toBe('Str0ngPassword')
        ->and($this->kit->driver->callsFor('change_password'))->toHaveCount(1);
});

test('security review L3: a client can change a hosting password six times a minute, then is throttled', function () {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id]);

    foreach (range(1, 6) as $attempt) {
        $this->post(route('client.services.password', $service->id), ['password' => "Str0ngPassword{$attempt}", 'password_confirmation' => "Str0ngPassword{$attempt}"])->assertSessionHasNoErrors();
    }

    $this->post(route('client.services.password', $service->id), ['password' => 'Str0ngPassword7', 'password_confirmation' => 'Str0ngPassword7'])->assertTooManyRequests();

    expect($this->kit->driver->callsFor('change_password'))->toHaveCount(6);
});

test('terminated services cannot have their password changed', function () {
    $service = Service::factory()->status(ServiceStatus::Terminated)->onServer()->create(['client_id' => $this->client->id]);

    $this->post(route('client.services.password', $service->id), ['password' => 'Str0ngPassword', 'password_confirmation' => 'Str0ngPassword'])->assertSessionHasErrors(['password']);
});

test('client dashboard cards count active and soon-renewing services', function () {
    Service::factory()->dueOn(today()->addDays(5))->create(['client_id' => $this->client->id]);
    Service::factory()->dueOn(today()->addDays(60))->create(['client_id' => $this->client->id]);
    Service::factory()->create();

    $cards = collect(app(Dashboard::class)->resolve('client', $this->user, $this->client))->keyBy('key');

    expect($cards['services.active']['value'])->toBe(2)->and($cards['services.renewing']['value'])->toBe(1);
});

test('a service with a domain shows the nameservers to set at the registry: its server\'s, else the default', function () {
    $onServer = Service::factory()->onServer()->create(['client_id' => $this->client->id, 'domain' => 'asha.com.np']);
    $onServer->server->forceFill(['nameservers' => ['ns1.host.test', 'ns2.host.test']])->save();
    $withoutServer = Service::factory()->create(['client_id' => $this->client->id, 'domain' => 'bikash.com.np']);
    app(Settings::class)->set('domains.default_nameservers', "ns1.fallback.test\nns2.fallback.test");

    $this->get(route('client.services.show', $onServer->id))->assertInertia(fn (Assert $page) => $page->where('nameservers', ['ns1.host.test', 'ns2.host.test']));
    $this->get(route('client.services.show', $withoutServer->id))->assertInertia(fn (Assert $page) => $page->where('nameservers', ['ns1.fallback.test', 'ns2.fallback.test']));
});

test('staff are asked to set nameservers until a server or the default setting has real ones', function () {
    $todo = fn (): int => (int) (collect(app(Dashboard::class)->resolveTodos('admin', actingAsStaff(['services.servers.view'])))->firstWhere('key', 'services.nameservers')['count'] ?? 0);
    app(Settings::class)->set('domains.default_nameservers', config('domains.default_nameservers')); // the form saved unchanged

    expect($todo())->toBe(1);

    app(Settings::class)->set('domains.default_nameservers', 'ns1.real.test, ns2.real.test');
    expect($todo())->toBe(0);
});
