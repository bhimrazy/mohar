<?php

namespace App\Data\Branding;

/**
 * The product/company brand as configured in Admin → Settings → Appearance (falls back to
 * config('app.name') and the default assets in public/brand). URLs are absolute.
 */
final readonly class Brand
{
    public function __construct(
        public string $name,
        public string $tagline,
        /** Wordmark for light backgrounds; null when a renamed brand has no uploaded logo (render mark + name). */
        public ?string $logoLight,
        /** Wordmark for dark backgrounds; falls back to the light logo. */
        public ?string $logoDark,
        /** Square mark (app icon). */
        public string $mark,
        public string $favicon,
        /** True when the name, tagline or any asset differs from the defaults. */
        public bool $customised = false,
        /** Absolute URL of a raster (PNG/JPEG) logo for dark-mode email clients; null = show the name. */
        public ?string $emailLogoDark = null,
        /** The client theme's primary colour (#RRGGBB) for email buttons and links; null = mail default. */
        public ?string $accent = null,
        /** Text colour on the accent (#RRGGBB). */
        public ?string $accentForeground = null,
    ) {}

    /**
     * @return array{name: string, tagline: string, logoLight: string|null, logoDark: string|null, mark: string, favicon: string, customised: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tagline' => $this->tagline,
            'logoLight' => $this->logoLight,
            'logoDark' => $this->logoDark,
            'mark' => $this->mark,
            'favicon' => $this->favicon,
            'customised' => $this->customised,
        ];
    }
}
