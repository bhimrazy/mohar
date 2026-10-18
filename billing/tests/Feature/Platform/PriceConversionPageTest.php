<?php

use App\Modules\Core\Models\Currency;
use App\Support\Currencies\PriceConverters;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Platform\FakePriceConverter;

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    $this->converter = new FakePriceConverter;
    app()->instance(FakePriceConverter::class, $this->converter);
    app(PriceConverters::class)->register(FakePriceConverter::class);
});

test('the converted-prices page previews every registered converter', function () {
    actingAsStaff(['core.currencies.rates']);

    $this->get(route('admin.platform.currencies.conversion'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/platform/currencies/conversion')
        ->where('defaultCode', 'USD')
        ->where('currencies.0.code', 'NPR')
        ->where('previews', fn ($previews) => collect($previews)->firstWhere('key', 'fake') === [
            'key' => 'fake', 'label' => 'Test prices', 'changes' => 2, 'unchanged' => 5, 'manual' => 1, 'automatic' => false,
            'samples' => [['item' => 'Starter', 'currency' => 'NPR', 'period' => 'Monthly', 'before' => 130_000, 'after' => 134_266]],
        ]));
    expect($this->converter->applied)->toBe(0);
});

test('applying a converter needs a recent password and is audited', function () {
    actingAsStaff(['core.currencies.rates']);

    $this->post(route('admin.platform.currencies.conversion.apply', 'fake'))->assertSessionHasErrors('password_confirmation');
    expect($this->converter->applied)->toBe(0);

    confirmPassword();
    $this->post(route('admin.platform.currencies.conversion.apply', 'fake'))
        ->assertRedirect(route('admin.platform.currencies.conversion'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 prices updated.']);
    $this->post(route('admin.platform.currencies.conversion.apply', 'missing'))->assertNotFound();

    expect($this->converter->applied)->toBe(1);
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.prices_converted']);
});
