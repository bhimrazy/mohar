<?php

use App\Enums\PriceRounding;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\ConvertTldPrices;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->inr()->create(['price_rounding' => PriceRounding::NinetyNine]);
});

test('TLD prices convert per term and slab, keep manual grids and drop orphaned conversions', function () {
    $com = Tld::factory()->extension('.com')->priced(register: 1200, transfer: 1100, renew: 1400, years: 2)->create();
    $net = Tld::factory()->extension('.net')->priced(register: 1500, transfer: null, renew: 1500, years: 1)->create();
    TldPrice::query()->create(['tld_id' => $net->id, 'currency' => 'INR', 'years' => 1, 'register' => 99_900, 'renew' => 99_900, 'is_manual' => true]);
    TldPrice::query()->create(['tld_id' => $net->id, 'currency' => 'INR', 'years' => 5, 'register' => 500_000, 'renew' => 500_000]);

    $preview = app(ConvertTldPrices::class)->preview();
    expect($preview)->changes->toBe(3)->manual->toBe(1)
        ->and($preview->samples[0]->toArray())->toMatchArray(['item' => '.com register', 'currency' => 'INR', 'period' => '1 year', 'before' => null, 'after' => 100_799]);

    app(ConvertTldPrices::class)->apply();

    // 12.00 × 84 = 1008.00 → nearest .99 = 1007.99; 11.00 × 84 = 924 → 923.99; 14.00 × 84 = 1176 → 1175.99
    expect(TldPrice::query()->where('tld_id', $com->id)->where('currency', 'INR')->where('years', 1)->sole())
        ->register->toBe(100_799)->transfer->toBe(92_399)->renew->toBe(117_599)->is_manual->toBeFalse()
        ->and(TldPrice::query()->where('tld_id', $net->id)->where('currency', 'INR')->where('years', 1)->sole()->register)->toBe(99_900)
        ->and(TldPrice::query()->where('tld_id', $net->id)->where('currency', 'INR')->where('years', 5)->exists())->toBeFalse()
        ->and(app(ConvertTldPrices::class)->preview()->changes)->toBe(0);
});

test('saving a non-default grid with the manual switch marks its rows', function () {
    actingAsStaff(['domains.tlds.manage']);
    $tld = Tld::factory()->extension('.org')->priced(years: 1)->create();

    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'INR', 'manual' => true, 'prices' => [['years' => 1, 'register' => 90_000, 'transfer' => null, 'renew' => 95_000]]])
        ->assertRedirect();

    expect(TldPrice::query()->where('currency', 'INR')->sole()->is_manual)->toBeTrue();
});
