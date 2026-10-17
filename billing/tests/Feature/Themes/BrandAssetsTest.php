<?php

use App\Modules\Core\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Storage::fake('objects'));

it('uploads a logo to the objects disk and serves it publicly with cache headers', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.settings.appearance.brand.store', 'logo-light'), [
        'file' => fakePng('logo.png', 400, 100),
    ])->assertRedirect();

    $path = app(Settings::class)->get('themes.logo_light');
    expect($path)->toStartWith('branding/logo-light-')->toEndWith('.png');
    Storage::disk('objects')->assertExists($path);

    auth()->logout();
    $this->get(route('branding.show', 'logo-light'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'");

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('brand.logoLight', fn (string $url) => str_contains($url, '/branding/logo-light')));
});

it('replaces and removes brand assets', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.settings.appearance.brand.store', 'favicon'), ['file' => fakePng('a.png', 64, 64)]);
    $first = app(Settings::class)->get('themes.favicon');
    $this->post(route('admin.settings.appearance.brand.store', 'favicon'), ['file' => fakePng('b.png', 32, 32)]);
    $second = app(Settings::class)->get('themes.favicon');

    expect($second)->not->toBe($first);
    Storage::disk('objects')->assertMissing($first);

    $this->get(route('admin.dashboard'))->assertSee('<link rel="icon" href="', false)->assertSee('/branding/favicon', false);

    $this->delete(route('admin.settings.appearance.brand.destroy', 'favicon'))->assertRedirect();
    Storage::disk('objects')->assertMissing($second);
    $this->get(route('branding.show', 'favicon'))->assertNotFound();
});

it('rejects svg, oversized and non-image uploads', function (string $kind, Closure $file) {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.settings.appearance.brand.store', $kind), ['file' => $file()])
        ->assertSessionHasErrors('file');

    expect(app(Settings::class)->get('themes.'.str_replace('-', '_', $kind)))->toBeNull();
})->with([
    'svg logo' => ['logo-light', fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'svg favicon' => ['favicon', fn () => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'html renamed png' => ['logo-dark', fn () => UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>')],
    'too large' => ['logo-light', fn () => fakePng('big.png', 1200, 400, noise: true)],
    'too wide' => ['logo-light', fn () => fakePng('wide.png', 2400, 200)],
]);

it('requires themes.manage to upload', function () {
    actingAsStaff(['core.clients.view']);

    $this->post(route('admin.settings.appearance.brand.store', 'logo-light'), ['file' => fakePng('logo.png', 100, 40)])
        ->assertForbidden();
});
