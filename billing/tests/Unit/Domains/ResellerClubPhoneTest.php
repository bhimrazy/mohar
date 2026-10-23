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
    'Nepal, national mobile number with country NP' => ['984-1234567', 'NP', ['977', '9841234567']],
    'Italy keeps its trunk 0' => ['06 1234 5678', 'IT', ['39', '0612345678']],
    'Italy typed with +39 keeps the 0' => ['+39 06 1234 5678', 'IT', ['39', '0612345678']],
    'Japan, a code the old table lacked' => ['+81312345678', 'JP', ['81', '312345678']],
    'Hong Kong, a code the old table lacked' => ['+85221234567', 'HK', ['852', '21234567']],
]);

test('a number split() refuses is refused with the way to write it, never sent unsplit', function () {
    expect(fn () => ResellerClubContacts::phone('+0009841234567', 'NP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('+8001234567', 'NP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('+977', 'NP'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('0011 61 2 5550 1234', 'AU'))->toThrow(RegistrarException::class, 'not recognised')
        ->and(fn () => ResellerClubContacts::phone('011 44 20 7946 0958', 'US'))->toThrow(RegistrarException::class, 'Enter the contact phone number with its country code')
        ->and(fn () => ResellerClubContacts::phone('555 0100', 'ZZ'))->toThrow(RegistrarException::class, 'country code');
});
