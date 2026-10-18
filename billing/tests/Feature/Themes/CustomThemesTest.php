<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Models\Theme;
use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

function presetTokens(string $key = 'calm'): array
{
    return app(ThemeManager::class)->presetDocument($key);
}

function themeFile(array $document): UploadedFile
{
    return UploadedFile::fake()->createWithContent('theme.json', json_encode($document));
}

function themeDocument(array $overrides = []): array
{
    return [
        'schema' => 'billing-theme',
        'version' => 1,
        'name' => 'Imported',
        'tokens' => presetTokens('slate'),
        ...$overrides,
    ];
}

it('opens the editor prefilled from a preset', function () {
    actingAsStaff(['themes.manage']);

    $this->get(route('admin.appearance.themes.create', ['from' => 'warm']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/theme-editor')
            ->where('basePreset', 'warm')
            ->where('tokens.light.primary', '#B4532E')
            ->has('schema.contrastPairs')
            ->has('presets', 4));
});

it('saves a custom theme duplicated from a preset', function () {
    $user = actingAsStaff(['themes.manage']);
    $tokens = presetTokens();
    $tokens['light']['primary'] = '#3F3AB0';

    $this->post(route('admin.appearance.themes.store'), ['name' => 'Acme brand', 'base_preset' => 'calm', 'tokens' => $tokens])
        ->assertRedirect();

    $theme = Theme::query()->sole();
    expect($theme->key)->toStartWith('custom-acme-brand-')
        ->and($theme->tokens['light']['primary'])->toBe('#3F3AB0')
        ->and($theme->created_by)->toBe($user->id)
        ->and(app(ThemeManager::class)->find($theme->key)?->name)->toBe('Acme brand');
});

it('blocks saving when a key pair fails WCAG AA', function () {
    actingAsStaff(['themes.manage']);
    $tokens = presetTokens();
    $tokens['dark']['primary-foreground'] = $tokens['dark']['primary'];

    $this->post(route('admin.appearance.themes.store'), ['name' => 'Low contrast', 'tokens' => $tokens])
        ->assertSessionHasErrors('tokens.dark.primary-foreground');

    expect(Theme::query()->count())->toBe(0);
});

it('rejects unknown tokens and values outside the whitelist', function (string $group, string $token, mixed $value) {
    actingAsStaff(['themes.manage']);
    $tokens = presetTokens();
    $tokens[$group][$token] = $value;

    $this->post(route('admin.appearance.themes.store'), ['name' => 'Hostile', 'tokens' => $tokens])
        ->assertSessionHasErrors("tokens.{$group}.{$token}");

    expect(Theme::query()->count())->toBe(0);
})->with([
    'unknown token' => ['light', 'evil', '#FFFFFF'],
    'named colour' => ['light', 'primary', 'red'],
    'declaration break-out' => ['light', 'background', '#FFFFFF;}body{display:none'],
    'url()' => ['light', 'card', 'url(https://evil.test/a.png)'],
    'style close' => ['dark', 'foreground', '</style><script>alert(1)</script>'],
    'length break-out' => ['shared', 'radius', '1rem;background:url(x)'],
    'expression' => ['shared', 'card-p', 'expression(alert(1))'],
    'shadow injection' => ['light', 'elevation-card', '0 1px 2px #000000;} html{display:none'],
    'font outside allow-list' => ['shared', 'font-sans', 'comic-sans'],
    'mono font as sans' => ['shared', 'font-sans', 'geist-mono'],
    'weight' => ['shared', 'heading-weight', 950],
]);

it('bumps the version on update and renames without touching the key', function () {
    actingAsStaff(['themes.manage']);
    $theme = Theme::factory()->create(['name' => 'Old']);
    $tokens = $theme->tokens;
    $tokens['shared']['radius'] = '0.25rem';

    $this->put(route('admin.appearance.themes.update', $theme), ['name' => 'Old', 'tokens' => $tokens])->assertRedirect();
    $this->patch(route('admin.appearance.themes.rename', $theme), ['name' => 'New name'])->assertRedirect();

    $theme->refresh();
    expect($theme->version)->toBe(2)
        ->and($theme->name)->toBe('New name')
        ->and($theme->tokens['shared']['radius'])->toBe('0.25rem');
});

it('refreshes cached CSS when a theme in use is edited', function () {
    actingAsStaff(['themes.manage']);
    $theme = Theme::factory()->create();
    app(Settings::class)->set('themes.default_admin', $theme->key);

    $this->get(route('admin.dashboard'))->assertSee('--radius:0.5rem;', false);

    $tokens = $theme->tokens;
    $tokens['shared']['radius'] = '0.125rem';
    $this->put(route('admin.appearance.themes.update', $theme), ['name' => $theme->name, 'tokens' => $tokens]);

    $this->get(route('admin.dashboard'))->assertSee('--radius:0.125rem;', false);
});

it('deletes unused custom themes but not ones in use', function () {
    actingAsStaff(['themes.manage']);
    $unused = Theme::factory()->create();
    $areaDefault = Theme::factory()->create();
    $preferred = Theme::factory()->create();
    app(Settings::class)->set('themes.default_client', $areaDefault->key);
    User::factory()->staff()->create(['theme_preference' => null])->forceFill(['theme_preference' => $preferred->key])->save();

    $this->delete(route('admin.appearance.themes.destroy', $unused))->assertRedirect(route('admin.appearance.edit'));
    $this->delete(route('admin.appearance.themes.destroy', $areaDefault))->assertSessionHasErrors('theme');
    $this->delete(route('admin.appearance.themes.destroy', $preferred))->assertSessionHasErrors('theme');

    expect(Theme::query()->pluck('key')->all())->toEqualCanonicalizing([$areaDefault->key, $preferred->key]);
});

it('exports presets and custom themes as schema documents', function () {
    actingAsStaff(['themes.manage']);

    $this->get(route('admin.appearance.themes.export', 'operator'))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="theme-operator.json"')
        ->assertJsonPath('schema', 'billing-theme')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('tokens.shared.font-numeric', 'ibm-plex-mono');

    $this->get(route('admin.appearance.themes.export', 'missing'))->assertNotFound();
});

it('imports a valid theme document as a custom theme', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile(themeDocument(['name' => 'From JSON']))])
        ->assertRedirect();

    expect(Theme::query()->sole()->name)->toBe('From JSON');
});

