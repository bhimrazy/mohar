<?php

use App\Events\Core\ExchangeRatesUpdated;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Jobs\ConvertPricesAfterRateChange;
use App\Support\Currencies\PriceConverters;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Queue;
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

test('a rate change converts automatic modules once, queued and unique per currency set', function () {
    Queue::fake();

    ExchangeRatesUpdated::dispatch(['NPR', 'INR'], 'automatic');
    ExchangeRatesUpdated::dispatch(['INR', 'NPR'], 'manual');

    Queue::assertPushed(ConvertPricesAfterRateChange::class, 1);
    Queue::assertPushed(ConvertPricesAfterRateChange::class, fn (ConvertPricesAfterRateChange $job): bool => $job->uniqueId() === 'INR,NPR');
});

test('a rate change arriving while a conversion runs queues a new conversion', function () {
    Queue::fake();
    ExchangeRatesUpdated::dispatch(['NPR'], 'automatic');
    $running = Queue::pushed(ConvertPricesAfterRateChange::class)->sole();

    // A worker releases the unique lock as it starts the job (ShouldBeUniqueUntilProcessing).
    expect($running)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    (new UniqueLock(app(Cache::class)))->release($running);
    ExchangeRatesUpdated::dispatch(['NPR'], 'review');

    Queue::assertPushed(ConvertPricesAfterRateChange::class, 2);
});

test('a failing converter never fails the rate save; it is audited and other modules still convert', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    $this->converter->automatic = true;
    $this->converter->throws = true;
    $npr = Currency::query()->where('code', 'NPR')->sole();

    $this->put(route('admin.platform.currencies.rates.update', $npr), ['rate_source' => 'manual', 'exchange_rate' => '150', 'price_rounding' => 'none'])
        ->assertRedirect(route('admin.platform.currencies.edit', $npr))
        ->assertSessionHasNoErrors();

    expect($npr->fresh()->exchange_rate)->toBe('150.000000000000');
    $this->assertDatabaseHas('activity_log', ['event' => 'currency.prices_conversion_failed']);
});
