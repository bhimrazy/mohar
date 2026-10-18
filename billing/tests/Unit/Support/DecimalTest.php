<?php

use App\Data\Currencies\RateSnapshot;
use App\Support\Currencies\Decimal;
use Carbon\CarbonImmutable;

test('division is exact long division rounded half up to the requested scale', function (string $a, string $b, int $scale, string $expected) {
    expect(Decimal::divide($a, $b, $scale))->toBe($expected);
})->with([
    ['1', '3', 6, '0.333333'],
    ['2', '3', 6, '0.666667'],
    ['106.8640', '1.1253', 6, '94.964898'],
    ['-1', '8', 2, '-0.13'],
    ['0.0049999', '1', 2, '0.00'],
    ['134.4', '0.8', 6, '168.000000'],
    ['1', '1250', 2, '0.00'],
    ['5', '1000', 2, '0.01'],
]);

test('add, subtract, multiply, compare and percent change stay exact', function () {
    expect(Decimal::add('0.1', '0.2'))->toBe('0.3')
        ->and(Decimal::subtract('152.25', '134.4'))->toBe('17.85')
        ->and(Decimal::multiply('160.075', '100'))->toBe('16007.500')
        ->and(Decimal::compare('1.10', '1.1'))->toBe(0)
        ->and(Decimal::compare('-2', '1'))->toBe(-1)
        ->and(Decimal::percentChange('100', '112'))->toBe('12.0000')
        ->and(Decimal::percentChange('134.4', '120.96'))->toBe('-10.0000')
        ->and(Decimal::round('+.5', 2))->toBe('0.50');
});

test('malformed numbers are rejected rather than guessed', function (string $value) {
    expect(Decimal::isValid($value))->toBeFalse();
    Decimal::round($value, 2);
})->with(['', 'abc', '1e3', '1,000.00', '1.2.3'])->throws(InvalidArgumentException::class);

test('cross rates go through the source base without inverting rates', function () {
    $ecb = new RateSnapshot('ecb', 'EUR', ['USD' => ['units' => '1.1253', 'base' => '1'], 'INR' => ['units' => '106.8640', 'base' => '1']], CarbonImmutable::now());
    $nrb = new RateSnapshot('nrb', 'NPR', ['USD' => ['units' => '1', 'base' => '152.25'], 'INR' => ['units' => '100', 'base' => '160.075']], CarbonImmutable::now());

    expect($ecb->crossRate('USD', 'INR'))->toBe('94.964898')
        ->and($ecb->crossRate('USD', 'EUR'))->toBe('0.888652')
        ->and($ecb->crossRate('USD', 'NPR'))->toBeNull()
        ->and($nrb->crossRate('USD', 'NPR'))->toBe('152.250000')
        ->and($nrb->crossRate('USD', 'INR'))->toBe('95.111666')
        ->and($nrb->crossRate('INR', 'NPR'))->toBe('1.600750');
});
