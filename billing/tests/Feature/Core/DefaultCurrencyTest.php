<?php

use App\Modules\Core\Models\Currency;

it('returns a usable rate for the default currency it has to create', function () {
    Currency::query()->delete();

    expect(Currency::default())->code->toBe('USD')->exchange_rate->toBe('1.000000000000');
});
