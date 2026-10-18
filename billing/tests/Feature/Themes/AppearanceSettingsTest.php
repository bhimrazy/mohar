<?php

use App\Events\Core\SettingsUpdated;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

it('requires the themes.manage permission', function () {
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.appearance.edit'))->assertForbidden();
    $this->put(route('admin.appearance.update'), [])->assertForbidden();
});

it('keeps client users out of the appearance settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.appearance.edit'))->assertForbidden();
});

it('lists presets with usages and the current settings', function () {
    actingAsStaff(['themes.manage']);

    $this->get(route('admin.appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/appearance')
            ->where('settings.default_admin', 'calm')
            ->where('settings.allow_user_choice', true)
            ->has('themes', 4)
            ->where('themes.0.key', 'calm')
            ->where('themes.0.usages', ['Admin default', 'Client area default', 'Storefront default']));
});

it('saves per-area defaults, density and staff choice with an audit diff and event', function () {
    Event::fake([SettingsUpdated::class]);
    actingAsStaff(['themes.manage']);

    $this->put(route('admin.appearance.update'), [
        'default_admin' => 'operator',
        'default_client' => 'warm',
        'default_public' => 'slate',
        'density' => 'compact',
        'allow_user_choice' => '0',
    ])->assertRedirect(route('admin.appearance.edit'));

    $settings = app(Settings::class);
    expect($settings->get('themes.default_admin'))->toBe('operator')
        ->and($settings->get('themes.default_client'))->toBe('warm')
        ->and($settings->get('themes.default_public'))->toBe('slate')
        ->and($settings->get('themes.density'))->toBe('compact')
        ->and($settings->get('themes.allow_user_choice'))->toBeFalse();

    $log = ActivityLog::query()->where('event', 'themes.appearance_updated')->sole();
    expect($log->properties['changes']['themes.default_admin'])->toBe(['from' => 'calm', 'to' => 'operator']);
    Event::assertDispatched(SettingsUpdated::class, fn (SettingsUpdated $event): bool => in_array('themes.density', $event->keys, true));
});

it('rejects unknown themes and densities', function () {
    actingAsStaff(['themes.manage']);

    $this->put(route('admin.appearance.update'), [
        'default_admin' => 'does-not-exist',
        'default_client' => 'calm',
        'default_public' => 'calm',
        'density' => 'tiny',
        'allow_user_choice' => '1',
    ])->assertSessionHasErrors(['default_admin', 'density']);
});

it('renders each area with its own theme', function () {
    app(Settings::class)->setMany([
        'themes.default_admin' => 'operator',
        'themes.default_client' => 'warm',
        'themes.default_public' => 'slate',
    ]);

    $this->get(route('login'))
        ->assertSee('<style id="theme-tokens"', false)
        ->assertSee("--app-font-sans:var(--font-geist, 'Geist')", false)
        ->assertInertia(fn (Assert $page) => $page->where('theme.key', 'slate')->where('theme.area', 'public'));

    [, $client] = actingAsClient();
    $this->get(route('client.dashboard'))
        ->assertSee('--radius:0.75rem;', false)
        ->assertInertia(fn (Assert $page) => $page->where('theme.key', 'warm')->where('theme.canChoose', false));

    actingAsStaff();
    $this->get(route('admin.dashboard'))
        ->assertSee('--root-font-size:93.75%;', false)
        ->assertInertia(fn (Assert $page) => $page->where('theme.key', 'operator')->where('theme.area', 'admin'));
});

it('applies a staff member\'s own theme and density only when allowed', function () {
    $staff = actingAsStaff();

    $this->put(route('admin.appearance.preference'), ['theme' => 'warm', 'density' => 'compact'])->assertRedirect();
    expect($staff->refresh()->theme_preference)->toBe('warm');

    $this->get(route('admin.dashboard'))
        ->assertSee('--cell-py:0.4375rem;', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('theme.key', 'warm')
            ->where('theme.density', 'compact')
            ->where('theme.canChoose', true)
            ->has('theme.options', 4));

    app(Settings::class)->set('themes.allow_user_choice', false);

    $this->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('theme.key', 'calm')->where('theme.canChoose', false));
    $this->put(route('admin.appearance.preference'), ['theme' => 'slate'])->assertForbidden();
});

it('never lets client users set a theme preference', function () {
    actingAsClient();

    $this->put(route('admin.appearance.preference'), ['theme' => 'warm'])->assertForbidden();
});

it('falls back to the default preset when a configured theme disappears', function () {
    app(Settings::class)->set('themes.default_public', 'custom-gone-1234');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('theme.key', 'calm'));
});
