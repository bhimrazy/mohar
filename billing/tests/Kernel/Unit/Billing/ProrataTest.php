<?php

use App\Enums\BillingCycle;
use App\Support\Billing\Prorata;
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

/*
 * Pinned results for the day-count edge cases (docs/reviews/2026-11-01-prorata.md). Amounts are minor units; the
 * denominator is the length of the cycle that ends on the anchor, in calendar days.
 */
it('pins month ends, leap years and short-month clamping', function (string $orderDate, int $recurring, int $prorataDay, int $chargeNextMonthDay, string $anchor, int $partial, int $firstAmount, string $nextDue) {
    $result = Prorata::calculate(CarbonImmutable::parse($orderDate), $recurring, BillingCycle::Monthly, $prorataDay, $chargeNextMonthDay);

    expect([$result['anchor']->toDateString(), $result['partial'], $result['first_amount'], $result['next_due_date']->toDateString()])
        ->toBe([$anchor, $partial, $firstAmount, $nextDue]);
})->with([
    'last day of January, 1 of 31 days, plus February' => ['2026-01-31', 3100, 1, 20, '2026-02-01', 100, 3200, '2026-03-01'],
    'leap February: 20 of 29 days' => ['2028-02-10', 2900, 1, 20, '2028-03-01', 2000, 2000, '2028-03-01'],
    'common February: 19 of 28 days' => ['2027-02-10', 2900, 1, 20, '2027-03-01', 1968, 1968, '2027-03-01'],
    'day 31 clamps to April 30: 15 of 30 days' => ['2026-04-15', 3000, 31, 32, '2026-04-30', 1500, 1500, '2026-04-30'],
    'day 30 period starts on clamped February 28' => ['2026-03-01', 3000, 30, 32, '2026-03-30', 2900, 2900, '2026-03-30'],
    'ordered on day 31: no partial, next due clamps to February 28' => ['2026-01-31', 3100, 31, 20, '2026-01-31', 0, 3100, '2026-02-28'],
    'ordered on leap day 29: no partial' => ['2028-02-29', 2900, 29, 20, '2028-02-29', 0, 2900, '2028-03-29'],
    'ordered on the charge-next-month day itself: partial only' => ['2026-01-20', 3100, 1, 20, '2026-02-01', 1200, 1200, '2026-02-01'],
]);

it('rounds the partial half away from zero, also in a zero-decimal currency', function () {
    // 101 × 14/28 = 50.5 → 51; JPY 1000 × 22/31 = 709.68 → 710 (the minor unit is the whole yen).
    expect(Prorata::calculate(CarbonImmutable::parse('2026-02-15'), 101, BillingCycle::Monthly, 1, 32)['partial'])->toBe(51)
        ->and(Prorata::calculate(CarbonImmutable::parse('2026-01-10'), 1000, BillingCycle::Monthly, 1, 20)['first_amount'])->toBe(710);
});

it('divides a longer cycle by that cycle\'s length, not by one month', function (BillingCycle $cycle, string $orderDate, int $recurring, int $partial, int $firstAmount, string $nextDue) {
    $result = Prorata::calculate(CarbonImmutable::parse($orderDate), $recurring, $cycle, 1, 20);

    expect([$result['partial'], $result['first_amount'], $result['next_due_date']->toDateString()])->toBe([$partial, $firstAmount, $nextDue]);
})->with([
    'quarterly: 9000 × 22/92' => [BillingCycle::Quarterly, '2026-01-10', 9000, 2152, 11152, '2026-05-01'],
    'annual over a leap year: 36600 × 22/366' => [BillingCycle::Annually, '2028-01-10', 36600, 2206, 38806, '2029-02-01'],
    'annual across the year end: 36500 × 12/365' => [BillingCycle::Annually, '2026-12-20', 36500, 1200, 37700, '2028-01-01'],
]);

it('reads the order date in the time zone of the date it is given', function () {
    $nepalMidnight = CarbonImmutable::parse('2026-01-10 00:00', 'Asia/Kathmandu');

    // The company clock's today() (00:00 Nepal time) and its todayDate() (00:00 UTC) give the same answer...
    expect(Prorata::calculate($nepalMidnight, 3100, BillingCycle::Monthly, 1, 20)['first_amount'])->toBe(2200)
        ->and(Prorata::calculate(CarbonImmutable::parse('2026-01-10 23:59', 'Asia/Kathmandu'), 3100, BillingCycle::Monthly, 1, 20)['first_amount'])->toBe(2200)
        ->and(Prorata::calculate(CarbonImmutable::parse('2026-01-10'), 3100, BillingCycle::Monthly, 1, 20)['first_amount'])->toBe(2200)
        // ...but the same instant converted to UTC is still 9 January, one more day to charge.
        ->and(Prorata::calculate($nepalMidnight->utc(), 3100, BillingCycle::Monthly, 1, 20)['first_amount'])->toBe(2300);
});

it('counts calendar days across a daylight-saving change', function () {
    // New York springs forward on 8 March 2026: 27 of 31 days either way.
    expect(Prorata::calculate(CarbonImmutable::parse('2026-03-05', 'America/New_York'), 3100, BillingCycle::Monthly, 1, 32)['partial'])->toBe(2700)
        ->and(Prorata::calculate(CarbonImmutable::parse('2026-03-05'), 3100, BillingCycle::Monthly, 1, 32)['partial'])->toBe(2700);
});
