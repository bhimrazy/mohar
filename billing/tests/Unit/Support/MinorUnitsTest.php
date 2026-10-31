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
