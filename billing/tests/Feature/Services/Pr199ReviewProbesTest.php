<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

/**
 * Review probes for PR #199, adopted as regression tests: the client "Nameservers" card.
 */
beforeEach(function () {
    $this->kit = ServicesTestKit::bind();
    [$this->user, $this->client] = actingAsClient();
});

test('probe: with no server nameservers and no saved setting, the card shows nothing rather than placeholder hosts', function () {
    $service = Service::factory()->create(['client_id' => $this->client->id, 'domain' => 'asha.com.np']);

    $this->get(route('client.services.show', $service->id))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', []));
});

test('probe: a JSON-stored default nameserver list is read like the Domains module reads it', function () {
    $service = Service::factory()->create(['client_id' => $this->client->id, 'domain' => 'asha.com.np']);
    app(Settings::class)->set('domains.default_nameservers', '["ns1.fallback.test","ns2.fallback.test"]');

    $this->get(route('client.services.show', $service->id))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['ns1.fallback.test', 'ns2.fallback.test']));
});

test('probe: a service on a server with nameservers but no domain still shows them (no information dropped)', function () {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id, 'domain' => null]);
    $service->server->forceFill(['nameservers' => ['ns1.host.test', 'ns2.host.test']])->save();

    $this->get(route('client.services.show', $service->id))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['ns1.host.test', 'ns2.host.test']));
});
