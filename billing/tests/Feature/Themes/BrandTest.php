<?php

use App\Contracts\Branding\BrandProvider;
use App\Data\Branding\Brand;
use App\Modules\Core\Support\DefaultBrandProvider;
use App\Modules\Core\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('objects');
    config(['app.name' => 'Acme Billing']);
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
    app(Settings::class)->setMany(['core.company_name' => 'Himalaya Cloud', 'themes.tagline' => 'Hosting from the hills.']);

    expect(brand())
        ->name->toBe('Himalaya Cloud')
        ->tagline->toBe('Hosting from the hills.')
        ->logoLight->toBeNull()
        ->logoDark->toBeNull()
        ->customised->toBeTrue();

    $this->get(route('login'))
        ->assertSee('Himalaya Cloud</title>', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Himalaya Cloud')
            ->where('brand.name', 'Himalaya Cloud')
            ->where('brand.tagline', 'Hosting from the hills.')
            ->where('brand.logoLight', null));
});

it('prefers uploaded assets and serves them for PDFs and email', function () {
    actingAsStaff(['themes.manage']);

    $this->post(route('admin.appearance.brand.store', 'logo-light'), ['file' => fakePng('logo.png', 300, 80)])->assertRedirect();
    $this->post(route('admin.appearance.brand.store', 'mark'), ['file' => fakePng('mark.png', 128, 128)])->assertRedirect();

    $provider = app(BrandProvider::class);
    expect($provider->brand()->logoLight)->toContain('/branding/logo-light')
        ->and($provider->brand()->logoDark)->toBe($provider->brand()->logoLight)
        ->and($provider->brand()->mark)->toContain('/branding/mark')
        ->and($provider->emailLogoUrl())->toContain('/branding/logo-light')
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

it('resets uploads, name and tagline to the defaults', function () {
    actingAsStaff(['themes.manage']);
    app(Settings::class)->setMany(['core.company_name' => 'Other', 'themes.tagline' => 'Custom']);
    $this->post(route('admin.appearance.brand.store', 'favicon'), ['file' => fakePng('f.png', 32, 32)]);
    $favicon = app(Settings::class)->get('themes.favicon');

    $this->delete(route('admin.appearance.brand.reset'))->assertRedirect();

    Storage::disk('objects')->assertMissing($favicon);
    expect(brand())->name->toBe('Acme Billing')->customised->toBeFalse()->favicon->toBe(asset('favicon.svg'));
});

it('brands the mail layout header and footer', function () {
    app(Settings::class)->setMany(['core.company_name' => 'Himalaya Cloud', 'themes.tagline' => 'Hosting from the hills.']);

    $html = (string) (new MailMessage)->line('Your invoice is ready.')->render();

    expect($html)->toContain('Himalaya Cloud')
        ->toContain('Hosting from the hills.')
        ->not->toContain('Laravel');
});

it('uses the raster default logo in email for the default brand', function () {
    $html = (string) (new MailMessage)->line('Hello')->render();

    expect($html)->toContain(asset('brand/logo.png'));
});

it('falls back to config and public assets without the Themes module', function () {
    $brand = (new DefaultBrandProvider)->brand();

    expect($brand->name)->toBe('Acme Billing')
        ->and($brand->mark)->toBe(asset('brand/mark.svg'));
});

it('lets staff with themes.manage edit the tagline', function () {
    actingAsStaff(['themes.manage']);

    $this->put(route('admin.appearance.update'), [
        'company_name' => 'Acme Billing',
        'tagline' => 'Paid on time.',
        'default_admin' => 'calm',
        'default_client' => 'calm',
        'default_public' => 'calm',
        'density' => 'theme',
        'allow_user_choice' => '1',
    ])->assertRedirect();

    expect(brand()->tagline)->toBe('Paid on time.');
});
