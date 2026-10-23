<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainInvoice;
use App\Modules\Domains\Models\Tld;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    [$this->user, $this->client] = actingAsClient();
    $np = Tld::factory()->extension('.com.np')->create();
    $this->selfRegistered = Domain::factory()->create(['tld_id' => $np->id, 'client_id' => $this->client->id, 'registrar_key' => null, 'expiry_date' => null, 'registration_date' => null, 'recurring_amount' => 0, 'auto_renew' => false]);
    $this->sold = Domain::factory()->create(['tld_id' => DomainFixtures::com()->id, 'client_id' => $this->client->id, 'registrar_key' => 'log']);
});

test('the client list and detail flag a name registered at the registry, and not one we sold', function () {
    $this->get(route('client.domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domains.data', fn ($rows) => collect($rows)->firstWhere('id', $this->selfRegistered->id)['registered_by_client'] === true
            && collect($rows)->firstWhere('id', $this->sold->id)['registered_by_client'] === false));

    $this->get(route('client.domains.show', $this->selfRegistered->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', true)->where('domain.status', 'active')->where('canRenewNow', false));

    $this->get(route('client.domains.show', $this->sold->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', false));
});

test('staff see the same flag on the admin list and detail', function () {
    actingAsStaff(['domains.*']);

    $this->get(route('admin.domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domains.data', fn ($rows) => collect($rows)->firstWhere('id', $this->selfRegistered->id)['registered_by_client'] === true
            && collect($rows)->firstWhere('id', $this->sold->id)['registered_by_client'] === false));

    $this->get(route('admin.domains.show', $this->selfRegistered->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', true));
});

test('mail merge fields name the status and expiry of a name registered at the registry', function () {
    $fields = app(MergeFieldRegistry::class);

    expect($fields->resolve('domain', $this->selfRegistered->id))->toMatchArray([
        'status' => 'active',
        'status_label' => 'Self-registered',
        'registered_by_client' => true,
        'expiry_date' => null,
        'expiry_label' => 'At the registry',
    ])->and($fields->resolve('domain', $this->sold->id))->toMatchArray([
        'status_label' => 'Active',
        'registered_by_client' => false,
        'expiry_label' => null,
    ]);
});

test('auto-renew and renew now are refused for a name registered at the registry, with no change', function () {
    $this->put(route('client.domains.auto-renew', $this->selfRegistered->id), ['enabled' => true])
        ->assertSessionHasErrors('domain');
    $this->post(route('client.domains.renew', $this->selfRegistered->id))
        ->assertSessionHasErrors('domain');

    expect($this->selfRegistered->fresh()->auto_renew)->toBeFalse()
        ->and(DomainInvoice::query()->where('domain_id', $this->selfRegistered->id)->count())->toBe(0);

    $this->put(route('client.domains.auto-renew', $this->sold->id), ['enabled' => false])->assertSessionHasNoErrors();
    expect($this->sold->fresh()->auto_renew)->toBeFalse();
});
