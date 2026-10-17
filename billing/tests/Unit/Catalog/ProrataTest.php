<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Support\Prorata;
use Carbon\CarbonImmutable;

it('charges only the partial month when ordered before the charge-next-month day', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-01-10'), 3100, BillingCycle::Monthly, 1, 20);

    expect($result['partial'])->toBe(2200)
        ->and($result['first_amount'])->toBe(2200)
        ->and($result['next_due_date']->toDateString())->toBe('2026-02-01');
});

it('adds the next full month when ordered after the charge-next-month day', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-01-25'), 3100, BillingCycle::Monthly, 1, 20);

    expect($result['partial'])->toBe(700)
        ->and($result['first_amount'])->toBe(3800)
        ->and($result['next_due_date']->toDateString())->toBe('2026-03-01');
});

it('always adds the next month with 0 and never with 32', function (int $chargeNextMonthDay, int $expected) {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-01-25'), 3100, BillingCycle::Monthly, 1, $chargeNextMonthDay);

    expect($result['first_amount'])->toBe($expected);
})->with([
    'always' => [0, 3800],
    'never' => [32, 700],
]);

it('charges a full cycle when ordered on the prorata day', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-03-01 15:30'), 3100, BillingCycle::Monthly, 1, 20);

    expect($result['partial'])->toBe(0)
        ->and($result['first_amount'])->toBe(3100)
        ->and($result['next_due_date']->toDateString())->toBe('2026-04-01');
});

it('clamps the prorata day to short months', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-02-10'), 2800, BillingCycle::Monthly, 31, 32);

    expect($result['anchor']->toDateString())->toBe('2026-02-28')
        ->and($result['partial'])->toBe(2800 * 18 / 28)
        ->and($result['next_due_date']->toDateString())->toBe('2026-02-28');
});

it('anchors longer cycles to the prorata day of the next month and adds a full cycle', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-01-10'), 36500, BillingCycle::Annually, 1, 20);

    expect($result['anchor']->toDateString())->toBe('2026-02-01')
        ->and($result['partial'])->toBe(2200)
        ->and($result['first_amount'])->toBe(36500 + 2200)
        ->and($result['next_due_date']->toDateString())->toBe('2027-02-01');
});

it('does not prorate one-time cycles', function () {
    $result = Prorata::calculate(CarbonImmutable::parse('2026-01-10'), 5000, BillingCycle::OneTime, 1, 20);

    expect($result['first_amount'])->toBe(5000)->and($result['partial'])->toBe(0);
});
