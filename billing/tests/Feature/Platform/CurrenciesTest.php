<?php

use App\Contracts\Billing\InvoiceQueries;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Currencies\PriceConverters;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Platform\FakePriceConverter;

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
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'npr', 'name' => 'Nepalese Rupee', 'prefix' => 'Rs ', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '134.4'])
        ->assertRedirect();

    $npr = Currency::query()->where('code', 'NPR')->sole();
    expect($npr)->decimals->toBe(2)->exchange_rate->toBe('134.400000000000')->rate_updated_at->not->toBeNull();

    $this->get(route('admin.platform.currencies.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/currencies/index')->has('currencies', 2)->where('currencies.0.is_default', true));

    $this->put(route('admin.platform.currencies.update', $npr), ['code' => 'NPR', 'name' => 'Nepali Rupee', 'prefix' => 'Rs ', 'decimals' => 2, 'format' => '1,23,456.78'])
        ->assertRedirect(route('admin.platform.currencies.edit', $npr));
    expect($npr->fresh())->name->toBe('Nepali Rupee')->format->toBe('1,23,456.78');
});

test('the display form cannot change an existing currency rate', function () {
    actingAsStaff(['core.currencies.manage']);
    Currency::factory()->create();
    $npr = Currency::factory()->npr()->create();

    $this->put(route('admin.platform.currencies.update', $npr), ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '999'])
        ->assertSessionHasErrors('exchange_rate');
    expect($npr->fresh()->exchange_rate)->toBe('134.400000000000');
});

test('currency validation rejects bad codes, decimals, formats and rates', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Currency::factory()->create();

    $this->post(route('admin.platform.currencies.store'), ['code' => 'US', 'name' => '', 'decimals' => 1, 'format' => '1-234', 'exchange_rate' => 0])
        ->assertSessionHasErrors(['code', 'name', 'decimals', 'format', 'exchange_rate']);
    $this->post(route('admin.platform.currencies.store'), ['code' => 'JPY', 'name' => 'Yen', 'decimals' => 0, 'format' => '1,234.56', 'exchange_rate' => 150])
        ->assertSessionHasErrors(['decimals' => 'Only currencies with 2 decimal places are supported for now.']);
    $this->post(route('admin.platform.currencies.store'), ['code' => 'usd', 'name' => 'Dup', 'decimals' => 2, 'format' => '1,234.56', 'exchange_rate' => 1])
        ->assertSessionHasErrors('code');
});

test('the default currency rate stays fixed at 1', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    $usd = Currency::factory()->create();

    $this->put(route('admin.platform.currencies.rates.update', $usd), ['rate_source' => 'manual', 'exchange_rate' => '2', 'price_rounding' => 'none'])
        ->assertRedirect();

    expect($usd->fresh()->exchange_rate)->toBe('1.000000000000');
});

test('changing the default currency rebases rates while no invoices exist', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create(['exchange_rate' => '0.8']);

    $this->post(route('admin.platform.currencies.default', $eur))->assertRedirect();

    expect($eur->fresh())->is_default->toBeTrue()->exchange_rate->toBe('1.000000000000')
        ->and($usd->fresh())->is_default->toBeFalse()->exchange_rate->toBe('1.250000000000');
});

test('the default currency cannot change once invoices exist (422)', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
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
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    $usd = Currency::factory()->create();
    $eur = Currency::factory()->euro()->create();
    $gbp = Currency::factory()->create(['code' => 'GBP', 'name' => 'Pound', 'is_default' => false]);
    Client::factory()->create(['currency' => 'EUR']);

    $this->delete(route('admin.platform.currencies.destroy', $usd))->assertSessionHasErrors('currency');
    $this->delete(route('admin.platform.currencies.destroy', $eur))->assertSessionHasErrors('currency');
    $this->delete(route('admin.platform.currencies.destroy', $gbp))->assertRedirect(route('admin.platform.currencies.index'));

    expect(Currency::query()->pluck('code')->sort()->values()->all())->toBe(['EUR', 'USD']);
});

