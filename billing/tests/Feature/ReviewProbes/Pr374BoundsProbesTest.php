<?php

use App\Modules\Core\Models\Currency;
use App\Support\Currencies\Decimal;

/** Re-check probes for PR #374: try to break the integer-path bounds of Currency::converterTo(). */
function pr374bExact(int $amount, Currency $from, Currency $to): ?int
{
    $shift = $to->decimals - $from->decimals;
    $value = Decimal::divide(
        Decimal::multiply(Decimal::multiply((string) $amount, (string) $to->exchange_rate), '1'.str_repeat('0', max(0, $shift))),
        Decimal::multiply((string) $from->exchange_rate, '1'.str_repeat('0', max(0, -$shift))),
        0,
    );

    // A true result outside int range is not comparable ((int) of the string saturates).
    if (Decimal::compare($value, (string) PHP_INT_MAX) > 0 || Decimal::compare($value, (string) PHP_INT_MIN) < 0) {
        return null;
    }

    return (int) $value;
}

function pr374bCurrency(string $rate, int $decimals = 2): Currency
{
    return (new Currency)->forceFill(['code' => 'Q'.random_int(10, 99), 'exchange_rate' => $rate, 'decimals' => $decimals]);
}

/** @return array{0: int|null, 1: int|null, 2: int|null} factor, divisor, limit of the integer path (nulls = exact path) */
function pr374bInternals(Closure $convert): array
{
    $vars = (new ReflectionFunction($convert))->getStaticVariables();

    return [$vars['factor'] ?? null, $vars['divisor'] ?? null, $vars['limit'] ?? null];
}

dataset('edge pairs', [
    // factor just under PHP_INT_MAX / 4, divisor 1: limit is 1 or 2.
    'factor ~ MAX/4' => ['0.0000000001', '230584300', 2, 2],
    // divisor just under PHP_INT_MAX / 4, factor 1: limit ~ 3.4e18.
    'divisor ~ MAX/4' => ['230584300', '0.0000000001', 2, 2],
    // factor exactly over MAX/4 by one digit: must fall back.
    'factor just over' => ['0.0000000001', '230584301', 2, 2],
    'divisor just over' => ['230584301', '0.0000000001', 2, 2],
    // both mid-size, with a decimals shift both ways.
    'both mid, shift +1' => ['1.00001', '23058430.09', 2, 3],
    'both mid, shift -2' => ['23058430.09', '1.00001', 2, 0],
    'odd divisor, halves' => ['3', '1', 0, 0],
    'seven divisor' => ['7', '1', 2, 2],
]);

test('amounts around the integer-path limit match the decimal formula exactly', function (string $fromRate, string $toRate, int $fromDecimals, int $toDecimals) {
    $from = pr374bCurrency($fromRate, $fromDecimals);
    $to = pr374bCurrency($toRate, $toDecimals);
    $convert = $from->converterTo($to);
    [$factor, $divisor, $limit] = pr374bInternals($convert);

    if ($limit !== null) {
        expect($factor)->toBeInt()->toBeLessThanOrEqual(intdiv(PHP_INT_MAX, 4))
            ->and($divisor)->toBeInt()->toBeLessThanOrEqual(intdiv(PHP_INT_MAX, 4))
            // the largest numerator the closure builds still fits
            ->and(2 * $limit * $factor + $divisor)->toBeInt();
    }

    $amounts = [0, 1, -1, PHP_INT_MAX, -PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX >> 1, PHP_INT_MAX >> 2];

    if ($limit !== null) {
        foreach ([-2, -1, 0, 1, 2] as $delta) {
            $amounts[] = $limit + $delta;
            $amounts[] = -($limit + $delta);
        }
    }

    foreach ($amounts as $amount) {
        if (! is_int($amount)) {
            continue;
        }

        $want = pr374bExact($amount, $from, $to);

        if ($want !== null) {
            expect($convert($amount))->toBe($want, "{$amount} ({$fromRate} -> {$toRate}) limit=".var_export($limit, true));
        }
    }
})->with('edge pairs');

test('wide fuzz: 3000 pairs with up to 15 significant digits, amounts across the whole int range', function () {
    mt_srand(3745);
    $rate = function (): string {
        $wholeDigits = mt_rand(0, 9);
        $places = mt_rand(0, 15 - min(15, $wholeDigits));
        $whole = $wholeDigits === 0 ? '0' : (string) mt_rand(10 ** ($wholeDigits - 1), 10 ** $wholeDigits - 1);
        $fraction = $places === 0 ? '' : '.'.str_pad((string) mt_rand(0, min(PHP_INT_MAX, 10 ** $places - 1)), $places, '0', STR_PAD_LEFT);

        return Decimal::compare($whole.$fraction, '0') > 0 ? $whole.$fraction : '1';
    };
    $bad = [];
    $fast = 0;

    for ($case = 0; $case < 3000; $case++) {
        $from = pr374bCurrency($rate(), [0, 2, 3][mt_rand(0, 2)]);
        $to = pr374bCurrency($rate(), [0, 2, 3][mt_rand(0, 2)]);

        try {
            $convert = $from->converterTo($to);
        } catch (Throwable $e) {
            $bad[] = "build {$from->exchange_rate} -> {$to->exchange_rate}: ".$e::class;

            continue;
        }

        [, , $limit] = pr374bInternals($convert);
        $fast += $limit !== null ? 1 : 0;
        $amounts = [0, 1, -1, 50, -150, mt_rand(), -mt_rand(), mt_rand(0, PHP_INT_MAX), -mt_rand(0, PHP_INT_MAX), PHP_INT_MAX, PHP_INT_MIN];

        if (is_int($limit)) {
            array_push($amounts, $limit, $limit + 1, -$limit, -$limit - 1);
        }

        foreach ($amounts as $amount) {
            try {
                $got = $convert($amount);
            } catch (Throwable $e) {
                $bad[] = "{$amount} {$from->exchange_rate} -> {$to->exchange_rate}: ".$e::class.' '.$e->getMessage();

                continue;
            }

            $want = pr374bExact($amount, $from, $to);

            if ($want !== null && $got !== $want) {
                $bad[] = "{$amount} {$from->exchange_rate}/{$from->decimals} -> {$to->exchange_rate}/{$to->decimals}: {$got} != {$want}";
            }
        }
    }

    // About two thirds of the pairs take the integer path, so the fuzz exercises it as well as the fallback.
    expect($fast)->toBeGreaterThan(1000);
    expect(array_slice($bad, 0, 10))->toBe([]);
});
