<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Storage::fake('objects'));

it('uploads a logo to the objects disk and serves it publicly with cache headers', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'logo-light'), [
        'file' => fakePng('logo.png', 400, 100),
    ])->assertRedirect();

    $path = app(Settings::class)->get('themes.logo_light');
    expect($path)->toStartWith('branding/logo-light-')->toEndWith('.png');
    Storage::disk('objects')->assertExists($path);

    expect(ActivityLog::query()->where('event', 'themes.brand_asset_uploaded')->sole()->properties['kind'])->toBe('logo-light');

    auth()->logout();
    $versioned = app(BrandAssets::class)->url('logo-light');
    $response = $this->get($versioned)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->getCookies())->toBe([]);

    // Unversioned or stale URLs redirect to the current version with a short cache, never immutable.
    foreach ([route('branding.show', 'logo-light'), route('branding.show', ['kind' => 'logo-light', 'v' => 'stale'])] as $url) {
        $redirect = $this->get($url)->assertRedirect($versioned);
        expect($redirect->headers->get('Cache-Control'))->not->toContain('immutable')
            ->and($redirect->headers->getCookies())->toBe([]);
    }

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('brand.logoLight', fn (string $url) => str_contains($url, '/branding/logo-light')));
});

it('replaces and removes brand assets', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'favicon'), ['file' => fakePng('a.png', 64, 64)]);
    $first = app(Settings::class)->get('themes.favicon');
    $this->post(route('admin.appearance.brand.store', 'favicon'), ['file' => fakePng('b.png', 32, 32)]);
    $second = app(Settings::class)->get('themes.favicon');

    expect($second)->not->toBe($first);
    Storage::disk('objects')->assertMissing($first);

    $this->get(route('admin.dashboard'))->assertSee('<link rel="icon" href="', false)->assertSee('/branding/favicon', false);

    $this->delete(route('admin.appearance.brand.destroy', 'favicon'))->assertRedirect();
    Storage::disk('objects')->assertMissing($second);
    $this->get(route('branding.show', 'favicon'))->assertNotFound();
    expect(ActivityLog::query()->where('event', 'themes.brand_asset_removed')->count())->toBe(1);
});

it('rejects svg, oversized and non-image uploads', function (string $kind, Closure $file) {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', $kind), ['file' => $file()])
        ->assertSessionHasErrors('file');

    expect(app(Settings::class)->get('themes.'.str_replace('-', '_', $kind)))->toBeNull();
})->with([
    'svg content named .png' => ['logo-light', fn () => UploadedFile::fake()->createWithContent('logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'svg logo' => ['logo-light', fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'svg favicon' => ['favicon', fn () => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'html renamed png' => ['logo-dark', fn () => UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>')],
    'too large' => ['logo-light', fn () => fakePng('big.png', 1200, 400, noise: true)],
    'too wide' => ['logo-light', fn () => fakePng('wide.png', 2400, 200)],
]);

it('requires themes.manage to upload', function () {
    actingAsStaff(['core.clients.view']);

    $this->post(route('admin.appearance.brand.store', 'logo-light'), ['file' => fakePng('logo.png', 100, 40)])
        ->assertForbidden();
});

it('shows a friendly error when object storage is unavailable', function () {
    actingAsStaff(['themes.manage']);
    $mock = Mockery::mock(BrandAssets::class, [app(Settings::class)])->makePartial();
    $mock->shouldReceive('store')->andThrow(new RuntimeException('Unable to write file at location: PutObject'));
    app()->instance(BrandAssets::class, $mock);

    $this->post(route('admin.appearance.brand.store', 'logo-light'), ['file' => fakePng('logo.png', 200, 60)])
        ->assertSessionHasErrors(['file' => "Couldn't store the image. Check that object storage is running, then try again."]);
});
