<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

function companyFormInput(array $core): array
{
    return ['core' => $core + [
        'company_name' => 'Northwind Hosting',
        'company_email' => 'billing@northwind.test',
        'default_country' => 'NP',
        'date_format' => 'd/m/Y',
        'automation_time' => '04:30',
    ], 'system' => ['timezone' => 'Asia/Kathmandu']];
}

test('the company form offers the default country as a country field, not a text box', function () {
    actingAsStaff(['core.settings.update']);

    $this->get(route('admin.platform.settings.edit', ['section' => 'company']))->assertInertia(fn (Assert $page) => $page
        ->where('section.fields', fn ($fields) => collect($fields)->firstWhere('key', 'core.default_country')['type'] === 'country'
            && collect($fields)->firstWhere('key', 'core.default_country')['required'] === true)
        ->where('countries', fn ($codes) => collect($codes)->contains('NP')));
});

test('a stored default country round-trips and a legacy lowercase code is normalised on save', function () {
    actingAsStaff(['core.settings.update']);
    app(Settings::class)->set('core.default_country', 'np');

    $this->get(route('admin.platform.settings.edit', ['section' => 'company']))
        ->assertInertia(fn (Assert $page) => $page->where('section.fields', fn ($fields) => collect($fields)->firstWhere('key', 'core.default_country')['value'] === 'NP'));

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => 'in']))->assertSessionHasNoErrors();

    expect((new Settings(app(SettingsRegistry::class)))->get('core.default_country'))->toBe('IN');
});

test('an unknown or free-text country is refused with a message naming the field', function (string $code) {
    actingAsStaff(['core.settings.update']);
    app(Settings::class)->set('core.default_country', 'NP');

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => $code]))
        ->assertSessionHasErrors(['core.default_country' => 'The default country must be a country from the list.']);

    expect(app(Settings::class)->get('core.default_country'))->toBe('NP');
})->with(['XX', 'ZZ', 'Nepal', '12']);

test('the default country cannot be cleared', function () {
    actingAsStaff(['core.settings.update']);

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => '']))
        ->assertSessionHasErrors('core.default_country');
});

function billingSectionKey(): string
{
    return collect(app(SettingsRegistry::class)->sections())
        ->filter(fn (array $s): bool => collect($s['settings'])->contains(fn ($d): bool => $d->key === 'billing.tax.home_country'))
        ->keys()->first();
}

/** The billing section's current values as the form would submit them, with overrides. */
function billingSectionInput(array $override = []): array
{
    $input = [];

    foreach (app(SettingsCatalog::class)->fields(billingSectionKey()) as $field) {
        $value = $field['value'];
        Arr::set($input, $field['key'], is_bool($value) || is_array($value) ? $value : (string) ($value ?? ''));
    }

    foreach ($override as $key => $value) {
        Arr::set($input, $key, $value);
    }

    return $input;
}

test('the tax countries can be cleared or set through the real save, and an unknown one is refused', function () {
    actingAsStaff(['*']);
    confirmPassword();
    app(Settings::class)->setMany(['billing.tax.home_country' => 'NP', 'billing.vat.home_country' => 'DE']);
    $route = route('admin.platform.settings.update', ['section' => billingSectionKey()]);

    $this->put($route, billingSectionInput(['billing.tax.home_country' => '', 'billing.vat.home_country' => '']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.tax.home_country'))->toBeNull()
        ->and(app(Settings::class)->get('billing.vat.home_country'))->toBeNull();

    $this->put($route, billingSectionInput(['billing.tax.home_country' => 'IN']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.tax.home_country'))->toBe('IN');

    $this->put($route, billingSectionInput(['billing.tax.home_country' => 'QQ']))
        ->assertSessionHasErrors(['billing.tax.home_country' => 'The seller tax country must be a country from the list.']);
});

test('a legacy stored code shows as its ISO code and an unknown stored code does not block saving other settings', function () {
    actingAsStaff(['*']);
    confirmPassword();
    app(Settings::class)->setMany(['billing.tax.home_country' => 'UK', 'billing.vat.home_country' => 'ZZ']);
    $route = route('admin.platform.settings.update', ['section' => billingSectionKey()]);

    $field = collect(app(SettingsCatalog::class)->fields(billingSectionKey()))->firstWhere('key', 'billing.tax.home_country');
    expect($field['value'])->toBe('GB');

    $this->put($route, billingSectionInput(['billing.vat.home_country' => 'ZZ', 'billing.late_fee.amount' => '5']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.vat.home_country'))->toBe('ZZ')
        ->and(app(Settings::class)->get('billing.tax.home_country'))->toBe('GB');
});

test('VAT home country must be an EU country while EU VAT rules are on', function () {
    actingAsStaff(['*']);
    confirmPassword();
    $route = route('admin.platform.settings.update', ['section' => billingSectionKey()]);

    foreach (['', 'NP'] as $bad) {
        $this->put($route, billingSectionInput(['billing.vat.enabled' => true, 'billing.vat.home_country' => $bad]))
            ->assertSessionHasErrors('billing.vat.home_country');
    }

    $this->put($route, billingSectionInput(['billing.vat.enabled' => true, 'billing.vat.home_country' => 'EL']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.vat.home_country'))->toBe('GR');
});

test('the required flag matches what the server requires for every field type', function () {
    $fields = collect(app(SettingsCatalog::class)->fields(billingSectionKey()))->keyBy('key');

    expect($fields['billing.tax.mode']['required'])->toBeTrue()
        ->and($fields['billing.vat.enabled']['required'])->toBeTrue()
        ->and($fields['billing.tax.home_country']['required'])->toBeFalse()
        ->and($fields['billing.vat.home_country']['value'])->toBeNull();
});

test('a fresh install has no VAT home country', function () {
    expect(config('billing.vat.home_country'))->toBeNull();
});
