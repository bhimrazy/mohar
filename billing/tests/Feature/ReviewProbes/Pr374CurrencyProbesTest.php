<?php

use App\Modules\Core\Models\Currency;
use App\Support\Currencies\Decimal;

/** Review probes for PR #374: Currency::converterTo(). */
function pr374Reference(int $amount, Currency $from, Currency $to): int
{
    $shift = $to->decimals - $from->decimals;

    return (int) Decimal::divide(
        Decimal::multiply(Decimal::multiply((string) $amount, (string) $to->exchange_rate), '1'.str_repeat('0', max(0, $shift))),
        Decimal::multiply((string) $from->exchange_rate, '1'.str_repeat('0', max(0, -$shift))),
        0,
    );
}

function pr374Currency(string $rate, int $decimals = 2): Currency
{
    return (new Currency)->forceFill(['code' => 'X'.random_int(10, 99), 'exchange_rate' => $rate, 'decimals' => $decimals]);
}

// divisor = d × 10^a overflows PHP_INT_MAX: converterTo() throws TypeError from intdiv() at construction.
test('cross-rate conversion with a large source rate and a 6-decimal target rate does not crash', function () {
    $from = pr374Currency('25000.123456789');
    $to = pr374Currency('1.123456');

    expect($from->convertTo(10_000, $to))->toBe(pr374Reference(10_000, $from, $to));
});

// divisor in (PHP_INT_MAX/2, PHP_INT_MAX]: 2 × divisor overflows inside the closure, for every amount.
test('cross-rate conversion where twice the divisor overflows does not crash', function () {
    $from = pr374Currency('5000.123456789');
    $to = pr374Currency('1.123456');

    expect($from->convertTo(0, $to))->toBe(0)
        ->and($from->convertTo(100, $to))->toBe(pr374Reference(100, $from, $to));
});

// Within the manual-entry rules (gt:0, max:1e9, decimal:0,12) and 12 significant digits.
test('rates allowed by the currency form never crash a cross-rate conversion', function () {
    $from = pr374Currency('42105.26315');
    $to = pr374Currency('0.0075187969');

    expect($from->convertTo(12_345, $to))->toBe(pr374Reference(12_345, $from, $to));
});

test('fuzz: converter equals the decimal formula for random rates, decimals and amounts', function () {
    mt_srand(374);
    $mismatches = [];
    $errors = [];

    for ($case = 0; $case < 400; $case++) {
        $rate = function (): string {
            $whole = (string) mt_rand(0, [1, 9, 999, 99_999, 999_999_999][mt_rand(0, 4)]);
            $places = mt_rand(0, 12);
            $fraction = $places === 0 ? '' : '.'.str_pad((string) mt_rand(0, 10 ** $places - 1), $places, '0', STR_PAD_LEFT);
            $value = $whole.$fraction;

            return Decimal::compare($value, '0') > 0 ? Decimal::round($value, 12) : '1.000000000000';
        };
        $from = pr374Currency($rate(), [0, 2, 3][mt_rand(0, 2)]);
        // Mostly the production shape (one side is the default at rate 1), sometimes a cross rate.
        $to = mt_rand(0, 2) === 0 ? pr374Currency($rate(), [0, 2, 3][mt_rand(0, 2)]) : pr374Currency('1.000000000000', 2);

        try {
            $convert = $from->converterTo($to);
        } catch (Throwable $e) {
            $errors[] = "{$from->exchange_rate}/{$from->decimals} -> {$to->exchange_rate}/{$to->decimals}: ".$e::class;

            continue;
        }

        foreach ([0, 1, -1, 50, -50, 149, 150, -150, mt_rand(-10_000_000, 10_000_000), mt_rand(), -mt_rand(), PHP_INT_MAX >> 30, PHP_INT_MAX >> 12] as $amount) {
            try {
                $got = $convert($amount);
            } catch (Throwable $e) {
                $errors[] = "{$from->exchange_rate} -> {$to->exchange_rate} amount {$amount}: ".$e::class;

                continue;
            }

            try {
                $want = pr374Reference($amount, $from, $to);
            } catch (Throwable) {
                continue;
            }

            if ($got !== $want) {
                $mismatches[] = "{$amount} @ {$from->exchange_rate}/{$from->decimals} -> {$to->exchange_rate}/{$to->decimals}: {$got} != {$want}";
            }
        }
    }

    expect($mismatches)->toBe([])->and(array_slice($errors, 0, 5))->toBe([]);
});

test('production shapes (to and from a default at rate 1) at the integer limit boundary match exactly', function () {
    $default = pr374Currency('1.000000000000');

    foreach (['999999999.999999', '0.000000000001', '134.400000000000', '0.007440476190', '1000000000'] as $r) {
        $other = pr374Currency($r);

        foreach ([[$other, $default], [$default, $other]] as [$from, $to]) {
            $convert = $from->converterTo($to);

            foreach ([PHP_INT_MAX >> 1, PHP_INT_MAX, PHP_INT_MIN, -PHP_INT_MAX, 4_611_686_018_427, 4_611_686_018_428, 9_223_372, 922_337_203_685_477] as $amount) {
                try {
                    $want = pr374Reference($amount, $from, $to);
                } catch (Throwable) {
                    continue;
                }

                // (int) of an out-of-range string saturates; skip amounts whose true result does not fit.
                if ($want === PHP_INT_MAX || $want === PHP_INT_MIN) {
                    continue;
                }

                expect($convert($amount))->toBe($want, "{$amount} {$from->exchange_rate} -> {$to->exchange_rate}");
            }
        }
    }
});
