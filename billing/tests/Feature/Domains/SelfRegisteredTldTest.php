<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use Illuminate\Support\Facades\Http;

/**
 * .np: the client registers names at the registry; the host only sells hosting for them (registrars.md §9).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    config(['domains.lookup_registrar' => 'log']);
    $this->np = Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'log']);
});

test('.np and its second levels are registered by the client by default; other extensions are not', function () {
    expect($this->np->fresh()->registered_by_client)->toBeTrue()
        ->and(Tld::factory()->extension('.np')->create()->fresh()->registered_by_client)->toBeTrue()
        ->and(Tld::factory()->extension('.com')->create()->fresh()->registered_by_client)->toBeFalse()
        ->and(Tld::factory()->extension('.org.np')->create(['registered_by_client' => false])->fresh()->registered_by_client)->toBeFalse();
});

test('domain search explains self-registration with the registry link and "I already have it", and sells nothing', function () {
    $this->getJson(route('domains.lookup', ['q' => 'asha.com.np']))
        ->assertOk()
        ->assertJsonPath('results.0.status', 'self_registered')
        ->assertJsonPath('results.0.sellable', false)
        ->assertJsonPath('results.0.transferable', false)
        ->assertJsonPath('results.0.order_url', null)
        ->assertJsonPath('results.0.registration_url', 'https://register.com.np/how-to-register-np-domain')
        ->assertJsonPath('results.0.existing_url', route('store.index', ['domain' => 'asha.com.np']));
});

test('a self-registered domain cannot be added to the cart for registration or transfer', function (string $action) {
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.com.np', 'action' => $action, 'years' => 1, 'epp_code' => 'X'])
        ->assertSessionHasErrors(['domain' => '.com.np domains are not sold here. If you already own one, choose "Use a domain I own".']);

    expect(Domain::query()->count())->toBe(0);
})->with(['register', 'transfer']);
