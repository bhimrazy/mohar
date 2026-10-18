<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Support\PriceCalculator;

it('sums the base price and option lines', function () {
    $quote = PriceCalculator::quote('eur', BillingCycle::Monthly, [
        ['label' => 'VPS', 'recurring' => 1000, 'setup' => 500],
        ['label' => 'RAM: 4 GB', 'recurring' => 500, 'setup' => 0],
    ]);

    expect($quote)
        ->currency->toBe('EUR')
        ->cycle->toBe(BillingCycle::Monthly)
        ->recurring->toBe(1500)
        ->setup->toBe(500)
        ->and($quote->firstPayment())->toBe(2000)
        ->and($quote->breakdown)->toHaveCount(2);
});

it('applies a client group discount to recurring and setup after options', function () {
    $quote = PriceCalculator::quote('USD', BillingCycle::Monthly, [
        ['label' => 'Product', 'recurring' => 1000, 'setup' => 1000],
        ['label' => 'Option', 'recurring' => 500, 'setup' => 0],
    ], 10);

    expect($quote->recurring)->toBe(1350)
        ->and($quote->setup)->toBe(900)
        ->and($quote->breakdown[2])->toBe(['label' => 'Client discount (10%)', 'recurring' => -150, 'setup' => -100])
        ->and(array_sum(array_column($quote->breakdown, 'recurring')))->toBe(1350);
});

it('rounds the discount half-up per line', function () {
    // 12.5% of 333 = 41.625 → 42; of 5 = 0.625 → 1 (per line, not on the total of 338 → 42.25 → 42).
    $quote = PriceCalculator::quote('USD', BillingCycle::Monthly, [
        ['label' => 'A', 'recurring' => 333, 'setup' => 0],
        ['label' => 'B', 'recurring' => 5, 'setup' => 0],
    ], '12.50');

    expect($quote->recurring)->toBe(338 - 43);
});

it('adds no discount line for a zero discount or zero amounts', function (float $discount, int $amount) {
    $quote = PriceCalculator::quote('USD', BillingCycle::Annually, [['label' => 'A', 'recurring' => $amount, 'setup' => 0]], $discount);

    expect($quote->breakdown)->toHaveCount(1)->and($quote->recurring)->toBe($amount);
})->with([
    'no discount' => [0, 1000],
    'free line' => [25, 0],
]);

it('rejects discounts outside 0–100%', function (float $discount) {
    PriceCalculator::quote('USD', BillingCycle::Monthly, [['label' => 'A', 'recurring' => 100, 'setup' => 0]], $discount);
})->with([-1, 100.01])->throws(InvalidArgumentException::class);

it('applies a 100% discount down to zero', function () {
    $quote = PriceCalculator::quote('USD', BillingCycle::Monthly, [['label' => 'A', 'recurring' => 999, 'setup' => 1]], 100);

    expect($quote->recurring)->toBe(0)->and($quote->setup)->toBe(0);
});

it('multiplies quantity option unit prices by the quantity', function () {
    expect(PriceCalculator::quantityCharge(200, 50, 3))->toBe(['recurring' => 600, 'setup' => 150])
        ->and(PriceCalculator::quantityCharge(200, 50, 0))->toBe(['recurring' => 0, 'setup' => 0]);
});

it('rejects a negative quantity', function () {
    PriceCalculator::quantityCharge(200, 0, -1);
})->throws(InvalidArgumentException::class);

it('validates quantities against min, max and step', function (int $quantity, ?int $min, ?int $max, int $step, bool $valid) {
    expect(PriceCalculator::isValidQuantity($quantity, $min, $max, $step))->toBe($valid);
})->with([
    'within range' => [3, 1, 10, 1, true],
    'below min' => [0, 1, 10, 1, false],
    'above max' => [11, 1, 10, 1, false],
    'unbounded' => [500, null, null, 1, true],
    'on step from min' => [5, 1, 9, 2, true],
    'off step' => [4, 1, 9, 2, false],
    'negative' => [-1, null, null, 1, false],
]);

it('computes the monthly equivalent of longer cycles rounded half-up', function () {
    expect(PriceCalculator::monthlyEquivalent(9900, BillingCycle::Annually))->toBe(825)
        ->and(PriceCalculator::monthlyEquivalent(1000, BillingCycle::Quarterly))->toBe(333)
        ->and(PriceCalculator::monthlyEquivalent(1001, BillingCycle::SemiAnnually))->toBe(167)
        ->and(PriceCalculator::monthlyEquivalent(1000, BillingCycle::OneTime))->toBeNull();
});

it('divides with half-up rounding for negatives too', function () {
    expect(PriceCalculator::divideRounded(5, 2))->toBe(3)
        ->and(PriceCalculator::divideRounded(-5, 2))->toBe(-3)
        ->and(PriceCalculator::divideRounded(4, 3))->toBe(1);
});
