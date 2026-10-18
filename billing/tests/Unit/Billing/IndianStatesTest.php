<?php

use App\Modules\Billing\Services\Tax\IndianStates;

test('states normalise from ISO code, GST code or name', function (string $input, ?string $iso) {
    expect(IndianStates::normalize($input))->toBe($iso);
})->with([
    ['MH', 'MH'],
    ['27', 'MH'],
    ['maharashtra', 'MH'],
    [' Karnataka ', 'KA'],
    ['Atlantis', null],
]);

test('GSTIN format and state of registration', function () {
    expect(IndianStates::isValidGstin('27AAPFU0939F1ZV'))->toBeTrue()
        ->and(IndianStates::stateFromGstin('29AAGCB7383J1Z4'))->toBe('KA')
        ->and(IndianStates::isValidGstin('99AAPFU0939F1ZV'))->toBeFalse()
        ->and(IndianStates::isValidGstin('27AAPFU0939F1Z'))->toBeFalse();
});

test('place of supply uses the GSTIN state for registered businesses', function () {
    expect(IndianStates::placeOfSupply('Maharashtra', '29AAGCB7383J1Z4'))->toBe('KA')
        ->and(IndianStates::placeOfSupply('Maharashtra', null))->toBe('MH');
});
