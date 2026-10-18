<?php

use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Services\Tax\VatNumber;

test('EU VAT numbers are validated against the country format', function (?string $vatId, ?string $country, bool $valid) {
    expect(VatNumber::isValidFormat($vatId, $country))->toBe($valid);
})->with([
    ['FR12345678901', 'FR', true],
    ['fr 12 345678901', 'FR', true],
    ['DE123456789', 'DE', true],
    ['EL123456789', 'GR', true],
    ['DE12345', 'DE', false],
    ['FR12345678901', 'DE', false],
    ['US123456789', 'US', false],
    [null, 'FR', false],
]);

test('sandbox card numbers are checked with the Luhn algorithm', function () {
    expect(SandboxCardDriver::passesLuhn('4242 4242 4242 4242'))->toBeTrue()
        ->and(SandboxCardDriver::passesLuhn('4242424242424241'))->toBeFalse()
        ->and(SandboxCardDriver::brand('5555555555554444'))->toBe('mastercard');
});
