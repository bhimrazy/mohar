<?php

use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\Tax\TaxRates;

function rates(int $rate1, int $rate2 = 0, bool $compound = false, bool $inclusive = false, bool $perInvoice = false, string $rounding = 'half_up'): TaxRates
{
    return new TaxRates('L1', $rate1, $rate2 > 0 ? 'L2' : null, $rate2, $compound, $inclusive, $perInvoice, $rounding);
}

test('exclusive tax 20% on 10.00 is 2.00 with total 12.00', function () {
    $result = TaxMath::compute([['amount' => 1000, 'taxable' => true]], rates(20_000));

    expect($result)->subtotal->toBe(1000)->tax1->toBe(200)->total->toBe(1200);
});

test('inclusive tax 20% on 12.00 is net 10.00 and tax 2.00', function () {
    $result = TaxMath::compute([['amount' => 1200, 'taxable' => true]], rates(20_000, inclusive: true));

    expect($result)->subtotal->toBe(1000)->tax1->toBe(200)->total->toBe(1200);
});

test('compound L1 10% and L2 5% on 100.00 gives 10.00, 5.50 and 115.50', function () {
    $result = TaxMath::compute([['amount' => 10_000, 'taxable' => true]], rates(10_000, 5_000, compound: true));

    expect($result)->tax1->toBe(1000)->tax2->toBe(550)->total->toBe(11_550);
});

test('non-compound level 2 is charged on the net amount', function () {
    $result = TaxMath::compute([['amount' => 10_000, 'taxable' => true]], rates(10_000, 5_000));

    expect($result)->tax1->toBe(1000)->tax2->toBe(500)->total->toBe(11_500);
});

test('per line and per invoice rounding differ for 11 lines of 2.21 at 20%', function () {
    $lines = array_fill(0, 11, ['amount' => 221, 'taxable' => true]);

    $perLine = TaxMath::compute($lines, rates(20_000));
    $perInvoice = TaxMath::compute($lines, rates(20_000, perInvoice: true));

    expect($perLine->tax())->toBe(484)
        ->and($perInvoice->tax())->toBe(486)
        ->and(array_sum($perInvoice->lineTaxes))->toBe(486);
});

test('inclusive two-level taxes keep the gross amount', function (bool $compound) {
    $result = TaxMath::compute([['amount' => 999, 'taxable' => true]], rates(19_000, 7_000, compound: $compound, inclusive: true));

    expect($result->total)->toBe(999)
        ->and($result->subtotal + $result->tax1 + $result->tax2)->toBe(999);
})->with([true, false]);

test('inclusive compound net equals gross over the product of both rates', function () {
    [$net, $tax1, $tax2] = TaxMath::split(11_550, rates(10_000, 5_000, compound: true, inclusive: true));

    expect([$net, $tax1, $tax2])->toBe([10_000, 1000, 550]);
});

test('untaxed lines and discounts change the taxable base', function () {
    $result = TaxMath::compute([
        ['amount' => 10_000, 'taxable' => true],
        ['amount' => -2_000, 'taxable' => true],
        ['amount' => 500, 'taxable' => false],
    ], rates(20_000));

    expect($result)->tax1->toBe(1600)->subtotal->toBe(8_500)->total->toBe(10_100)
        ->and($result->lineTaxes)->toBe([2000, -400, 0]);
});

test('no rates means no tax', function () {
    $result = TaxMath::compute([['amount' => 1234, 'taxable' => true]], TaxRates::none());

    expect($result)->tax()->toBe(0)->total->toBe(1234);
});

test('rounding modes', function (int $numerator, string $mode, int $expected) {
    expect(TaxMath::roundDiv($numerator, 10, $mode))->toBe($expected);
})->with([
    [25, 'half_up', 3],
    [-25, 'half_up', -3],
    [25, 'half_even', 2],
    [35, 'half_even', 4],
    [29, 'down', 2],
    [21, 'up', 3],
    [24, 'half_up', 2],
]);

test('rates convert between percent strings and thousandths', function () {
    expect(TaxRates::toMilli('20.000'))->toBe(20_000)
        ->and(TaxRates::toMilli('17.125'))->toBe(17_125)
        ->and(TaxRates::toPercent(7_500))->toBe('7.500');
});
