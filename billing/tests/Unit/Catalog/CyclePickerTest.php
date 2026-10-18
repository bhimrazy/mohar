<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Support\CyclePicker;

it('uses the parent cycle when the addon is priced for it', function () {
    expect(CyclePicker::forAddon([BillingCycle::Annually, BillingCycle::Monthly], BillingCycle::Monthly))->toBe(BillingCycle::Monthly);
});

it('falls back to the addon shortest enabled cycle', function () {
    expect(CyclePicker::forAddon([BillingCycle::Biennially, BillingCycle::Annually], BillingCycle::Monthly))->toBe(BillingCycle::Annually)
        ->and(CyclePicker::forAddon([], BillingCycle::Monthly))->toBeNull();
});

it('sorts cycles from shortest to longest', function () {
    expect(CyclePicker::sort([BillingCycle::OneTime, BillingCycle::Triennially, BillingCycle::Monthly, BillingCycle::Quarterly]))
        ->toBe([BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Triennially, BillingCycle::OneTime]);
});
