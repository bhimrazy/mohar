<?php

use App\Contracts\Billing\InvoiceQueries;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

test('converting between currencies uses both rates and rounds half up', function () {
    $usd = Currency::factory()->create();
    $inr = Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'exchange_rate' => 84, 'is_default' => false]);
    $npr = Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => '134.4', 'is_default' => false]);

    expect($usd->convertTo(1000, $inr))->toBe(84000)       // $10.00 → ₹840.00
        ->and($inr->convertTo(84000, $usd))->toBe(1000)
        ->and($inr->convertTo(1000, $npr))->toBe(1600)      // ₹10.00 → रू 16.00
        ->and($usd->convertTo(-1000, $inr))->toBe(-84000)
        ->and($usd->convertTo(0, $inr))->toBe(0);
});

test('conversion rounds half up', function () {
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create(['exchange_rate' => '0.925']);

    expect($usd->convertTo(1, $eur))->toBe(1)       // 0.925 → 1
        ->and($usd->convertTo(2, $eur))->toBe(2)    // 1.85 → 2
        ->and($eur->convertTo(37, $usd))->toBe(40); // 40.0 → 40
});

test('fromDefault converts default-currency prices through the shared conversion', function () {
    Currency::factory()->create();
    $inr = Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee', 'exchange_rate' => '84.5', 'is_default' => false]);

    expect($inr->fromDefault(999))->toBe(84416); // 9.99 × 84.5 = 844.155 → 844.16
});

test('currency management requires core.currencies.manage', function () {
    actingAsStaff(['core.clients.*']);

    $this->get(route('admin.platform.currencies.index'))->assertForbidden();
});

test('a currency can be created, listed and edited', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'npr', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '134.4'])
        ->assertRedirect(route('admin.platform.currencies.index'));

    $npr = Currency::query()->where('code', 'NPR')->sole();
    expect($npr)->decimals->toBe(2)->exchange_rate->toBe('134.400000')->rate_updated_at->not->toBeNull();

    $this->get(route('admin.platform.currencies.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/currencies/index')->has('currencies', 2)->where('currencies.0.is_default', true));

    $this->put(route('admin.platform.currencies.update', $npr), ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '135'])
        ->assertRedirect();
    expect($npr->fresh()->exchange_rate)->toBe('135.000000');
});

test('currency validation rejects bad codes, decimals, formats and rates', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'US', 'name' => '', 'decimals' => 1, 'format' => '1-234', 'exchange_rate' => 0])
        ->assertSessionHasErrors(['code', 'name', 'decimals', 'format', 'exchange_rate']);
    $this->post(route('admin.platform.currencies.store'), ['code' => 'JPY', 'name' => 'Yen', 'decimals' => 0, 'format' => '1,234.56', 'exchange_rate' => 150])
        ->assertSessionHasErrors(['decimals' => 'Only currencies with 2 decimal places are supported for now.']);
    $this->post(route('admin.platform.currencies.store'), ['code' => 'usd', 'name' => 'Dup', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => 1])
        ->assertSessionHasErrors('code');
});

test('the default currency rate stays fixed at 1', function () {
    actingAsStaff(['core.currencies.manage']);
    $usd = Currency::factory()->create();

    $this->put(route('admin.platform.currencies.update', $usd), ['code' => 'USD', 'name' => 'US Dollar', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => '2'])
        ->assertRedirect();

    expect($usd->fresh()->exchange_rate)->toBe('1.000000');
});

test('changing the default currency rebases rates while no invoices exist', function () {
    actingAsStaff(['core.currencies.manage']);
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create(['exchange_rate' => '0.8']);

    $this->post(route('admin.platform.currencies.default', $eur))->assertRedirect();

    expect($eur->fresh())->is_default->toBeTrue()->exchange_rate->toBe('1.000000')
        ->and($usd->fresh())->is_default->toBeFalse()->exchange_rate->toBe('1.250000');
});

test('the default currency cannot change once invoices exist (422)', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();
    $eur = Currency::factory()->euro()->create();
    $this->app->instance(InvoiceQueries::class, new class implements InvoiceQueries
    {
        public function hasPayments(int $invoiceId): bool
        {
            return false;
        }

        public function hasDocumentsInCurrency(string $currency): bool
        {
            return $currency === 'USD';
        }
    });

    $this->postJson(route('admin.platform.currencies.default', $eur))->assertUnprocessable()->assertJsonValidationErrors('currency');
    expect($eur->fresh()->is_default)->toBeFalse();
});

test('the default currency and currencies used by clients cannot be deleted', function () {
    actingAsStaff(['core.currencies.manage']);
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create();
    $gbp = Currency::factory()->create(['code' => 'GBP', 'name' => 'Pound', 'is_default' => false]);
    Client::factory()->create(['currency' => 'EUR']);

    $this->delete(route('admin.platform.currencies.destroy', $usd))->assertSessionHasErrors('currency');
    $this->delete(route('admin.platform.currencies.destroy', $eur))->assertSessionHasErrors('currency');
    $this->delete(route('admin.platform.currencies.destroy', $gbp))->assertRedirect(route('admin.platform.currencies.index'));

    expect(Currency::query()->pluck('code')->sort()->values()->all())->toBe(['EUR', 'USD']);
});
