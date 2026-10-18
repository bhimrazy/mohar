<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->inr()->create();
    Currency::factory()->npr()->create();
});

test('the kernel formatter uses each currency\'s configured display', function () {
    $format = app(MoneyFormatter::class);

    expect($format->format(Money::of(123450, 'USD')))->toBe('$1,234.50')
        ->and($format->format(Money::of(12345600, 'INR')))->toBe('₹1,23,456.00')
        ->and($format->format(Money::of(12345600, 'NPR')))->toBe('Rs 1,23,456.00')
        ->and($format->format(Money::of(-120000, 'NPR')))->toBe('−Rs 1,200.00')
        ->and($format->amount(-1200, 'usd'))->toBe('−$12.00')
        ->and(Money::of(2499, 'USD')->format())->toBe('24.99 USD');
});

test('a customised prefix applies at once, across requests and workers', function () {
    $format = app(MoneyFormatter::class);
    expect($format->amount(100, 'NPR'))->toBe('Rs 1.00');

    Currency::query()->where('code', 'NPR')->sole()->update(['prefix' => 'NRs ', 'format' => '1,234.56']);

    expect($format->amount(12345600, 'NPR'))->toBe('NRs 123,456.00')
        ->and(app(MoneyFormatter::class)->amount(12345600, 'NPR'))->toBe('NRs 123,456.00');
});

test('pages share the currency display config with the browser', function () {
    actingAsStaff(['*']);

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page
        ->has('currencyFormats', 3)
        ->where('currencyFormats.0', ['code' => 'USD', 'prefix' => '$', 'suffix' => '', 'decimals' => 2, 'format' => '1,234.56'])
        ->where('currencyFormats.2', ['code' => 'NPR', 'prefix' => 'Rs ', 'suffix' => '', 'decimals' => 2, 'format' => '1,23,456.78']));

    $client = Client::factory()->create(['currency' => 'NPR']);
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);
    $this->actingAs($user)->get(route('client.dashboard'))->assertInertia(fn (Assert $page) => $page->has('currencyFormats', 3));
});

test('the display migration moves old seed defaults to Rs and lakh grouping but keeps customised rows', function () {
    $migration = require base_path('app/Modules/Core/Database/Migrations/2026_10_18_000140_add_currency_rate_sources_and_display.php');
    $migration->down();
    DB::table('currencies')->delete();
    DB::table('currencies')->insert([
        ['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,234.56', 'exchange_rate' => 1, 'is_default' => true],
        ['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'suffix' => '', 'format' => '1,234.56', 'exchange_rate' => 84, 'is_default' => false],
        ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'suffix' => '', 'format' => '1.234,56', 'exchange_rate' => 134.4, 'is_default' => false],
    ]);

    $migration->up();

    $rows = DB::table('currencies')->orderBy('code')->get(['code', 'prefix', 'format'])->keyBy('code');
    expect($rows['USD']->format)->toBe('1,234.56')
        ->and($rows['INR']->format)->toBe('1,23,456.78')
        ->and($rows['NPR']->prefix)->toBe('Rs ')
        ->and($rows['NPR']->format)->toBe('1.234,56'); // staff-customised format kept
});
