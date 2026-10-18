<?php

use App\Data\Currencies\CurrencyDisplay;
use App\Enums\PriceRounding;
use App\Support\Currencies\MoneyFormatter;

test('amounts render with the currency symbol and its grouping', function (CurrencyDisplay $display, int $amount, string $expected) {
    expect(MoneyFormatter::render($amount, $display))->toBe($expected);
})->with([
    'USD' => [new CurrencyDisplay('USD', '$'), 123450, '$1,234.50'],
    'USD millions' => [new CurrencyDisplay('USD', '$'), 123456700, '$1,234,567.00'],
    'INR lakh' => [new CurrencyDisplay('INR', '₹', format: '1,23,456.78'), 12345600, '₹1,23,456.00'],
    'NPR crore' => [new CurrencyDisplay('NPR', 'Rs ', format: '1,23,456.78'), 1234567800, 'Rs 1,23,45,678.00'],
    'NPR small' => [new CurrencyDisplay('NPR', 'Rs ', format: '1,23,456.78'), 5, 'Rs 0.05'],
    'suffix and comma decimals' => [new CurrencyDisplay('EUR', '', ' €', format: '1.234,56'), 123456789, '1.234.567,89 €'],
    'space thousands' => [new CurrencyDisplay('SEK', '', ' kr', format: '1 234,56'), 123456789, '1 234 567,89 kr'],
    'no grouping' => [new CurrencyDisplay('CHF', 'CHF '), 0, 'CHF 0.00'],
]);

test('negatives put a true minus before the symbol; signed credits get a plus', function () {
    $npr = new CurrencyDisplay('NPR', 'Rs ', format: '1,23,456.78');

    expect(MoneyFormatter::render(-120000, $npr))->toBe('−Rs 1,200.00')
        ->and(MoneyFormatter::render(-1200, new CurrencyDisplay('USD', '$')))->toBe('−$12.00')
        ->and(MoneyFormatter::render(1200, new CurrencyDisplay('USD', '$'), signed: true))->toBe('+$12.00')
        ->and(MoneyFormatter::render(0, new CurrencyDisplay('USD', '$'), signed: true))->toBe('$0.00');
});

test('unconfigured currencies fall back to a narrow symbol, then the code', function () {
    expect(MoneyFormatter::render(150000, MoneyFormatter::fallback('gbp')))->toBe('£1,500.00')
        ->and(MoneyFormatter::render(12345600, MoneyFormatter::fallback('NPR')))->toBe('Rs 1,23,456.00')
        ->and(MoneyFormatter::render(100, MoneyFormatter::fallback('XYZ')))->toBe('XYZ 1.00');
});

test('price rounding rules', function (PriceRounding $rule, int $amount, int $expected) {
    expect($rule->apply($amount))->toBe($expected);
})->with([
    'none keeps cents' => [PriceRounding::None, 92907, 92907],
    'whole rounds half up' => [PriceRounding::Whole, 92950, 93000],
    'whole rounds down' => [PriceRounding::Whole, 92949, 92900],
    'whole never makes a price free' => [PriceRounding::Whole, 30, 100],
    'tens' => [PriceRounding::Tens, 123499, 123000],
    'tens up' => [PriceRounding::Tens, 123500, 124000],
    'tens minimum' => [PriceRounding::Tens, 400, 1000],
    '.99 nearest below' => [PriceRounding::NinetyNine, 92907, 92899],
    '.99 nearest above' => [PriceRounding::NinetyNine, 92960, 92999],
    '.99 from a whole price' => [PriceRounding::NinetyNine, 1000, 999],
    '.99 minimum' => [PriceRounding::NinetyNine, 20, 99],
    'free stays free' => [PriceRounding::NinetyNine, 0, 0],
]);
