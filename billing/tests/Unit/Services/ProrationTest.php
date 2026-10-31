<?php

use App\Enums\BillingCycle;
use App\Modules\Services\Support\Proration;
use App\Support\Billing\Prorata;
use Carbon\CarbonImmutable;

function prorate(int $old, int $new, string $start, string $end, string $today): Proration
{
    return Proration::calculate($old, $new, CarbonImmutable::parse($start), CarbonImmutable::parse($end), CarbonImmutable::parse($today));
}

test('half of a 30 day period: credit 5.00, charge 10.00, due 5.00', function () {
    $proration = prorate(1000, 2000, '2026-11-01', '2026-12-01', '2026-11-16');

    expect($proration)->cycleDays->toBe(30)->remainingDays->toBe(15)->credit->toBe(500)->charge->toBe(1000)
        ->and($proration->amountDue())->toBe(500);
});

test('a downgrade yields a negative amount due', function () {
    expect(prorate(1000, 200, '2026-11-01', '2026-12-01', '2026-11-16')->amountDue())->toBe(-400);
});

test('each product is rounded half up', function () {
    // 1000 * 1 / 3 = 333.33 → 333 ; 1999 * 1 / 3 = 666.33 → 666 ; 5 * 1 / 2 = 2.5 → 3
    $thirds = prorate(1000, 1999, '2026-11-01', '2026-11-04', '2026-11-03');
    $half = prorate(5, 5, '2026-11-01', '2026-11-03', '2026-11-02');

    expect([$thirds->credit, $thirds->charge])->toBe([333, 666])
        ->and($half->credit)->toBe(3);
});

test('on the last day nothing remains and before the period everything does', function () {
    expect(prorate(1000, 2000, '2026-11-01', '2026-12-01', '2026-12-01')->amountDue())->toBe(0)
        ->and(prorate(1000, 2000, '2026-11-01', '2026-12-01', '2026-12-05')->remainingDays)->toBe(0)
        ->and(prorate(1000, 2000, '2026-11-01', '2026-12-01', '2026-11-01')->amountDue())->toBe(1000);
});

test('february uses its real length', function () {
    $proration = prorate(2800, 5600, '2027-02-01', '2027-03-01', '2027-02-15');

    expect($proration->cycleDays)->toBe(28)->and($proration->credit)->toBe(1400)->and($proration->charge)->toBe(2800);
});

test('zero amounts prorate to zero', function () {
    expect(prorate(0, 0, '2026-11-01', '2026-12-01', '2026-11-16')->amountDue())->toBe(0);
});

test('full charge has no proration on the new side', function () {
    $proration = Proration::fullCharge(10000, 500);

    expect($proration->amountDue())->toBe(9500);
});

test('half-up division handles negatives symmetrically', function () {
    expect(Proration::divideHalfUp(-5, 2))->toBe(-3)
        ->and(Proration::divideHalfUp(5, 2))->toBe(3)
        ->and(Proration::divideHalfUp(4, 3))->toBe(1);
});

/*
 * Pinned results for the day-count edge cases (docs/reviews/2026-11-01-prorata.md). Callers pass company dates as
 * date values (00:00 UTC, CompanyClock::todayDate() and the date casts).
 */
test('pins leap years, clamped anchors and zero-decimal amounts', function (array $args, array $expected) {
    $proration = prorate(...$args);

    expect([$proration->cycleDays, $proration->remainingDays, $proration->credit, $proration->charge])->toBe($expected);
})->with([
    'leap February: 15 of 29 days' => [[2900, 5800, '2028-02-01', '2028-03-01', '2028-02-15'], [29, 15, 1500, 3000]],
    'anchor 31 clamped to February 28: 14 of 28 days' => [[2800, 5600, '2027-01-31', '2027-02-28', '2027-02-14'], [28, 14, 1400, 2800]],
    'annual over a leap year: 184 of 366 days' => [[36600, 73200, '2028-01-01', '2029-01-01', '2028-07-01'], [366, 184, 18400, 36800]],
    'JPY: 1000 × 22/31 and 3000 × 22/31' => [[1000, 3000, '2026-01-01', '2026-02-01', '2026-01-10'], [31, 22, 710, 2129]],
]);

test('counts the same days whether the dates are UTC date values or Nepal-time midnights', function () {
    $utc = prorate(3100, 6200, '2026-03-01', '2026-04-01', '2026-03-05');
    $nepal = Proration::calculate(3100, 6200, CarbonImmutable::parse('2026-03-01', 'Asia/Kathmandu'), CarbonImmutable::parse('2026-04-01', 'Asia/Kathmandu'), CarbonImmutable::parse('2026-03-05', 'Asia/Kathmandu'));

    expect([$utc->remainingDays, $utc->credit, $utc->charge])->toBe([27, 2700, 5400])
        ->and([$nepal->remainingDays, $nepal->credit, $nepal->charge])->toBe([27, 2700, 5400]);
});

test('the change-plan charge for the days to the anchor equals the first-period partial', function () {
    // Same day ratio and rounding: Prorata's partial is Proration's charge over the cycle that ends on the anchor.
    $mismatches = [];

    foreach ([BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Annually] as $cycle) {
        foreach ([1, 15, 28, 29, 30, 31] as $day) {
            for ($date = CarbonImmutable::parse('2027-12-01'); $date->lt('2029-01-01'); $date = $date->addDay()) {
                $prorata = Prorata::calculate($date, 3333, $cycle, $day, 32);
                $anchor = $prorata['anchor'];
                $charge = Proration::calculate(0, 3333, $cycle->advance($anchor, -1, $day), $anchor, $date)->charge;

                if ($prorata['partial'] !== $charge) {
                    $mismatches[] = "{$cycle->value} day {$day} {$date->toDateString()}: {$prorata['partial']} vs {$charge}";
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

test('the first-period and change-plan half-up divisions agree', function () {
    $prorata = new ReflectionMethod(Prorata::class, 'divideRounded');

    for ($numerator = -300; $numerator <= 300; $numerator++) {
        for ($denominator = 1; $denominator <= 31; $denominator++) {
            $expected = Proration::divideHalfUp($numerator, $denominator);

            if ($prorata->invoke(null, $numerator, $denominator) !== $expected) {
                throw new RuntimeException("{$numerator}/{$denominator} differs");
            }
        }
    }

    expect(Proration::divideHalfUp(-7, 2))->toBe(-4)->and(Proration::divideHalfUp(7, 2))->toBe(4);
});
