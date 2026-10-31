<?php

use App\Support\MinorUnits;

test('paisa become the rupee strings eSewa expects', function (int $paisa, string $rupees) {
    expect(MinorUnits::toDecimal($paisa, 2))->toBe($rupees);
})->with([
    [113_000, '1130'],
    [113_050, '1130.50'],
    [113_005, '1130.05'],
    [1, '0.01'],
    [0, '0'],
]);

test('provider rupee amounts parse to exact paisa', function (mixed $rupees, ?int $paisa) {
    expect(MinorUnits::fromDecimal($rupees, 2))->toBe($paisa);
})->with([
    'thousands separator' => ['1,130.0', 113_000],
    'two decimals' => ['1130.50', 113_050],
    'one decimal' => ['1130.5', 113_050],
    'whole string' => ['100', 10_000],
    'json integer' => [100, 10_000],
    'json float' => [1130.5, 113_050],
    'json float with float noise' => [0.1 + 0.2, 30],
    'trailing zeros' => ['10.500', 1_050],
    'sub-paisa is rejected' => ['10.505', null],
    'sub-paisa float is rejected' => [10.005, null],
    'negative is rejected' => ['-1', null],
    'text is rejected' => ['abc', null],
    'null is rejected' => [null, null],
]);

test('other decimal counts format and parse exactly', function () {
    expect(MinorUnits::toDecimal(1500, 0))->toBe('1500')
        ->and(MinorUnits::toDecimal(1_234_005, 3))->toBe('1234.005')
        ->and(MinorUnits::fromDecimal('1500', 0))->toBe(1500)
        ->and(MinorUnits::fromDecimal('1500.00', 0))->toBe(1500)
        ->and(MinorUnits::fromDecimal('1500.5', 0))->toBeNull()
        ->and(MinorUnits::fromDecimal('1.005', 3))->toBe(1005)
        ->and(fn () => MinorUnits::toDecimal(-1, 2))->toThrow(InvalidArgumentException::class);
});

test('rescaling between decimal counts never rounds', function () {
    expect(MinorUnits::rescale(1234, 2, 2))->toBe(1234)
        ->and(MinorUnits::rescale(1234, 0, 2))->toBe(123_400)
        ->and(MinorUnits::rescale(123_400, 2, 0))->toBe(1234)
        ->and(MinorUnits::rescale(123_450, 2, 0))->toBeNull()
        ->and(MinorUnits::rescale(PHP_INT_MAX, 0, 2))->toBeNull();
});

test('an amount that overflows is refused, not returned as a float', function () {
    // rescale() promises null on overflow; a negative amount scaled up overflows below PHP_INT_MIN.
    expect(MinorUnits::rescale(PHP_INT_MIN, 0, 2))->toBeNull();
});

test('a provider amount too large for its decimals is refused, not a TypeError', function () {
    // fromDecimal() accepts 13 integer digits; with 6 decimals that passes PHP_INT_MAX.
    expect(MinorUnits::fromDecimal('9999999999999', 6))->toBeNull()
        ->and(MinorUnits::fromDecimal('9999999999999.999999', 6))->toBeNull();
});

test('minor units match the converters they replaced, for NPR and Stripe amounts', function () {
    // NprAmount::toPaisa/toRupees (removed in #590) were fixed at 2 decimals; StripeAmount::shift did 10 ** power.
    foreach ([0, 1, 99, 100, 101, 113_050, 999_999_999_99] as $paisa) {
        expect(MinorUnits::fromDecimal(MinorUnits::toDecimal($paisa, 2), 2))->toBe($paisa)
            ->and(MinorUnits::rescale($paisa, 2, 2))->toBe($paisa);
    }

    expect(MinorUnits::rescale(150_000, 2, 0))->toBe(1500)
        ->and(MinorUnits::rescale(150_050, 2, 0))->toBeNull()
        ->and(MinorUnits::rescale(1500, 0, 2))->toBe(150_000);
});

test('a negative amount or decimals beyond an integer are refused', function () {
    expect(MinorUnits::rescale(-5, 0, 2))->toBeNull()
        ->and(MinorUnits::rescale(-500, 2, 0))->toBeNull()
        ->and(MinorUnits::rescale(1, 0, 19))->toBeNull()
        ->and(MinorUnits::fromDecimal('1', 19))->toBeNull()
        ->and(MinorUnits::fromDecimal('1', MinorUnits::MAX_DECIMALS))->toBe(10 ** 18)
        ->and(fn () => MinorUnits::toDecimal(1, 19))->toThrow(InvalidArgumentException::class);
});

test('minor units hold at the integer and decimal edges', function () {
    expect(MinorUnits::rescale(PHP_INT_MAX, 2, 2))->toBe(PHP_INT_MAX)
        ->and(MinorUnits::rescale(PHP_INT_MAX, 0, 1))->toBeNull()
        ->and(MinorUnits::rescale(intdiv(PHP_INT_MAX, 10), 0, 1))->toBe(intdiv(PHP_INT_MAX, 10) * 10)
        ->and(MinorUnits::rescale(PHP_INT_MIN, 0, 2))->toBeNull()
        ->and(MinorUnits::rescale(PHP_INT_MIN, 2, 0))->toBeNull()
        ->and(MinorUnits::rescale(9, 0, 18))->toBe(9 * 10 ** 18)
        ->and(MinorUnits::rescale(10, 0, 18))->toBeNull()
        ->and(MinorUnits::rescale(10 ** 18, 18, 0))->toBe(1)
        ->and(MinorUnits::rescale(0, 0, 0))->toBe(0)
        ->and(MinorUnits::toDecimal(PHP_INT_MAX, 18))->toBe('9.223372036854775807')
        ->and(MinorUnits::toDecimal(PHP_INT_MAX, 0))->toBe((string) PHP_INT_MAX)
        ->and(MinorUnits::toDecimal(1500, 0))->toBe('1500')
        ->and(MinorUnits::fromDecimal(PHP_INT_MAX, 0))->toBe(PHP_INT_MAX)
        ->and(MinorUnits::fromDecimal(PHP_INT_MAX, 1))->toBeNull()
        ->and(MinorUnits::fromDecimal('1500.0', 0))->toBe(1500)
        ->and(MinorUnits::fromDecimal('1500.5', 0))->toBeNull()
        ->and(MinorUnits::fromDecimal(1500.0, 0))->toBe(1500)
        ->and(MinorUnits::fromDecimal('8.5', 18))->toBe(8_500_000_000_000_000_000)
        ->and(MinorUnits::fromDecimal('9999999999999', 6))->toBeNull()
        ->and(MinorUnits::fromDecimal('9223372036854.999999', 6))->toBeNull()
        ->and(MinorUnits::fromDecimal('9.5', 18))->toBeNull()
        ->and(MinorUnits::fromDecimal('9223372036853', 6))->toBe(9_223_372_036_853_000_000)
        ->and(MinorUnits::fromDecimal('1', -1))->toBeNull()
        ->and(fn () => MinorUnits::toDecimal(1, -1))->toThrow(InvalidArgumentException::class);
});
