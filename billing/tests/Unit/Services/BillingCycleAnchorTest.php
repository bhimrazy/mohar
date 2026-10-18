<?php

use App\Enums\BillingCycle;
use App\Modules\Services\Support\BillingDates;
use Carbon\CarbonImmutable;

test('monthly anchor 31 does not drift across short months', function () {
    $date = CarbonImmutable::parse('2027-01-31');
    $dates = [];

    foreach (range(1, 3) as $step) {
        $date = BillingCycle::Monthly->advance($date, anchorDay: 31);
        $dates[] = $date->toDateString();
    }

    expect($dates)->toBe(['2027-02-28', '2027-03-31', '2027-04-30']);
});

test('annual anchor 29 february falls back in common years and returns in leap years', function () {
    expect(BillingCycle::Annually->advance(CarbonImmutable::parse('2028-02-29'), anchorDay: 29)->toDateString())->toBe('2029-02-28')
        ->and(BillingCycle::Annually->advance(CarbonImmutable::parse('2028-02-29'), 4, 29)->toDateString())->toBe('2032-02-29');
});

test('retreating one period mirrors advancing', function () {
    expect(BillingDates::periodStartBefore(BillingCycle::Monthly, CarbonImmutable::parse('2027-03-31'), 31)->toDateString())->toBe('2027-02-28')
        ->and(BillingDates::periodStartBefore(BillingCycle::Quarterly, CarbonImmutable::parse('2027-05-31'), 31)->toDateString())->toBe('2027-02-28');
});
