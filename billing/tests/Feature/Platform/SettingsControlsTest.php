<?php

use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\BuildDomainContacts;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Services\SupportSettings;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** One section's current values as the settings form submits them, with overrides. */
function settingsControlsInput(string $section, array $override = []): array
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

function settingsControlsField(string $section, string $key): array
{
    return collect(app(SettingsCatalog::class)->fields($section))->firstWhere('key', $key);
}

function settingsControlsMigration(string $path): object
{
    return require base_path($path);
}

test('ticket statuses are a multiselect by name, read from the comma-separated default and saved as a list', function () {
    actingAsStaff(['support.settings.manage']);
    app(Settings::class)->set('support.awaiting_reply_statuses', 'open, customer_reply');

    $field = settingsControlsField('support', 'support.awaiting_reply_statuses');
    expect($field['type'])->toBe('multiselect')
        ->and($field['value'])->toBe(['open', 'customer_reply'])
        ->and(collect($field['options'])->pluck('label')->all())->toContain('Customer reply')->not->toContain('Closed');

    $this->put(route('admin.platform.settings.update', ['section' => 'support']), settingsControlsInput('support', ['support.awaiting_reply_statuses' => ['in_progress', 'open']]))
        ->assertSessionHasNoErrors();

    // Stored in option order; the Support reader takes the list as it is.
    expect(app(Settings::class)->get('support.awaiting_reply_statuses'))->toBe(['open', 'in_progress'])
        ->and(app(SupportSettings::class)->awaitingReplyStatuses())->toBe(['open', 'in_progress']);
});

test('a multiselect refuses a value outside its options, naming the field, and can be emptied', function () {
    actingAsStaff(['billing.settings.manage']);
    $route = route('admin.platform.settings.update', ['section' => 'billing']);

    $this->put($route, settingsControlsInput('billing', ['billing.tax.apply_to' => ['service', 'bogus']]))
        ->assertSessionHasErrors(['billing.tax.apply_to.1' => 'The selected taxed item kinds is invalid.']);

    $this->put($route, settingsControlsInput('billing', ['billing.tax.apply_to' => []]))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.tax.apply_to'))->toBe([]);
});

