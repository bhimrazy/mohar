<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\Settings;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(SettingsRegistry::class)->section('acme', 'Acme', [
        new SettingDefinition('acme.label', 'Label', rules: ['required', 'max:20']),
        new SettingDefinition('acme.retries', 'Retries', 'integer', rules: ['min:0', 'max:10']),
        new SettingDefinition('acme.enabled', 'Enabled', 'boolean'),
        new SettingDefinition('acme.mode', 'Mode', 'select', options: ['live' => 'Live', 'test' => 'Test']),
        new SettingDefinition('acme.contact', 'Contact', 'email'),
        new SettingDefinition('acme.api_key', 'API key', secret: true),
    ], 'acme.settings.manage');

    config(['acme' => ['label' => 'Default', 'retries' => 3, 'enabled' => false, 'mode' => 'test', 'contact' => null, 'api_key' => null]]);
});

test('settings without a section redirect to the first visible section', function () {
    actingAsStaff(['core.settings.view']);

    $this->get(route('admin.platform.settings.edit'))->assertRedirect(route('admin.platform.settings.edit', ['section' => 'company']));
});

test('staff without settings permissions cannot open settings', function () {
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.platform.settings.edit'))->assertForbidden();
    $this->get(route('admin.platform.settings.edit', ['section' => 'acme']))->assertForbidden();
});

test('a section is visible to holders of its permission and lists its fields with values', function () {
    app(Settings::class)->set('acme.api_key', 'sk_live_123');
    actingAsStaff(['acme.settings.manage']);

    $this->get(route('admin.platform.settings.edit', ['section' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/platform/settings/edit')
            ->where('sections', [['key' => 'acme', 'title' => 'Acme']])
            ->where('section.editable', true)
            ->where('section.fields.0.value', 'Default')
            ->where('section.fields.1.value', 3)
            ->where('section.fields.3.options', [['value' => 'live', 'label' => 'Live'], ['value' => 'test', 'label' => 'Test']])
            ->where('section.fields.5.value', null)
            ->where('section.fields.5.is_set', true)
            ->where('section.fields.5.secret', true));
});

test('view-only staff see sections but cannot save them', function () {
    actingAsStaff(['core.settings.view']);

    $this->get(route('admin.platform.settings.edit', ['section' => 'acme']))->assertInertia(fn (Assert $page) => $page->where('section.editable', false));
    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => ['label' => 'Nope']])->assertForbidden();
});

test('values are validated by type and definition rules', function () {
    actingAsStaff(['acme.settings.manage']);

    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => [
        'label' => str_repeat('x', 21),
        'retries' => 'many',
        'enabled' => 'maybe',
        'mode' => 'chaos',
        'contact' => 'not-an-email',
    ]])->assertSessionHasErrors(['acme.label', 'acme.retries', 'acme.enabled', 'acme.mode', 'acme.contact']);

    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => ['label' => '', 'retries' => 11, 'enabled' => true, 'mode' => 'live']])
        ->assertSessionHasErrors(['acme.label', 'acme.retries']);
});

test('saving stores changed values, logs old and new, and masks secrets', function () {
    actingAsStaff(['acme.settings.manage']);
    app(Settings::class)->set('acme.api_key', 'sk_old');

    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => [
        'label' => 'Acme Hosting',
        'retries' => '5',
        'enabled' => true,
        'mode' => 'test',
        'contact' => 'ops@example.com',
        'api_key' => 'sk_new',
    ]])->assertRedirect(route('admin.platform.settings.edit', ['section' => 'acme']));

    $settings = app(Settings::class);
    expect($settings->get('acme.label'))->toBe('Acme Hosting')
        ->and($settings->get('acme.retries'))->toBe(5)
        ->and($settings->get('acme.enabled'))->toBeTrue()
        ->and($settings->get('acme.api_key'))->toBe('sk_new');

    $entry = ActivityLog::query()->where('event', 'settings.updated')->sole();
    expect($entry->properties['diff'])->toHaveKeys(['acme.label', 'acme.retries', 'acme.enabled', 'acme.contact', 'acme.api_key'])
        ->not->toHaveKey('acme.mode')
        ->and($entry->properties['diff']['acme.label'])->toBe(['old' => 'Default', 'new' => 'Acme Hosting'])
        ->and(json_encode($entry->properties))->not->toContain('sk_new')->not->toContain('sk_old');
});

test('a blank secret keeps the stored value', function () {
    actingAsStaff(['acme.settings.manage']);
    app(Settings::class)->set('acme.api_key', 'sk_keep');

    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => ['label' => 'Default', 'retries' => 3, 'enabled' => false, 'mode' => 'test', 'api_key' => '']]);

    expect(app(Settings::class)->get('acme.api_key'))->toBe('sk_keep')
        ->and(ActivityLog::query()->where('event', 'settings.updated')->exists())->toBeFalse();
});

test('the next read sees an updated setting even through a fresh Settings instance', function () {
    actingAsStaff(['core.settings.update']);
    app(Settings::class)->get('core.company_name');

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), ['core' => [
        'company_name' => 'Northwind Hosting',
        'company_email' => 'billing@northwind.test',
        'default_country' => 'GB',
        'date_format' => 'd/m/Y',
        'automation_time' => '04:30',
    ]])->assertSessionHasNoErrors();

    expect((new Settings(app(SettingsRegistry::class)))->get('core.company_name'))->toBe('Northwind Hosting');
});

