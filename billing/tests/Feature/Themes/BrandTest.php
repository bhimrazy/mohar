<?php

use App\Contracts\Branding\BrandProvider;
use App\Data\Branding\Brand;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\DefaultBrandProvider;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('objects');
    config(['app.name' => 'Acme Billing', 'core.company_name' => 'Acme Billing']);
});

function brand(): Brand
{
    return app(BrandProvider::class)->brand();
}

it('defaults to the configured app name, tagline and shipped assets', function () {
    $brand = brand();

    expect($brand->name)->toBe('Acme Billing')
        ->and($brand->tagline)->toBe(config('themes.tagline'))
        ->and($brand->logoLight)->toBe(asset('brand/logo.svg'))
        ->and($brand->logoDark)->toBe(asset('brand/logo-dark.svg'))
        ->and($brand->mark)->toBe(asset('brand/mark.svg'))
        ->and($brand->favicon)->toBe(asset('favicon.svg'))
        ->and($brand->customised)->toBeFalse()
        ->and(app(BrandProvider::class)->logoDataUri())->toStartWith('data:image/svg+xml;base64,');
});

it('shows the mark and name for a renamed brand without its own logo', function () {
    app(Settings::class)->setMany(['core.company_name' => 'Example Hosting', 'themes.tagline' => 'Hosting from the hills.']);

    expect(brand())
        ->name->toBe('Example Hosting')
        ->tagline->toBe('Hosting from the hills.')
        ->logoLight->toBeNull()
        ->logoDark->toBeNull()
        ->customised->toBeTrue();

    $this->get(route('login'))
        ->assertSee('Example Hosting</title>', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Example Hosting')
            ->where('brand.name', 'Example Hosting')
            ->where('brand.tagline', 'Hosting from the hills.')
            ->where('brand.logoLight', null));
});

it('prefers uploaded assets and serves them for PDFs, with the mark for email', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'logo-light'), ['file' => fakePng('logo.png', 300, 80)])->assertRedirect();
    $this->post(route('admin.appearance.brand.store', 'mark'), ['file' => fakePng('mark.png', 128, 128)])->assertRedirect();

    $provider = app(BrandProvider::class);
    expect($provider->brand()->logoLight)->toContain('/branding/logo-light')
        ->and($provider->brand()->logoDark)->toBe($provider->brand()->logoLight)
        ->and($provider->brand()->mark)->toContain('/branding/mark')
        // The site logo is not the email logo: email uses the uploaded mark (raster) beside the name, or an opt-in email logo.
        ->and($provider->emailLogoUrl())->toBeNull()
        ->and($provider->brand()->emailMark)->toContain('/branding/mark')
        ->and($provider->logoDataUri())->toStartWith('data:image/png;base64,');
});

it('rejects a non-square mark and svg marks', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'mark'), ['file' => fakePng('wide.png', 200, 80)])
        ->assertSessionHasErrors('file');
    $this->post(route('admin.appearance.brand.store', 'mark'), ['file' => UploadedFile::fake()->createWithContent('m.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')])
        ->assertSessionHasErrors('file');

    expect(brand()->mark)->toBe(asset('brand/mark.svg'));
});

it('resets uploads and tagline but leaves Core\'s company name alone', function () {
    actingAsStaff(['themes.manage']);
    app(Settings::class)->setMany(['core.company_name' => 'Other', 'themes.tagline' => 'Custom']);
    $this->post(route('admin.appearance.brand.store', 'favicon'), ['file' => fakePng('f.png', 32, 32)]);
    $favicon = app(Settings::class)->get('themes.favicon');

    $this->delete(route('admin.appearance.brand.reset'))->assertRedirect();

    Storage::disk('objects')->assertMissing($favicon);
    expect(brand())->name->toBe('Other')->tagline->toBe(config('themes.tagline'))->favicon->toBe(asset('favicon.svg'))
        ->and(app(Settings::class)->get('core.company_name'))->toBe('Other')
        ->and(ActivityLog::query()->where('event', 'themes.brand_reset')->sole()->properties['removed'])->toBe(['favicon']);
});