test('an untouched save of a section with list and multiselect settings changes nothing', function () {
    actingAsStaff(['billing.settings.manage']);

    // capture.retry_days defaults to [3, 7] in config and is edited as "3, 7".
    expect(settingsControlsField('billing', 'billing.capture.retry_days')['value'])->toBe('3, 7');

    $this->put(route('admin.platform.settings.update', ['section' => 'billing']), settingsControlsInput('billing'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'No changes to save.');
});

test('money settings are entered in the default currency and stored in minor units', function () {
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'Rs ', 'is_default' => true]);
    actingAsStaff(['billing.settings.manage']);
    app(Settings::class)->set('billing.credit.min_deposit', 50_000);

    $field = settingsControlsField('billing', 'billing.credit.min_deposit');
    expect($field)->toMatchArray(['type' => 'money', 'value' => '500.00', 'currency' => 'NPR', 'required' => true]);

    $route = route('admin.platform.settings.update', ['section' => 'billing']);
    $this->put($route, settingsControlsInput('billing', ['billing.credit.min_deposit' => '1250.50', 'billing.late_fee.minimum' => '100']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.credit.min_deposit'))->toBe(125_050)
        ->and(app(Settings::class)->get('billing.late_fee.minimum'))->toBe(10_000);

    $this->put($route, settingsControlsInput('billing', ['billing.credit.min_deposit' => '12.345']))->assertSessionHasErrors('billing.credit.min_deposit');
    $this->put($route, settingsControlsInput('billing', ['billing.credit.min_deposit' => '-1']))->assertSessionHasErrors('billing.credit.min_deposit');
});

test('a fixed late fee reads its own amount, and the upgrade copies the old minor-unit amount', function () {
    $invoice = Invoice::factory()->make(['total' => 100_000, 'balance' => 100_000]);
    app(Settings::class)->setMany(['billing.late_fee.type' => 'fixed', 'billing.late_fee.amount' => 5, 'billing.late_fee.fixed_amount' => 0, 'billing.late_fee.minimum' => 0]);

    expect(app(ApplyLateFee::class)->feeFor($invoice))->toBe(0);

    DB::table('settings')->where('key', 'billing.late_fee.fixed_amount')->delete();
    DB::table('settings')->where('key', 'billing.late_fee.amount')->update(['value' => json_encode(1_130)]);
    settingsControlsMigration('app/Modules/Billing/Database/Migrations/2026_10_31_300770_copy_fixed_late_fee_amount.php')->up();

    expect(app(Settings::class)->get('billing.late_fee.fixed_amount'))->toBe(1_130)
        ->and(app(ApplyLateFee::class)->feeFor($invoice))->toBe(1_130);

    app(Settings::class)->setMany(['billing.late_fee.type' => 'percent', 'billing.late_fee.amount' => 10]);
    expect(app(ApplyLateFee::class)->feeFor($invoice))->toBe(10_000);
});

test('the domains settings are split into sections and the developer cart route is not shown', function () {
    actingAsStaff(['domains.settings.manage']);
    $sections = app(SettingsRegistry::class)->sections();

    expect(array_keys($sections))->toContain('domains', 'domains_contact', 'domains_sync', 'domains_reminders', 'domains_search');

    $keys = collect($sections)->flatMap(fn (array $section) => collect($section['settings'])->pluck('key'));
    expect($keys)->not->toContain('domains.order_route')->not->toContain('domains.default_contact')
        ->and(collect($sections['domains_sync']['settings'])->pluck('key'))->toContain('domains.sync_batch_size')
        ->and(collect($sections['domains_search']['settings'])->pluck('key'))->toContain('domains.lookup_tlds');

    $this->get(route('admin.platform.settings.edit', ['section' => 'domains_search']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sections', fn ($items) => collect($items)->pluck('title')->contains('Domain search')));
});

test('domain search offers registrars by name and active extensions to pick', function () {
    Tld::factory()->extension('.com')->create(['is_active' => true]);
    Tld::factory()->extension('.com.np')->create(['is_active' => true]);
    Tld::factory()->extension('.old')->create(['is_active' => false]);
    actingAsStaff(['domains.settings.manage']);

    $registrar = settingsControlsField('domains_search', 'domains.lookup_registrar');
    expect($registrar['type'])->toBe('select')
        ->and(collect($registrar['options'])->firstWhere('value', 'log')['label'])->not->toBe('log');

    $tlds = settingsControlsField('domains_search', 'domains.lookup_tlds');
    expect(collect($tlds['options'])->pluck('value')->all())->toContain('.com', '.com.np')->not->toContain('.old')
        ->and($tlds['value'])->toBe(['.com']);

    $this->put(route('admin.platform.settings.update', ['section' => 'domains_search']), settingsControlsInput('domains_search', ['domains.lookup_tlds' => ['.com.np', '.com']]))
        ->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('domains.lookup_tlds'))->toBe(['.com', '.com.np']);
});

test('renewal invoice lead days is required, and a blank saved earlier reads as the shipped default', function () {
    actingAsStaff(['domains.settings.manage']);

    expect(settingsControlsField('domains', 'domains.invoice_lead_days'))->toMatchArray(['required' => true, 'help' => null]);
    $this->put(route('admin.platform.settings.update', ['section' => 'domains']), settingsControlsInput('domains', ['domains.invoice_lead_days' => '']))
        ->assertSessionHasErrors('domains.invoice_lead_days');

    app(Settings::class)->set('domains.invoice_lead_days', null);
    expect(app(DomainDates::class)->leadDays())->toBe(30);
});

test('the default domain contact is a form, used for new domains when client contacts are off', function () {
    actingAsStaff(['domains.settings.manage']);
    app(Settings::class)->set('domains.use_client_contacts', false);

    expect(settingsControlsField('domains_contact', 'domains.default_contact.country')['type'])->toBe('country');

    $this->put(route('admin.platform.settings.update', ['section' => 'domains_contact']), settingsControlsInput('domains_contact', [
        'domains.default_contact.first_name' => 'Sita', 'domains.default_contact.email' => 'domains@himalayan.test', 'domains.default_contact.country' => 'NP',
    ]))->assertSessionHasNoErrors();

    $domain = Domain::factory()->create(['client_id' => Client::factory()->create(['first_name' => 'Ram'])->id]);
    app(BuildDomainContacts::class)->forNewDomain($domain, $domain->client);

    expect($domain->contacts()->where('role', 'admin')->first()->only('first_name', 'email', 'country'))->toBe(['first_name' => 'Sita', 'email' => 'domains@himalayan.test', 'country' => 'NP'])
        ->and($domain->contacts()->where('role', 'registrant')->value('first_name'))->toBe('Ram');
});

test('the upgrade splits a saved JSON contact into fields and keeps a contact saved empty empty', function () {
    $migration = settingsControlsMigration('app/Modules/Domains/Database/Migrations/2026_10_31_500800_split_default_domain_contact.php');
    DB::table('settings')->insert(['key' => 'domains.default_contact', 'value' => json_encode(json_encode(['first_name' => 'Hari', 'country' => 'NP']))]);
    $migration->up();

    expect(app(Settings::class)->get('domains.default_contact.first_name'))->toBe('Hari')
        ->and(app(Settings::class)->get('domains.default_contact.company'))->toBeNull();

    DB::table('settings')->where('key', 'like', 'domains.default%')->delete();
    DB::table('settings')->insert(['key' => 'domains.default_contact', 'value' => json_encode(null)]);
    $migration->up();
    expect(app(Settings::class)->get('domains.default_contact.first_name'))->toBeNull();
});

test('the default support department is chosen by name', function () {
    $department = Department::factory()->create(['name' => 'Sales']);
    actingAsStaff(['support.settings.manage']);

    $field = settingsControlsField('support', 'support.default_department_id');
    expect($field['type'])->toBe('select')
        ->and(collect($field['options'])->firstWhere('value', (string) $department->id)['label'])->toBe('Sales');

    $this->put(route('admin.platform.settings.update', ['section' => 'support']), settingsControlsInput('support', ['support.default_department_id' => (string) $department->id]))
        ->assertSessionHasNoErrors();
    $this->put(route('admin.platform.settings.update', ['section' => 'support']), settingsControlsInput('support', ['support.default_department_id' => '999999']))
        ->assertSessionHasErrors('support.default_department_id');
});
