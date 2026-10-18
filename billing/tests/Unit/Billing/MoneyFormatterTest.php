<?php

use App\Support\Currencies\MoneyFormatter;

test('Indian grouping uses lakh and crore separators', function (string $whole, string $expected) {
    expect(MoneyFormatter::indianGrouping($whole))->toBe($expected);
})->with([
    ['0', '0'],
    ['999', '999'],
    ['1000', '1,000'],
    ['123456', '1,23,456'],
    ['12345678', '1,23,45,678'],
]);
