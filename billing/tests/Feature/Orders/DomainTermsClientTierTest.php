<?php

use App\Modules\Core\Models\Currency;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    $this->fakes = OrdersFakes::bind();
});

test('the store domain search lists base terms for a visitor', function () {
    Currency::factory()->create();

    $this->get(route('store.domains', ['domain' => 'acme.com']))->assertOk();

    expect($this->fakes->domains->termsFor)->not->toBeEmpty()->and(array_unique($this->fakes->domains->termsFor))->toBe([null]);
});

test('the store domain search lists terms for the signed-in client', function () {
    [, $client] = actingAsClient();

    $this->get(route('store.domains', ['domain' => 'acme.com']))->assertOk();

    expect($this->fakes->domains->termsFor)->not->toBeEmpty()->and(array_unique($this->fakes->domains->termsFor))->toBe([$client->id]);
});
