<?php

namespace App\Modules\Core\Support;

use App\Contracts\Branding\BrandProvider;
use App\Data\Branding\Brand;

/**
 * Fallback brand when the Themes module is not loaded: config('app.name') and the default
 * assets in public/brand.
 */
class DefaultBrandProvider implements BrandProvider
{
    public function brand(): Brand
    {
        return new Brand(
            name: (string) config('app.name'),
            tagline: (string) config('themes.tagline', ''),
            logoLight: asset('brand/logo.svg'),
            logoDark: asset('brand/logo-dark.svg'),
            mark: asset('brand/mark.svg'),
            favicon: asset('favicon.svg'),
        );
    }

    public function logoDataUri(bool $dark = false): ?string
    {
        $path = public_path($dark ? 'brand/logo-dark.svg' : 'brand/logo.svg');

        return is_file($path) ? 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    public function emailLogoUrl(): ?string
    {
        return is_file(public_path('brand/logo.png')) ? asset('brand/logo.png') : null;
    }
}
