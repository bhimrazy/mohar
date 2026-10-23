<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Domains\Models\Domain;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Billing/helpers.php';

/*
 * Review probes for #424: each area grant sees only its own counters and panels, on every client page the user can
 * open; the owner sees all; the dashboard's `client` prop does not hand profile data to a user without `profile`.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'Asia/Kathmandu'));
    Currency::factory()->npr()->create();
    [$this->owner, $this->client] = actingAsClient(Client::factory()->create([
        'currency' => 'NPR', 'country' => 'NP', 'address_1' => 'Secret Street 1', 'phone' => '+9779800000000', 'tax_id' => '601234567',
    ]));
    issueInvoice($this->client, [5_000], dueDate: CarbonImmutable::parse('2026-10-20'));
    Service::factory()->create(['client_id' => $this->client->id]);
    Ticket::factory()->forClient($this->client)->create();
    Domain::factory()->create(['client_id' => $this->client->id]);
});

function probeUser(Client $client, array $areas): User
{
    $user = User::factory()->create();
    $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode($areas)]);

    return $user;
}

function probeProps(): array
{
    return test()->get(route('client.dashboard'))->assertOk()->viewData('page')['props'];
}

function keysOf(array $props): array
{
    return [
        'notices' => array_column($props['notices'], 'key'),
        'cards' => array_column($props['cards'], 'key'),
        'panels' => array_column($props['panels'], 'key'),
    ];
}

test('owner sees every area', function () {
    expect(keysOf(probeProps()))->toBe([
        'notices' => ['billing.due'],
        'cards' => ['billing.unpaid', 'services.active', 'services.renewing', 'billing.credit', 'domains.expiring', 'support.open'],
        'panels' => ['services.list', 'billing.invoices', 'support.tickets', 'domains.list'],
    ]);
});

test('support-only user sees support only, and cannot open billing, services or domains pages', function () {
    $this->actingAs(probeUser($this->client, ['support']));

    expect(keysOf(probeProps()))->toBe(['notices' => [], 'cards' => ['support.open'], 'panels' => ['support.tickets']]);

    $this->get(route('client.invoices.index'))->assertForbidden();
    $this->get(route('client.services.index'))->assertForbidden();
    $this->get(route('client.domains.index'))->assertForbidden();
    $this->get(route('client.orders.index'))->assertForbidden();
    $this->get(route('client.credit.index'))->assertForbidden();
});

test('billing-only user sees billing only, and cannot open support pages', function () {
    $this->actingAs(probeUser($this->client, ['billing']));

    expect(keysOf(probeProps()))->toBe(['notices' => ['billing.due'], 'cards' => ['billing.unpaid', 'billing.credit'], 'panels' => ['billing.invoices']]);

    $this->get(route('client.support.tickets.index'))->assertForbidden();
    $this->get(route('client.account.edit'))->assertForbidden();
});

test('a user with no areas sees the empty dashboard', function () {
    $this->actingAs(probeUser($this->client, []));

    expect(keysOf(probeProps()))->toBe(['notices' => [], 'cards' => [], 'panels' => []]);
});

test('the access cache is per user and account: the owner after a sub-user still sees everything', function () {
    $context = app(ClientContext::class);
    $support = probeUser($this->client, ['support']);

    expect($context->allows($support, $this->client, 'billing'))->toBeFalse()
        ->and($context->allows($this->owner, $this->client, 'billing'))->toBeTrue()
        ->and($context->allows($support, Client::factory()->create(), 'support'))->toBeFalse()
        ->and($context->allows(null, $this->client, 'support'))->toBeFalse();
});

test('the dashboard client prop carries no profile data to a user without the profile area', function () {
    $this->actingAs(probeUser($this->client, ['support']));
    $this->get(route('client.account.edit'))->assertForbidden();

    $client = probeProps()['client'];

    expect($client)->not->toHaveKeys(['address_1', 'phone', 'tax_id', 'email']);
});
