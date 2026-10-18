<?php

use App\Modules\PaymentsNepal\Support\NprAmount;

test('paisa become the rupee strings eSewa expects', function (int $paisa, string $rupees) {
    expect(NprAmount::toRupees($paisa))->toBe($rupees);
})->with([
    [113_000, '1130'],
    [113_050, '1130.50'],
    [113_005, '1130.05'],
    [1, '0.01'],
    [0, '0'],
]);

test('provider rupee amounts parse to exact paisa', function (mixed $rupees, ?int $paisa) {
    expect(NprAmount::toPaisa($rupees))->toBe($paisa);
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