test('creating or deleting a currency needs the rates permission and a recent password', function () {
    Currency::factory()->create();
    $npr = Currency::factory()->npr()->create();
    $payload = ['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '1'];

    actingAsStaff(['core.currencies.manage']);
    confirmPassword();
    $this->get(route('admin.platform.currencies.create'))->assertForbidden();
    $this->post(route('admin.platform.currencies.store'), $payload)->assertForbidden();
    $this->delete(route('admin.platform.currencies.destroy', $npr))->assertForbidden();

    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    $this->flushSession();
    $this->post(route('admin.platform.currencies.store'), $payload)->assertSessionHasErrors('password_confirmation');
    $this->delete(route('admin.platform.currencies.destroy', $npr))->assertSessionHasErrors('password_confirmation');

    expect(Currency::query()->pluck('code')->sort()->values()->all())->toBe(['NPR', 'USD'])
        ->and($npr->fresh()->exchange_rate)->toBe('134.400000000000');
});

test('a currency used by prices, invoices or payments cannot be deleted', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Currency::factory()->create();
    $npr = Currency::factory()->npr()->create();
    $inr = Currency::factory()->inr()->create();
    $converter = new FakePriceConverter;
    $converter->currenciesInUse = ['NPR'];
    app()->instance(FakePriceConverter::class, $converter);
    app(PriceConverters::class)->register(FakePriceConverter::class);
    $this->app->instance(InvoiceQueries::class, new class implements InvoiceQueries
    {
        public function hasPayments(int $invoiceId): bool
        {
            return false;
        }

        public function hasDocumentsInCurrency(string $currency): bool
        {
            return $currency === 'INR';
        }
    });

    $this->delete(route('admin.platform.currencies.destroy', $npr))
        ->assertSessionHasErrors(['currency' => 'Test prices have prices in this currency. Remove those prices before deleting it.']);
    $this->delete(route('admin.platform.currencies.destroy', $inr))
        ->assertSessionHasErrors(['currency' => 'Invoices, quotes or transactions use this currency, so it cannot be deleted.']);

    expect(Currency::query()->count())->toBe(3);
});

test('with NPR as the default currency, conversions between USD and INR stay exact to the paisa', function () {
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'Rs ', 'exchange_rate' => 1, 'is_default' => true]);
    $usd = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '0.006581545347', 'is_default' => false]);
    $inr = Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee', 'exchange_rate' => '0.624707168515', 'is_default' => false]);

    // $10,000.00 → ₹949,180.07 at 151.94/160.075 × 100 (6-decimal rates would give ₹949,114.25, 0.007% off).
    expect($usd->fresh()->exchange_rate)->toBe('0.006581545347')
        ->and($usd->convertTo(1_000_000, $inr))->toBe(94_918_007)
        ->and($inr->convertTo(94_918_007, $usd))->toBe(1_000_000);
});

test('a default saved concurrently gives a friendly error instead of a 500', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Currency::factory()->create();
    $eur = Currency::factory()->euro()->create();
    Currency::factory()->create(['code' => 'GBP', 'name' => 'Pound', 'exchange_rate' => '0.8', 'is_default' => false]);

    // Another request makes GBP the default between our "clear the old default" and "set the new one".
    Currency::updating(function (Currency $currency): void {
        if ($currency->code === 'EUR' && $currency->is_default) {
            DB::table('currencies')->where('code', 'GBP')->update(['is_default' => true]);
        }
    });

    $this->post(route('admin.platform.currencies.default', $eur))
        ->assertSessionHasErrors(['currency' => 'Another default currency was just saved; reload and try again.']);

    expect(Currency::query()->where('is_default', true)->pluck('code')->all())->toBe(['USD']);
});

test('after the rate-precision migration, many non-default currencies coexist and only one default is allowed', function () {
    expect(Schema::hasIndex('currencies', 'currencies_single_default_unique'))->toBeTrue();
    $migration = require base_path('app/Modules/Core/Database/Migrations/2026_10_18_000140_add_currency_rate_sources_and_display.php');

    // As on a database where the one-default index already exists when this migration runs.
    $migration->down();
    $migration->up();

    Currency::factory()->create();
    Currency::factory()->inr()->create();
    Currency::factory()->npr()->create();

    expect(Schema::hasIndex('currencies', 'currencies_single_default_unique'))->toBeTrue()
        ->and(Currency::query()->where('is_default', false)->count())->toBe(2)
        ->and(fn () => Currency::factory()->euro()->create(['is_default' => true]))->toThrow(UniqueConstraintViolationException::class);
});

test('the list says why Make default and Delete are unavailable, and refusals show as an error toast', function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    confirmPassword();
    Currency::factory()->create();
    $npr = Currency::factory()->npr()->create();
    $inr = Currency::factory()->inr()->create();
    Client::factory()->count(2)->create(['currency' => 'NPR']);

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('currencies.0.default_blocked', 'Already the default')
        ->where('currencies.0.delete_blocked', 'The default currency can\'t be deleted')
        ->where('currencies.1.code', 'INR')->where('currencies.1.default_blocked', null)->where('currencies.1.delete_blocked', null)
        ->where('currencies.2.code', 'NPR')->where('currencies.2.delete_blocked', 'In use by 2 clients'));

    $this->delete(route('admin.platform.currencies.destroy', $npr))
        ->assertSessionHasErrors('currency')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Clients use this currency. Move them to another currency first.']);
    expect($inr->exists)->toBeTrue();
});
