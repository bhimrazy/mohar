<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('converting 10.00 USD to a zero-decimal currency at rate 150 gives 1500 yen', function () {
    $usd = Currency::factory()->create();
    $jpy = Currency::factory()->create(['code' => 'JPY', 'name' => 'Yen', 'prefix' => '¥', 'decimals' => 0, 'exchange_rate' => 150, 'is_default' => false]);

    expect($usd->convertTo(1000, $jpy))->toBe(1500)
        ->and($jpy->convertTo(1500, $usd))->toBe(1000)
        ->and($usd->convertTo(-1000, $jpy))->toBe(-1500)
        ->and($usd->convertTo(0, $jpy))->toBe(0);
});

test('conversion rounds half up', function () {
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create(['exchange_rate' => '0.925']);

    expect($usd->convertTo(1, $eur))->toBe(1)       // 0.925 → 1
        ->and($usd->convertTo(2, $eur))->toBe(2)    // 1.85 → 2
        ->and($eur->convertTo(37, $usd))->toBe(40); // 40.0 → 40
});

test('currency management requires core.currencies.manage', function () {
    actingAsStaff(['core.clients.*']);

    $this->get(route('admin.platform.currencies.index'))->assertForbidden();
});

test('a currency can be created, listed and edited', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'jpy', 'name' => 'Japanese Yen', 'prefix' => '¥', 'decimals' => 0, 'format' => '1,234.56', 'exchange_rate' => '150.5'])
        ->assertRedirect(route('admin.platform.currencies.index'));

    $jpy = Currency::query()->where('code', 'JPY')->sole();
    expect($jpy)->decimals->toBe(0)->exchange_rate->toBe('150.500000')->rate_updated_at->not->toBeNull();

    $this->get(route('admin.platform.currencies.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/currencies/index')->has('currencies', 2)->where('currencies.0.is_default', true));

    $this->put(route('admin.platform.currencies.update', $jpy), ['code' => 'JPY', 'name' => 'Yen', 'decimals' => 0, 'format' => '1,234.56', 'exchange_rate' => '151'])
        ->assertRedirect();
    expect($jpy->fresh()->exchange_rate)->toBe('151.000000');
});

test('currency validation rejects bad codes, decimals, formats and rates', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'US', 'name' => '', 'decimals' => 1, 'format' => '1-234', 'exchange_rate' => 0])
        ->assertSessionHasErrors(['code', 'name', 'decimals', 'format', 'exchange_rate']);
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

    config(['platform.currency_lock_tables' => ['platform_test_invoices']]);
    Schema::create('platform_test_invoices', function (Blueprint $table) {
        $table->id();
        $table->string('number');
    });
    DB::table('platform_test_invoices')->insert(['number' => 'INV-1']);

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