test('every page receives the date format setting so dates follow it', function () {
    actingAsClient();

    $this->get(route('client.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dateFormat', 'M j, Y'));

    app(Settings::class)->set('core.date_format', 'd/m/Y');

    $this->get(route('client.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dateFormat', 'd/m/Y'));
});

test('a stored date format that is not on the list falls back to the default', function () {
    actingAsClient();
    app(Settings::class)->set('core.date_format', 'YYYY-MM-DD');

    $this->get(route('client.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dateFormat', DateFormats::DEFAULT));
});

test('the admin select offers exactly the supported date formats', function () {
    actingAsStaff(['core.settings.update']);

    $this->put(route('admin.platform.settings.update', ['section' => 'company']), ['core' => [
        'company_name' => 'Northwind Hosting',
        'company_email' => 'billing@northwind.test',
        'default_country' => 'GB',
        'date_format' => 'F j, Y',
        'automation_time' => '04:30',
    ]])->assertSessionHasErrors('core.date_format');
});

test('the browser formatter accepts the same date formats as the server', function () {
    $source = file_get_contents(resource_path('js/lib/date.ts'));
    preg_match('/DATE_FORMATS = new Set\(\[(.*?)\]\)/s', $source, $match);
    preg_match_all("/'([^']+)'/", $match[1] ?? '', $formats);

    expect($formats[1])->toEqualCanonicalizing(array_keys(DateFormats::OPTIONS));
});

test('unknown sections return 404', function () {
    actingAsStaff();

    $this->get(route('admin.platform.settings.edit', ['section' => 'nope']))->assertNotFound();
});

test('secret settings are encrypted at rest and decrypted on read', function () {
    $settings = app(Settings::class);
    $settings->set('acme.api_key', 'sk_live_plaintext_123');
    $settings->set('acme.label', 'Not secret');

    $stored = DB::table('settings')->where('key', 'acme.api_key')->value('value');
    expect($stored)->not->toContain('sk_live_plaintext_123')
        ->toContain(Settings::ENCRYPTED_PREFIX)
        ->and(json_decode((string) DB::table('settings')->where('key', 'acme.label')->value('value')))->toBe('Not secret')
        ->and($settings->get('acme.api_key'))->toBe('sk_live_plaintext_123')
        ->and((new Settings(app(SettingsRegistry::class)))->get('acme.api_key'))->toBe('sk_live_plaintext_123');
});

test('the settings cache never holds a plaintext secret', function () {
    $settings = app(Settings::class);
    $settings->set('acme.api_key', 'sk_live_cached_456');
    $settings->get('acme.api_key');

    expect(serialize(Cache::get('core.settings')))->not->toContain('sk_live_cached_456')
        ->and(json_encode($settings->all()))->not->toContain('sk_live_cached_456');
});

test('plaintext secrets saved before encryption are encrypted by the migration step', function () {
    DB::table('settings')->insert(['key' => 'acme.api_key', 'value' => json_encode('sk_legacy_789'), 'created_at' => now(), 'updated_at' => now()]);
    $settings = app(Settings::class);

    expect($settings->encryptStoredSecrets())->toBe(1)
        ->and($settings->encryptStoredSecrets())->toBe(0)
        ->and(DB::table('settings')->where('key', 'acme.api_key')->value('value'))->not->toContain('sk_legacy_789')
        ->and($settings->get('acme.api_key'))->toBe('sk_legacy_789');
});

test('a secret saved through the settings UI is stored encrypted and never rendered', function () {
    actingAsStaff(['acme.settings.manage']);

    $this->put(route('admin.platform.settings.update', ['section' => 'acme']), ['acme' => ['label' => 'Default', 'retries' => 3, 'enabled' => false, 'mode' => 'test', 'api_key' => 'sk_ui_000']])
        ->assertSessionHasNoErrors();

    expect(DB::table('settings')->where('key', 'acme.api_key')->value('value'))->not->toContain('sk_ui_000');
    $this->get(route('admin.platform.settings.edit', ['section' => 'acme']))->assertDontSee('sk_ui_000');
});

test('only registered secrets are decrypted, and only with ciphertext bound to their key', function () {
    $settings = app(Settings::class);
    $settings->set('acme.api_key', 'sk_bound_111');
    $ciphertext = DB::table('settings')->where('key', 'acme.api_key')->value('value');

    // Ciphertext copied into a non-secret setting is shown as the opaque string, never decrypted.
    DB::table('settings')->insert(['key' => 'acme.label', 'value' => $ciphertext, 'created_at' => now(), 'updated_at' => now()]);
    Cache::memo()->forget('core.settings');
    expect($settings->get('acme.label'))->not->toContain('sk_bound_111');

    // Ciphertext copied into another secret setting is refused.
    app(SettingsRegistry::class)->section('acme2', 'Acme 2', [new SettingDefinition('acme.other_key', 'Other key', secret: true)]);
    DB::table('settings')->insert(['key' => 'acme.other_key', 'value' => $ciphertext, 'created_at' => now(), 'updated_at' => now()]);
    Cache::memo()->forget('core.settings');
    expect($settings->get('acme.other_key', 'fallback'))->toBe('fallback')
        ->and($settings->get('acme.api_key'))->toBe('sk_bound_111');
});
