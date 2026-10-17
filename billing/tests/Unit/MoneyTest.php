<?php

use App\Enums\BillingCycle;
use App\Support\Money;
use Carbon\CarbonImmutable;

test('money arithmetic stays in minor units', function () {
    $price = Money::of(1999, 'usd');

    expect($price->currency)->toBe('USD')
        ->and($price->add(Money::of(1, 'USD'))->amount)->toBe(2000)
        ->and($price->percent(20)->amount)->toBe(400)
        ->and(Money::fromDecimal('12.345', 'USD')->amount)->toBe(1235)
        ->and(Money::of(-150, 'USD')->toDecimal())->toBe('-1.50');
});

test('money refuses mixed currencies', function () {
    Money::of(100, 'USD')->add(Money::of(100, 'EUR'));
})->throws(InvalidArgumentException::class);

test('allocation never loses a cent', function () {
    $parts = Money::of(1000, 'USD')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amount, $parts))->toBe([334, 333, 333]);
});

test('billing cycles advance without month overflow', function () {
    $jan31 = CarbonImmutable::parse('2026-01-31');

    expect(BillingCycle::Monthly->advance($jan31)->toDateString())->toBe('2026-02-28')
        ->and(BillingCycle::Annually->advance($jan31)->toDateString())->toBe('2027-01-31')
        ->and(BillingCycle::OneTime->isRecurring())->toBeFalse();
});
