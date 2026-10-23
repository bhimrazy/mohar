<?php

use App\Modules\Core\Models\Currency;
use App\Support\Currencies\Decimal;

/** The decimal-string formula convertTo() is defined by, written out without any shortcut. */
function referenceConversion(int $amount, Currency $from, Currency $to): int
{
    $shift = $to->decimals - $from->decimals;

    return (int) Decimal::divide(
        Decimal::multiply(Decimal::multiply((string) $amount, (string) $to->exchange_rate), '1'.str_repeat('0', max(0, $shift))),
        Decimal::multiply((string) $from->exchange_rate, '1'.str_repeat('0', max(0, -$shift))),
        0,
    );
}

function rateCurrency(string $code, string $rate, int $decimals = 2): Currency
{
    return (new Currency)->forceFill(['code' => $code, 'exchange_rate' => $rate, 'decimals' => $decimals]);
}

test('a prepared converter returns exactly what convertTo returns, including halves, negatives and huge amounts', function (string $fromRate, string $toRate, int $fromDecimals, int $toDecimals) {
    $from = rateCurrency('AAA', $fromRate, $fromDecimals);
    $to = rateCurrency('BBB', $toRate, $toDecimals);
    $convert = $from->converterTo($to);
    $amounts = [0, 1, -1, 2, 3, 5, 7, 49, 50, 51, 99, 100, 101, 12_345, -12_345, 999_999, 1_000_000_007, -1_000_000_007, 123_456_789_012, PHP_INT_MAX >> 20, PHP_INT_MAX >> 3, 9_000_000_000_000_000_000];
    mt_srand(205);

    for ($i = 0; $i < 300; $i++) {
        $amounts[] = mt_rand(-5_000_000, 5_000_000);
    }

    foreach ($amounts as $amount) {
        expect($convert($amount))->toBe(referenceConversion($amount, $from, $to), "{$amount} at {$fromRate} -> {$toRate}")
            ->and($from->convertTo($amount, $to))->toBe(referenceConversion($amount, $from, $to));
    }
})->with([
    'NPR from USD' => ['1.00000000', '133.45000000', 2, 2],
    'USD from NPR' => ['133.45000000', '1.00000000', 2, 2],
    'INR from NPR' => ['1.60000000', '83.12345678', 2, 2],
    'small rate' => ['0.00750000', '1.00000000', 2, 2],
    'zero-decimal target' => ['1', '157.5', 2, 0],
    'three-decimal source' => ['0.31', '1', 3, 2],
    'cross rate, divisor overflows' => ['25000.123456789', '1.123456', 2, 2],
    'cross rate, twice the divisor overflows' => ['5000.123456789', '1.123456', 2, 2],
    'cross rate from the currency form limits' => ['42105.26315', '0.0075187969', 2, 2],
    'cross rate with a zero-decimal source' => ['671802583.87', '0.66118443', 0, 3],
    'rates too long for integers' => ['1.12345678901234567890', '98765.43210987654321', 2, 2],
]);

test('random rate pairs, with neither side the default, never throw and match the decimal formula', function () {
    mt_srand(374);
    $rate = function (): string {
        $places = mt_rand(0, 12);
        $whole = (string) mt_rand(0, [1, 9, 999, 99_999, 999_999_999][mt_rand(0, 4)]);
        $value = $whole.($places === 0 ? '' : '.'.str_pad((string) mt_rand(0, 10 ** $places - 1), $places, '0', STR_PAD_LEFT));

        return Decimal::compare($value, '0') > 0 ? $value : '1';
    };

    for ($case = 0; $case < 500; $case++) {
        $from = rateCurrency('AAA', $rate(), [0, 2, 3][mt_rand(0, 2)]);
        $to = rateCurrency('BBB', $rate(), [0, 2, 3][mt_rand(0, 2)]);
        $convert = $from->converterTo($to);

        foreach ([0, 1, -1, 50, -149, 150, mt_rand(-10_000_000, 10_000_000), mt_rand(), -mt_rand(), PHP_INT_MAX >> 30] as $amount) {
            expect($convert($amount))->toBe(referenceConversion($amount, $from, $to), "{$amount} at {$from->exchange_rate}/{$from->decimals} -> {$to->exchange_rate}/{$to->decimals}");
        }
    }
});
