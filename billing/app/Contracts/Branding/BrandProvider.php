<?php

namespace App\Contracts\Branding;

use App\Data\Branding\Brand;

/**
 * Single source of the product/company brand for pages, emails and PDFs.
 * Core binds a config-only fallback; the Themes module binds the settings-aware one.
 *
 *   $brand = app(BrandProvider::class)->brand();      // name, tagline, logo/mark URLs
 *   $src = app(BrandProvider::class)->logoDataUri();   // embed in a PDF header
 */
interface BrandProvider
{
    public function brand(): Brand;

    /**
     * The logo (or mark when no wordmark applies) as a data: URI for PDFs and inline email images.
     */
    public function logoDataUri(bool $dark = false): ?string;

    /**
     * An absolute URL to a raster logo for email clients (most don't render SVG); null = use the name.
     */
    public function emailLogoUrl(): ?string;
}