it('round-trips an export through import', function () {
    actingAsStaff(['themes.manage']);
    $exported = $this->get(route('admin.appearance.themes.export', 'warm'))->json();

    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile($exported)])->assertRedirect();

    expect(Theme::query()->sole()->tokens)->toEqual(presetTokens('warm'));
});

it('rejects invalid imports', function (Closure $document) {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile($document())])
        ->assertSessionHasErrors('file');

    expect(Theme::query()->count())->toBe(0);
})->with([
    'unknown top-level key' => [fn () => themeDocument(['script' => 'x'])],
    'wrong schema' => [fn () => themeDocument(['schema' => 'other'])],
    'future version' => [fn () => themeDocument(['version' => 2])],
    'missing name' => [fn () => themeDocument(['name' => ''])],
    'unknown token' => [fn () => themeDocument(['tokens' => [...presetTokens(), 'light' => [...presetTokens()['light'], 'hack' => '#000000']]])],
    'css injection' => [fn () => themeDocument(['tokens' => [...presetTokens(), 'light' => [...presetTokens()['light'], 'ring' => '#000;}*{x:url(//evil)']]])],
    'contrast failure' => [fn () => themeDocument(['tokens' => [...presetTokens(), 'light' => [...presetTokens()['light'], 'muted-foreground' => '#FFFFFF']]])],
]);

it('rejects oversize and malformed import files', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.themes.import'), ['file' => UploadedFile::fake()->createWithContent('t.json', str_repeat(' ', 40_000).'{}')])
        ->assertSessionHasErrors('file');
    $this->post(route('admin.appearance.themes.import'), ['file' => UploadedFile::fake()->createWithContent('t.json', '{not json')])
        ->assertSessionHasErrors('file');
});

it('requires themes.manage for every custom theme action', function () {
    actingAsStaff(['core.clients.view']);
    $theme = Theme::factory()->create();

    $this->get(route('admin.appearance.themes.create'))->assertForbidden();
    $this->post(route('admin.appearance.themes.store'), ['name' => 'x', 'tokens' => presetTokens()])->assertForbidden();
    $this->get(route('admin.appearance.themes.edit', $theme))->assertForbidden();
    $this->put(route('admin.appearance.themes.update', $theme), ['name' => 'x', 'tokens' => presetTokens()])->assertForbidden();
    $this->delete(route('admin.appearance.themes.destroy', $theme))->assertForbidden();
    $this->get(route('admin.appearance.themes.export', 'calm'))->assertForbidden();
    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile(themeDocument())])->assertForbidden();
});

it('audits theme create, update, rename, import and delete', function () {
    actingAsStaff(['themes.manage']);
    $tokens = presetTokens();

    $this->post(route('admin.appearance.themes.store'), ['name' => 'Audited', 'base_preset' => 'calm', 'tokens' => $tokens]);
    $theme = Theme::query()->sole();
    $tokens['light']['primary'] = '#3F3AB0';
    $this->put(route('admin.appearance.themes.update', $theme), ['name' => 'Audited', 'tokens' => $tokens]);
    $this->patch(route('admin.appearance.themes.rename', $theme), ['name' => 'Renamed']);
    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile(themeDocument(['name' => 'Imported one']))]);
    $this->delete(route('admin.appearance.themes.destroy', $theme));

    $events = ActivityLog::query()->where('event', 'like', 'themes.theme_%')->pluck('event')->all();
    expect($events)->toEqualCanonicalizing(['themes.theme_created', 'themes.theme_updated', 'themes.theme_renamed', 'themes.theme_imported', 'themes.theme_deleted'])
        ->and(ActivityLog::query()->where('event', 'themes.theme_updated')->sole()->properties['changes']['light.primary'])->toBe(['from' => '#4F4BC4', 'to' => '#3F3AB0']);
});

it('rejects values with a trailing newline', function () {
    actingAsStaff(['themes.manage']);
    $document = themeDocument();
    $document['tokens']['light']['chart-grid'] .= "\n";

    $this->post(route('admin.appearance.themes.import'), ['file' => themeFile($document)])->assertSessionHasErrors('file');
});
