<?php

use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Models\Tld;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Support\Models\Department;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** A section's current values as the settings form submits them, with overrides. */
function pr475Input(string $section, array $override = []): array
{
    $input = [];
    foreach (app(SettingsCatalog::class)->fields($section) as $field) {
        $value = $field['value'];
        Arr::set($input, $field['key'], is_bool($value) || is_array($value) ? $value : (string) ($value ?? ''));
    }
    foreach ($override as $key => $value) {
        Arr::set($input, $key, $value);
    }

    return $input;
}

function pr475Field(string $section, string $key): array
{
    return collect(app(SettingsCatalog::class)->fields($section))->firstWhere('key', $key);
}

function pr475Raw(string $key, mixed $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
    app(Settings::class)->forgetCached();
}

test('a fixed-fee install upgraded and switched to Percent shows 0%, saves, and charges the percent set', function () {
    pr475Raw('billing.late_fee.type', 'fixed');
    pr475Raw('billing.late_fee.amount', 250); // $2.50 / Rs 2.50 fixed, in minor units
    (require base_path('app/Modules/Billing/Database/Migrations/2026_10_31_300770_copy_fixed_late_fee_amount.php'))->up();

    expect(app(Settings::class)->get('billing.late_fee.fixed_amount'))->toBe(250);
    expect((float) pr475Field('billing', 'billing.late_fee.amount')['value'])->toBe(0.0);

    $invoice = Invoice::factory()->make(['total' => 100_000, 'balance' => 100_000]);
    expect(app(ApplyLateFee::class)->feeFor($invoice))->toBe(250);

    actingAsStaff(['billing.settings.manage']);
    $route = route('admin.platform.settings.update', ['section' => 'billing']);
    $this->put($route, pr475Input('billing', ['billing.late_fee.type' => 'percent']))->assertSessionHasNoErrors();
    expect(app(ApplyLateFee::class)->feeFor($invoice))->toBe(0);

    $this->put($route, pr475Input('billing', ['billing.late_fee.amount' => '5']))->assertSessionHasNoErrors();
    expect(app(ApplyLateFee::class)->feeFor($invoice))->toBe(5_000);
});

test('the late fee percent is at most 100, and a fixed install with an old amount above it still saves Billing', function () {
    actingAsStaff(['billing.settings.manage']);
    $route = route('admin.platform.settings.update', ['section' => 'billing']);
    $this->put($route, pr475Input('billing', ['billing.late_fee.amount' => '255']))->assertSessionHasErrors('billing.late_fee.amount');
    $this->put($route, pr475Input('billing', ['billing.late_fee.amount' => '100']))->assertSessionHasNoErrors();

    pr475Raw('billing.late_fee.type', 'fixed');
    pr475Raw('billing.late_fee.amount', 1130);
    (require base_path('app/Modules/Billing/Database/Migrations/2026_10_31_300770_copy_fixed_late_fee_amount.php'))->up();
    // Staff change an unrelated Billing setting; the field they never set no longer blocks the save.
    $this->put($route, pr475Input('billing', ['billing.mass_pay_enabled' => false]))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.mass_pay_enabled'))->toBeFalse()
        ->and(app(Settings::class)->get('billing.late_fee.fixed_amount'))->toBe(1130);
});

test('the late fee migration is idempotent and leaves fresh and percent installs alone', function () {
    $m = require base_path('app/Modules/Billing/Database/Migrations/2026_10_31_300770_copy_fixed_late_fee_amount.php');
    $m->up();
    expect(DB::table('settings')->where('key', 'billing.late_fee.fixed_amount')->exists())->toBeFalse();

    pr475Raw('billing.late_fee.type', 'percent');
    pr475Raw('billing.late_fee.amount', 5);
    $m->up();
    expect(DB::table('settings')->where('key', 'billing.late_fee.fixed_amount')->exists())->toBeFalse()
        ->and(app(Settings::class)->get('billing.late_fee.amount'))->toBe(5);

    pr475Raw('billing.late_fee.type', 'fixed');
    pr475Raw('billing.late_fee.amount', '250.6');
    $m->up();
    $m->up();
    expect(app(Settings::class)->get('billing.late_fee.fixed_amount'))->toBe(251)
        ->and(DB::table('settings')->where('key', 'billing.late_fee.fixed_amount')->count())->toBe(1)
        ->and(app(Settings::class)->get('billing.late_fee.amount'))->toBe(0);
});

