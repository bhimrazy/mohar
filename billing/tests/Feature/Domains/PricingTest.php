<?php

use App\Contracts\Domains\DomainPricing;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Domains\Services\DomainPricingService;
use Carbon\CarbonImmutable;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    $this->com = DomainFixtures::com();
    $this->pricing = app(DomainPricing::class);
});

test('client group members get their slab price and others the base price', function () {
    $group = ClientGroup::query()->create(['name' => 'Resellers']);
    TldPrice::query()->create(['tld_id' => $this->com->id, 'currency' => 'USD', 'client_group_id' => $group->id, 'years' => 1, 'register' => 900, 'renew' => 1000]);

    expect($this->pricing->price('.com', 'register', 1, 'USD', $group->id))->toBe(900)
        ->and($this->pricing->price('.com', 'register', 1, 'USD'))->toBe(1200)
        ->and($this->pricing->price('com', 'register', 2, 'USD', $group->id))->toBe(2400);
});

test('a currency without price rows is not sellable', function () {
    expect($this->pricing->price('.com', 'register', 1, 'EUR'))->toBeNull()
        ->and(app(DomainPricingService::class)->terms('.com', 'register', 'EUR'))->toBe([]);
});

test('transfers are sold for the lowest configured term only', function () {
    $service = app(DomainPricingService::class);

    expect($service->terms('.com', 'transfer', 'USD'))->toBe([1])
        ->and($this->pricing->price('.com', 'transfer', 1, 'USD'))->toBe(1100)
        ->and($this->pricing->price('.com', 'transfer', 2, 'USD'))->toBeNull();
});

test('terms outside the TLD limits or disabled actions are not offered', function () {
    $this->com->update(['max_years' => 3, 'allow_register' => false]);
    app(DomainPricingService::class)->flush();

    expect($this->pricing->price('.com', 'renew', 4, 'USD'))->toBeNull()
        ->and($this->pricing->price('.com', 'renew', 3, 'USD'))->toBe(4200)
        ->and($this->pricing->price('.com', 'register', 1, 'USD'))->toBeNull()
        ->and($this->pricing->price('.unknown', 'register', 1, 'USD'))->toBeNull();
});

test('domains split on the longest configured extension', function () {
    Tld::factory()->extension('.co.uk')->create();
    Tld::factory()->extension('.uk')->create();

    expect($this->pricing->split('Example.CO.UK'))->toBe(['example', '.co.uk'])
        ->and($this->pricing->split('example.uk'))->toBe(['example', '.uk'])
        ->and($this->pricing->split('www.example.com'))->toBe(['example', '.com'])
        ->and($this->pricing->split('sub.example.com'))->toBeNull()
        ->and($this->pricing->split('example.dev'))->toBeNull()
        ->and($this->pricing->split('not valid.com'))->toBeNull();
});

test('renewal terms never exceed the ten year registry horizon', function () {
    $domain = Domain::factory()->expiring('2030-06-01')->create(['tld_id' => $this->com->id]);

    $terms = app(DomainPricingService::class)->renewalTerms($domain, 'USD', null, CarbonImmutable::parse('2026-10-17'));

    expect(array_column($terms, 'years'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($terms[0]['price'])->toBe(1400);
});

test('renewal orders are hidden until the renew window opens', function () {
    $this->com->update(['renew_window_days' => 180]);
    $domain = Domain::factory()->expiring('2027-12-01')->create(['tld_id' => $this->com->id]);
    $service = app(DomainPricingService::class);

    expect($service->renewalTerms($domain, 'USD', null, CarbonImmutable::parse('2027-05-01')))->toBe([])
        ->and($service->renewalTerms($domain, 'USD', null, CarbonImmutable::parse('2027-06-05')))->not->toBe([]);
});
