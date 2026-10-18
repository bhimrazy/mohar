<?php

use App\Modules\Services\Support\Proration;
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