it('brands the mail layout header and footer', function () {
    app(Settings::class)->setMany(['core.company_name' => 'Example Hosting', 'themes.tagline' => 'Hosting from the hills.']);

    $html = (string) (new MailMessage)->line('Your invoice is ready.')->render();

    expect($html)->toContain('Example Hosting')
        ->toContain('Hosting from the hills.')
        ->not->toContain('Laravel');
});

it('shows only the host brand in email: the name alone by default, no product mark or tagline', function () {
    app(Settings::class)->set('core.company_name', 'Example Hosting');

    $html = (string) (new MailMessage)->line('Hello')->render();

    expect(config('themes.tagline'))->toBe('')
        ->and($html)->toMatch('#class="tx-strong brand-name"[^>]*>Example Hosting</td>#')
        ->not->toContain('brand/mark', 'brand/logo', 'sealed and settled', ' · </');
});

it('accepts only a PNG or JPEG email logo on the server', function (string $name, string $content) {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'logo-email'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])
        ->assertSessionHasErrors(['file' => 'The email logo must be a PNG or JPEG image (other formats, including WebP and SVG, are not accepted).']);

    expect(app(BrandAssets::class)->path('logo-email'))->toBeNull();
})->with([
    'WebP' => ['logo.webp', base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=')],
    'SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'],
]);

it('uses the host\'s uploaded mark beside the name, and the opt-in email logo instead of both', function () {
    actingAsStaff(['themes.manage']);
    $this->post(route('admin.appearance.brand.store', 'mark'), ['file' => fakePng('mark.png', 128, 128)])->assertRedirect();

    expect((string) (new MailMessage)->line('Hello')->render())->toContain(route('branding.show', ['kind' => 'mark', 'v' => app(BrandAssets::class)->version('mark')]), 'brand-name');

    $this->post(route('admin.appearance.brand.store', 'logo-email'), ['file' => fakePng('logo.png', 300, 80)])->assertRedirect();
    $html = (string) (new MailMessage)->line('Hello')->render();

    expect($html)->toContain(route('branding.show', ['kind' => 'logo-email', 'v' => app(BrandAssets::class)->version('logo-email')]))
        ->not->toContain('brand-name');
});

it('falls back to config and public assets without the Themes module', function () {
    $brand = (new DefaultBrandProvider)->brand();

    expect($brand->name)->toBe('Acme Billing')
        ->and($brand->mark)->toBe(asset('brand/mark.svg'));
});

it('saves the tagline with its own form and audits it', function () {
    actingAsStaff(['themes.manage']);

    $this->put(route('admin.appearance.brand.update'), ['tagline' => 'Paid on time.'])->assertRedirect();

    expect(brand()->tagline)->toBe('Paid on time.')
        ->and(ActivityLog::query()->where('event', 'themes.brand_updated')->sole()->properties['changes']['themes.tagline']['to'])->toBe('Paid on time.');
});

it('shows the company name read-only and links to Core settings', function () {
    actingAsStaff(['themes.manage', 'core.settings.update']);
    app(Settings::class)->set('core.company_name', 'Example Hosting');

    $this->get(route('admin.appearance.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('companyName', 'Example Hosting')
        ->where('companySettingsUrl', fn (?string $url) => $url === null || str_contains($url, '/settings/company')));

    // The appearance form no longer writes Core's setting.
    $this->put(route('admin.appearance.update'), [
        'company_name' => 'Hijacked',
        'default_admin' => 'calm', 'default_client' => 'calm', 'default_public' => 'calm',
        'density' => 'theme', 'allow_user_choice' => '1',
    ]);
    expect(app(Settings::class)->get('core.company_name'))->toBe('Example Hosting');
});
