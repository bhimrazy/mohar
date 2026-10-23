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
    'rates too long for integers' => ['1.12345678901234567890', '98765.43210987654321', 2, 2],
]);
