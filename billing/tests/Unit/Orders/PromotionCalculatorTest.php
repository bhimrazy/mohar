<?php

use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Pricing\DiscountTarget;
use App\Modules\Orders\Pricing\PromotionCalculator;
use App\Modules\Orders\Pricing\PromotionTerms;

function discountProduct(string $key, int $base, int $setup = 0, int $productId = 1, string $cycle = 'monthly'): DiscountTarget
{
    return new DiscountTarget($key, 'product', $base, $setup, productId: $productId, cycle: $cycle);
}

it('takes 25% off the recurring amount and leaves the setup fee alone', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 2500, productIds: [1]),
        [discountProduct('p', 1000, 500)],
    );

    expect($discounts)->toBe(['p' => ['discount' => 250, 'setup_discount' => 0]]);
});

it('also discounts the setup fee when the promotion includes setup fees', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 2500, discountSetupFee: true),
        [discountProduct('p', 1000, 500)],
    );

    expect($discounts['p'])->toBe(['discount' => 250, 'setup_discount' => 125]);
});

it('rounds percentage discounts half-up to the minor unit', function (int $base, int $hundredths, int $expected) {
    expect(PromotionCalculator::percentOf($base, $hundredths))->toBe($expected);
})->with([
    '12.5% of 3.33 = 0.41625' => [333, 1250, 42],
    '33.33% of 9.99 = 3.329667' => [999, 3333, 333],
    '50% of 0.01 = 0.005' => [1, 5000, 1],
    '10% of 0.04 = 0.004' => [4, 1000, 0],
    'zero amount' => [0, 2500, 0],
]);

it('caps a fixed discount at the item price so the line is never negative', function () {
    $discounts = (new PromotionCalculator)->discounts(new PromotionTerms(PromotionType::Fixed, 1500), [discountProduct('p', 1000, 500)]);

    expect($discounts['p'])->toBe(['discount' => 1000, 'setup_discount' => 0]);
});

it('applies the rest of a fixed discount to the setup fee when allowed', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Fixed, 1200, discountSetupFee: true),
        [discountProduct('p', 1000, 500)],
    );

    expect($discounts['p'])->toBe(['discount' => 1000, 'setup_discount' => 200]);
});

it('discounts the difference to the override price', function () {
    $discounts = (new PromotionCalculator)->discounts(new PromotionTerms(PromotionType::PriceOverride, 700), [discountProduct('p', 1000)]);

    expect($discounts['p']['discount'])->toBe(300);
});

it('gives no discount when the override price is above the item price', function () {
    expect((new PromotionCalculator)->discounts(new PromotionTerms(PromotionType::PriceOverride, 1500), [discountProduct('p', 1000)]))->toBe([]);
});

it('waives the whole setup fee for free-setup promotions', function () {
    $discounts = (new PromotionCalculator)->discounts(new PromotionTerms(PromotionType::FreeSetup, 0), [discountProduct('p', 1000, 499)]);

    expect($discounts['p'])->toBe(['discount' => 0, 'setup_discount' => 499]);
});

it('discounts only the most expensive applicable item when applied once', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 1000, applyOnce: true),
        [discountProduct('cheap', 500), discountProduct('pricey', 2000, productId: 2), discountProduct('mid', 1000, productId: 3)],
    );

    expect($discounts)->toBe(['pricey' => ['discount' => 200, 'setup_discount' => 0]]);
});

it('skips items whose billing cycle is not allowed', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 1000, cycles: ['annually']),
        [discountProduct('monthly', 1000), discountProduct('yearly', 10000, cycle: 'annually')],
    );

    expect(array_keys($discounts))->toBe(['yearly']);
});

it('matches products, addons and domains named in applies_to', function () {
    $targets = [
        discountProduct('p1', 1000, productId: 1),
        discountProduct('p2', 1000, productId: 2),
        new DiscountTarget('a', 'addon', 200, addonId: 7, cycle: 'monthly'),
        new DiscountTarget('d', 'domain', 1200, tld: '.com'),
        new DiscountTarget('d2', 'domain', 1200, tld: '.net'),
    ];

    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 5000, productIds: [2], addonIds: [7], tlds: ['.com']),
        $targets,
    );

    expect(array_keys($discounts))->toBe(['p2', 'a', 'd']);
});

it('applies an unrestricted promotion to products only', function () {
    $discounts = (new PromotionCalculator)->discounts(
        new PromotionTerms(PromotionType::Percent, 5000),
        [discountProduct('p', 1000), new DiscountTarget('a', 'addon', 200, addonId: 7), new DiscountTarget('d', 'domain', 1200, tld: '.com')],
    );

    expect(array_keys($discounts))->toBe(['p']);
});

it('never discounts more than the item price for percentages above 100', function () {
    expect((new PromotionCalculator)->discountFor(new PromotionTerms(PromotionType::Percent, 15000), discountProduct('p', 1000)))
        ->toBe(['discount' => 1000, 'setup_discount' => 0]);
});