test('money settings convert exactly at the edges and every money field says it applies in each client\'s currency', function () {
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'Rs ', 'is_default' => true]);
    actingAsStaff(['billing.settings.manage']);
    $route = route('admin.platform.settings.update', ['section' => 'billing']);
    $cases = ['0' => 0, '0.01' => 1, '0.29' => 29, '1.15' => 115, '4.35' => 435, '1234567.89' => 123456789, '10000000' => 1_000_000_000, '19.9' => 1990];
    foreach ($cases as $in => $minor) {
        $this->put($route, pr475Input('billing', ['billing.credit.max_deposit' => (string) $in]))->assertSessionHasNoErrors();
        expect(app(Settings::class)->get('billing.credit.max_deposit'))->toBe($minor);
        expect(pr475Field('billing', 'billing.credit.max_deposit')['value'])->toBe(number_format($minor / 100, 2, '.', ''));
    }
    foreach (['10000000.01', '-0.01', '1e3', 'abc', '', '1,000.00'] as $bad) {
        $this->put($route, pr475Input('billing', ['billing.credit.max_deposit' => $bad]))->assertSessionHasErrors('billing.credit.max_deposit');
    }
    foreach (['billing.credit.min_deposit', 'billing.credit.max_deposit', 'billing.credit.max_balance', 'billing.late_fee.minimum', 'billing.late_fee.fixed_amount'] as $key) {
        $f = pr475Field('billing', $key);
        expect($f['currency'])->toBe('NPR')->and($f['help'])->toContain('each client\'s currency');
    }
});

test('a TLD list typed without dots or in upper case shows its extensions checked', function () {
    Tld::factory()->extension('.com')->create(['is_active' => true]);
    Tld::factory()->extension('.np')->create(['is_active' => true]);
    pr475Raw('domains.lookup_tlds', 'com, NP');
    expect(pr475Field('domains_search', 'domains.lookup_tlds')['value'])->toBe(['.com', '.np']);
});

test('the domain contact migration handles malformed, list, nested, numeric and already-split rows', function () {
    $m = require base_path('app/Modules/Domains/Database/Migrations/2026_10_31_500800_split_default_domain_contact.php');
    $reset = fn () => DB::table('settings')->where('key', 'like', 'domains.default_contact%')->delete();

    foreach (['{bad json', json_encode(['a', 'b'])] as $raw) {
        $reset();
        pr475Raw('domains.default_contact', $raw);
        $m->up();
        expect(app(Settings::class)->get('domains.default_contact.first_name'))->toBeNull()
            ->and(DB::table('settings')->where('key', 'like', 'domains.default_contact.%')->count())->toBe(11);
    }

    $reset();
    pr475Raw('domains.default_contact', json_encode(['first_name' => ['x'], 'phone' => 9801234567, 'Email' => 'a@b.c', 'extra' => 'z']));
    $m->up();
    $m->up();
    expect(app(Settings::class)->get('domains.default_contact.first_name'))->toBeNull()
        ->and(app(Settings::class)->get('domains.default_contact.phone'))->toBe('9801234567')
        ->and(app(Settings::class)->get('domains.default_contact.email'))->toBeNull()
        ->and(DB::table('settings')->where('key', 'like', 'domains.default_contact.%')->count())->toBe(11);

    // A stored array (not a JSON string) is also read.
    $reset();
    pr475Raw('domains.default_contact', ['first_name' => 'Gita']);
    $m->up();
    expect(app(Settings::class)->get('domains.default_contact.first_name'))->toBe('Gita');
});

test('untouched Domains and Billing saves keep config list defaults', function () {
    actingAsStaff(['billing.settings.manage', 'domains.settings.manage']);
    foreach (['billing', 'domains', 'domains_reminders', 'domains_search', 'domains_contact', 'domains_sync'] as $section) {
        $this->put(route('admin.platform.settings.update', ['section' => $section]), pr475Input($section))
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'No changes to save.');
    }
    // Edit a different field in Domains: nameservers must stay as config list values.
    $this->put(route('admin.platform.settings.update', ['section' => 'domains']), pr475Input('domains', ['domains.lock_by_default' => false]))->assertSessionHasNoErrors();
    expect(DB::table('settings')->where('key', 'domains.default_nameservers')->exists())->toBeFalse();
    $this->put(route('admin.platform.settings.update', ['section' => 'domains']), pr475Input('domains', ['domains.default_nameservers' => "ns1.a.np\nns2.a.np"]))->assertSessionHasNoErrors();
    expect(SettingList::of(app(Settings::class)->get('domains.default_nameservers')))->toBe(['ns1.a.np', 'ns2.a.np']);
});

test('a saved lookup registrar that is no longer registered shows as not available and the section still saves', function () {
    actingAsStaff(['domains.settings.manage']);
    pr475Raw('domains.lookup_registrar', 'gone_driver');
    $f = pr475Field('domains_search', 'domains.lookup_registrar');
    expect($f['value'])->toBe('gone_driver')
        ->and(collect($f['options'])->firstWhere('value', 'gone_driver')['label'])->toBe('Gone Driver (not available)');
    $this->put(route('admin.platform.settings.update', ['section' => 'domains_search']), pr475Input('domains_search', ['domains.allow_idn' => false]))
        ->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('domains.allow_idn'))->toBeFalse();
});

test('a settings page reads each option source once', function () {
    Department::factory()->count(3)->create();
    actingAsStaff(['domains.settings.manage', 'support.settings.manage']);
    foreach (['domains_search', 'support'] as $s) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.platform.settings.edit', ['section' => $s]))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, '"tlds"') || str_contains($sql, '"support_departments"') || str_contains($sql, '"departments"'));
        expect($queries)->toHaveCount(1);
    }
});
