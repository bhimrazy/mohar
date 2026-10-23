<?php

use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubContacts;
use App\Support\Domains\RegistrarException;

test('a typed phone number becomes ResellerClub\'s country code and number, whatever separator it uses', function (string $typed, string $country, array $expected) {
    expect(ResellerClubContacts::phone($typed, $country))->toBe($expected);
})->with([
    'dot after the code' => ['+977.15550100', 'NP', ['977', '15550100']],
    'space after the code' => ['+977 1-5550100', 'NP', ['977', '15550100']],
    'Nepal, no separator (the doubled code of #377)' => ['+9779841234567', 'NP', ['977', '9841234567']],
    'Nepal, no separator, spaces in the number' => ['+977 98 4123 4567', 'NP', ['977', '9841234567']],
    'Nepal, no separator, spaces inside the code part' => ['+97798 4123 4567', 'NP', ['977', '9841234567']],
    'United States, +1' => ['+14155550100', 'US', ['1', '4155550100']],
    'NANP number with an area code' => ['+1 415 555 0100', 'US', ['1', '4155550100']],
    'NANP number from Canada, no separator' => ['+16045550100', 'CA', ['1', '6045550100']],
    'Bangladesh, +880' => ['+8801712345678', 'BD', ['880', '1712345678']],
    'United States, +1 with spaces' => ['+1 415 555 0100', 'US', ['1', '4155550100']],
    'United Kingdom, +44' => ['+442079460958', 'GB', ['44', '2079460958']],
    'United Kingdom, +44 with spaces' => ['+44 20 7946 0958', 'GB', ['44', '2079460958']],
    'United Arab Emirates, +971' => ['+971501234567', 'AE', ['971', '501234567']],
    'a code that differs from the contact\'s country' => ['+971501234567', 'NP', ['971', '501234567']],
    'local number with the contact\'s country' => ['01-5550100', 'NP', ['977', '15550100']],
]);

test('a number that starts with + and an unknown country code is refused with the way to write it', function () {
    expect(fn () => ResellerClubContacts::phone('+0009841234567', 'NP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('+8001234567', 'NP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('+85221234567', 'HK'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('+81312345678', 'JP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('555 0100', 'ZZ'))->toThrow(RegistrarException::class, 'country code');
});

test('a country code with nothing after it is refused', function (string $typed) {
    expect(fn () => ResellerClubContacts::phone($typed, 'NP'))->toThrow(RegistrarException::class, 'no number');
})->with(['+977', '+1', '+ 44', '+971 ']);
