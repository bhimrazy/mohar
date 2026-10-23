<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
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
        ->assertInertia(fn (Assert $page) => $page->where('section.fields', fn ($fields) => collect($fields)->firstWhere('key', 'core.default_country')['value'] === 'np'));

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => 'in']))->assertSessionHasNoErrors();

    expect((new Settings(app(SettingsRegistry::class)))->get('core.default_country'))->toBe('IN');
});

test('an unknown or free-text country is refused with a message naming the field', function (string $code) {
    actingAsStaff(['core.settings.update']);
    app(Settings::class)->set('core.default_country', 'NP');

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => $code]))
        ->assertSessionHasErrors(['core.default_country' => 'Choose a country from the list.']);

    expect(app(Settings::class)->get('core.default_country'))->toBe('NP');
})->with(['XX', 'ZZ', 'Nepal', '12']);

test('the default country cannot be cleared', function () {
    actingAsStaff(['core.settings.update']);

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), companyFormInput(['default_country' => '']))
        ->assertSessionHasErrors('core.default_country');
});

test('the tax countries are country fields that accept a known code or none and refuse an unknown one', function () {
    $catalog = app(SettingsCatalog::class);
    $section = collect(app(SettingsRegistry::class)->sections())
        ->filter(fn (array $s): bool => collect($s['settings'])->contains(fn ($d): bool => $d->key === 'billing.tax.home_country'))
        ->keys()->first();
    $rules = $catalog->rules($section);

    foreach (['billing.tax.home_country', 'billing.vat.home_country'] as $field) {
        expect(Validator::make(Arr::undot([$field => 'DE']), [$field => $rules[$field]])->passes())->toBeTrue()
            ->and(Validator::make(Arr::undot([$field => null]), [$field => $rules[$field]])->passes())->toBeTrue()
            ->and(Validator::make(Arr::undot([$field => 'QQ']), [$field => $rules[$field]])->passes())->toBeFalse();
    }
});
