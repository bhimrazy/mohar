<?php

use App\Support\CallingCodes;
use App\Support\Countries;

it('splits international input in every prefix style', function (string $input, ?string $country, string $code, string $national) {
    expect(CallingCodes::split($input, $country))->toBe(['code' => $code, 'national' => $national]);
})->with([
    'plus, dot' => ['+977.15550100', null, '977', '15550100'],
    'plus, spaces' => ['+977 1 5550100', null, '977', '15550100'],
    'double zero' => ['00977-9841234567', null, '977', '9841234567'],
    'India, brackets' => ['+91 (98765) 43210', 'NP', '91', '9876543210'],
    'no separator, 2 digits' => ['+81312345678', null, '81', '312345678'],
    'trunk zero in brackets' => ['+44 (0) 20 7946 0958', null, '44', '2079460958'],
    'NANP' => ['+1 (415) 555-0132', 'CA', '1', '4155550132'],
    'plus 7' => ['+7 495 123-45-67', null, '7', '4951234567'],
    'explicit code beats country' => ['+91 98765 43210', 'NP', '91', '9876543210'],
]);

it('treats input without a prefix as national and uses the country', function (string $input, string $country, string $code, string $national) {
    expect(CallingCodes::split($input, $country))->toBe(['code' => $code, 'national' => $national]);
})->with([
    'Nepal with trunk zero' => ['01-5550100', 'NP', '977', '15550100'],
    'Nepal mobile' => ['984 123 4567', 'np', '977', '9841234567'],
    'India' => ['098765 43210', 'IN', '91', '9876543210'],
    'Canada shares 1' => ['(604) 555-0100', 'CA', '1', '6045550100'],
    'Guernsey shares 44' => ['01481 712345', 'GG', '44', '1481712345'],
]);

it('returns null for anything it cannot split', function (?string $input, ?string $country) {
    expect(CallingCodes::split((string) $input, $country))->toBeNull();
})->with([
    'empty' => ['', 'NP'],
    'letters' => ['+977 abc 5550100', null],
    'extension text' => ['+977 15550100 ext 4', null],
    'no prefix, no country' => ['9841234567', null],
    'no prefix, unknown country' => ['9841234567', 'ZZ'],
    'country without a code' => ['9841234567', 'XX'],
    'unassigned code' => ['+999 12345678', null],
    'only the code' => ['+977', null],
    'too short' => ['+977 123', null],
    'over 15 digits' => ['+9771234567890123', null],
    'plus in the middle' => ['977+15550100', 'NP'],
    'only separators' => ['+ - .', null],
]);

it('maps countries and codes both ways', function () {
    expect(CallingCodes::forCountry('np'))->toBe('977')
        ->and(CallingCodes::forCountry('IN'))->toBe('91')
        ->and(CallingCodes::forCountry('AQ'))->toBe('672')
        ->and(CallingCodes::forCountry('ZZ'))->toBeNull()
        ->and(CallingCodes::countries('+1'))->toContain('US', 'CA')
        ->and(CallingCodes::countries('999'))->toBe([]);
});

it('keeps the table prefix-free and well formed', function () {
    $codes = array_map('strval', array_keys(CallingCodes::TABLE));
    $seen = [];

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[1-9]\d{0,2}$/');

        foreach ($codes as $other) {
            if ($code !== $other) {
                expect(str_starts_with($other, $code))->toBeFalse("+{$code} is a prefix of +{$other}");
            }
        }

        foreach (CallingCodes::countries($code) as $country) {
            expect($country)->toMatch('/^[A-Z]{2}$/')->and($seen)->not->toHaveKey($country);
            $seen[$country] = $code;
        }
    }
});

it('covers every checkout country', function () {
    expect(array_values(array_filter(Countries::CODES, fn ($c) => CallingCodes::forCountry($c) === null)))
        ->toBe([]);
});

it('round-trips every code with a generated number', function () {
    foreach (array_keys(CallingCodes::TABLE) as $code) {
        $code = (string) $code;
        $national = substr('5551234567890', 0, 15 - strlen($code) > 10 ? 10 : 15 - strlen($code));

        expect(CallingCodes::split("+{$code} {$national}"))->toBe(['code' => $code, 'national' => $national])
            ->and(CallingCodes::split("00{$code}-{$national}"))->toBe(['code' => $code, 'national' => $national]);
    }
});
