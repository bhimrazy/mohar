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
    'typed trunk zero after the code' => ['+977 015550100', null, '977', '15550100'],
    'typed trunk zero, UK' => ['+44 020 7946 0958', null, '44', '2079460958'],
    'Italy keeps the zero' => ['+39 06 1234 5678', null, '39', '0612345678'],
    'San Marino keeps the zero' => ['+378 0549 123456', null, '378', '0549123456'],
    'Vatican, 00 form' => ['0039 06 698 12345', 'VA', '39', '0669812345'],
    'Australia, 00 form' => ['0061 2 9999 9999', null, '61', '299999999'],
    'explicit code beats country' => ['+91 98765 43210', 'NP', '91', '9876543210'],
]);

it('treats input without a prefix as national and uses the country', function (string $input, string $country, string $code, string $national) {
    expect(CallingCodes::split($input, $country))->toBe(['code' => $code, 'national' => $national]);
})->with([
    'Nepal with trunk zero' => ['01-5550100', 'NP', '977', '15550100'],
    'Nepal mobile' => ['984 123 4567', 'np', '977', '9841234567'],
    'India' => ['098765 43210', 'IN', '91', '9876543210'],
    'Canada shares 1' => ['(604) 555-0100', 'CA', '1', '6045550100'],
    'Italy keeps the zero' => ['06 1234 5678', 'IT', '39', '0612345678'],
    'Vatican keeps the zero' => ['06 698 12345', 'VA', '39', '0669812345'],
    'San Marino keeps the zero' => ['0549 123456', 'SM', '378', '0549123456'],
    'NANP with trunk 1' => ['1 415 555 0132', 'US', '1', '4155550132'],
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
    'Australian exit prefix 0011' => ['0011 61 2 9999 9999', 'AU'],
    'Australian exit prefix, no country' => ['0011 977 15550100', null],
    'US exit prefix 011' => ['011 977 15550100', 'US'],
    'NANP area code starting 1' => ['+1 115 555 0132', null],
    'NANP too short' => ['+1 415 555 013', null],
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
    $violations = [];
    $seen = [];

    foreach ($codes as $code) {
        if (preg_match('/^[1-9]\d{0,2}$/', $code) !== 1) {
            $violations[] = "+{$code} is malformed";
        }

        foreach ($codes as $other) {
            if ($code !== $other && str_starts_with($other, $code)) {
                $violations[] = "+{$code} is a prefix of +{$other}";
            }
        }

        foreach (CallingCodes::countries($code) as $country) {
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || isset($seen[$country])) {
                $violations[] = "{$country} under +{$code} is malformed or repeated";
            }

            $seen[$country] = true;
        }
    }

    expect($violations)->toBe([]);
});

it('matches known ITU assignments', function () {
    $golden = [
        'NP' => '977', 'IN' => '91', 'US' => '1', 'CA' => '1', 'GB' => '44', 'RU' => '7', 'KZ' => '7', 'AU' => '61',
        'IT' => '39', 'VA' => '39', 'SM' => '378', 'DE' => '49', 'FR' => '33', 'JP' => '81', 'KR' => '82', 'CN' => '86',
        'BD' => '880', 'BT' => '975', 'LK' => '94', 'PK' => '92', 'AE' => '971', 'SG' => '65', 'MY' => '60', 'ID' => '62',
        'TH' => '66', 'VN' => '84', 'PH' => '63', 'NZ' => '64', 'ZA' => '27', 'NG' => '234', 'KE' => '254', 'EG' => '20',
        'BR' => '55', 'MX' => '52', 'AR' => '54', 'CH' => '41', 'ES' => '34', 'NL' => '31', 'IE' => '353', 'IS' => '354',
        'HK' => '852', 'TW' => '886', 'AC' => '247',
    ];

    foreach ($golden as $country => $code) {
        expect(CallingCodes::forCountry($country))->toBe($code, $country);
    }

    foreach (['1' => 'US', '7' => 'RU', '39' => 'IT', '44' => 'GB', '47' => 'NO', '61' => 'AU', '212' => 'MA', '262' => 'RE', '358' => 'FI', '590' => 'GP', '599' => 'CW', '672' => 'NF'] as $code => $main) {
        expect(CallingCodes::countries((string) $code)[0])->toBe($main);
    }
});

it('accepts 15 digits in all and refuses 16', function () {
    expect(CallingCodes::split('+44 2079460958123'))->toBe(['code' => '44', 'national' => '2079460958123'])
        ->and(CallingCodes::split('+44 20794609581234'))->toBeNull();
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
