<?php

namespace App\Modules\Themes\Support;

use App\Contracts\Branding\BrandProvider;
use App\Data\Branding\Brand;
use App\Modules\Core\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The brand (BrandProvider): name and tagline from settings, logos/mark/favicon from uploads
 * with the defaults in public/brand as fallback.
 *
 * Uploads live on the private "objects" disk (shared across app servers, S3/RustFS in
 * production) and are served by BrandAssetController::show with long-lived cache headers;
 * the URL carries the content hash so a new upload busts caches.
 */
class BrandAssets implements BrandProvider
{
    public const KINDS = ['logo-light', 'logo-dark', 'mark', 'favicon'];

    public const DISK = 'objects';

    private const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'];

    public function __construct(private Settings $settings) {}

    public function brand(): Brand
    {
        $name = $this->name();
        $default = $name === $this->defaultName();
        $logoLight = $this->url('logo-light') ?? ($default ? $this->defaultUrl('logo-light') : null);

        return new Brand(
            name: $name,
            tagline: $this->tagline(),
            logoLight: $logoLight,
            logoDark: $this->url('logo-dark') ?? ($this->path('logo-light') ? $logoLight : ($default ? $this->defaultUrl('logo-dark') : null)),
            mark: $this->url('mark') ?? $this->defaultUrl('mark'),
            favicon: $this->url('favicon') ?? $this->defaultUrl('favicon'),
            customised: ! $default || $this->tagline() !== $this->defaultTagline() || array_filter(array_map($this->path(...), self::KINDS)) !== [],
            emailLogoDark: $this->emailLogoDarkUrl(),
            accent: $this->accent('primary'),
            accentForeground: $this->accent('primary-foreground'),
        );
    }

    /**
     * A raster dark-mode logo for email: the uploaded dark logo when PNG/JPEG, else the default one.
     */
    public function emailLogoDarkUrl(): ?string
    {
        if (in_array(self::mime((string) $this->path('logo-dark')), ['image/png', 'image/jpeg'], true)) {
            return $this->url('logo-dark');
        }

        $default = (string) config('themes.defaults.logo-email-dark');

        return $this->path('logo-light') === null && $this->name() === $this->defaultName() && $default !== '' && is_file(public_path($default)) ? asset($default) : null;
    }

    /**
     * A light-mode colour token of the client area's theme (emails follow the client-facing brand).
     */
    private function accent(string $token): ?string
    {
        try {
            $value = app(ThemeManager::class)->resolve('client')->tokens['light'][$token] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }

    public function logoDataUri(bool $dark = false): ?string
    {
        foreach ($dark ? ['logo-dark', 'logo-light'] : ['logo-light'] as $kind) {
            if ($path = $this->path($kind)) {
                return $this->dataUri((string) Storage::disk(self::DISK)->get($path), $path);
            }
        }

        $brand = $this->brand();

        // A renamed brand without its own wordmark falls back to the mark.
        if (($dark ? $brand->logoDark : $brand->logoLight) === null) {
            if ($path = $this->path('mark')) {
                return $this->dataUri((string) Storage::disk(self::DISK)->get($path), $path);
            }

            $kind = 'mark';
        } else {
            $kind = $dark ? 'logo-dark' : 'logo-light';
        }

        $file = public_path($this->defaultPath($kind));

        return is_file($file) ? $this->dataUri((string) file_get_contents($file), $file) : null;
    }

    public function emailLogoUrl(): ?string
    {
        // Email clients render PNG/JPEG reliably; WebP and SVG often not.
        if (in_array(self::mime((string) $this->path('logo-light')), ['image/png', 'image/jpeg'], true)) {
            return $this->url('logo-light');
        }

        $default = (string) config('themes.defaults.logo-email');

        return $this->name() === $this->defaultName() && $default !== '' && is_file(public_path($default)) ? asset($default) : null;
    }

    public function name(): string
    {
        // Core owns this setting (Settings → Company); its config default is the product name.
        $name = $this->settings->get('core.company_name');

        return is_string($name) && trim($name) !== '' ? $name : $this->defaultName();
    }

    public function tagline(): string
    {
        $tagline = $this->settings->get('themes.tagline');

        return is_string($tagline) ? $tagline : $this->defaultTagline();
    }

    public function store(string $kind, UploadedFile $file): string
    {
        $hash = substr((string) hash_file('sha256', (string) $file->getRealPath()), 0, 16);
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = "branding/{$kind}-{$hash}.{$extension}";

        Storage::disk(self::DISK)->putFileAs('branding', $file, basename($path));
        $previous = $this->path($kind);
        $this->settings->set($this->settingKey($kind), $path);

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }

    public function delete(string $kind): void
    {
        if ($path = $this->path($kind)) {
            Storage::disk(self::DISK)->delete($path);
        }

        $this->settings->set($this->settingKey($kind), null);
    }

    /**
     * Remove every upload and the custom tagline (the company name is Core's setting).
     *
     * @return list<string> kinds that had an upload
     */
    public function reset(): array
    {
        $removed = array_values(array_filter(self::KINDS, fn (string $kind): bool => $this->path($kind) !== null));

        foreach ($removed as $kind) {
            $this->delete($kind);
        }

        $this->settings->set('themes.tagline', null);

        return $removed;
    }

    /**
     * The stored file's version (content hash), used to validate ?v= on /branding.
     */
    public function version(string $kind): ?string
    {
        $path = $this->path($kind);

        return $path === null ? null : pathinfo($path, PATHINFO_FILENAME);
    }

    public function path(string $kind): ?string
    {
        $path = $this->settings->get($this->settingKey($kind));

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function url(string $kind): ?string
    {
        $path = $this->path($kind);

        return $path === null ? null : route('branding.show', ['kind' => $kind, 'v' => $this->version($kind)]);
    }

    public static function mime(string $path): ?string
    {
        return self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    private function defaultName(): string
    {
        return (string) config('app.name');
    }

    private function defaultTagline(): string
    {
        return (string) config('themes.tagline', '');
    }

    private function defaultPath(string $kind): string
    {
        return (string) config("themes.defaults.{$kind}");
    }

    private function defaultUrl(string $kind): string
    {
        return asset($this->defaultPath($kind));
    }

    private function dataUri(string $contents, string $path): ?string
    {
        $mime = self::mime($path);

        return $mime === null ? null : "data:{$mime};base64,".base64_encode($contents);
    }

    private function settingKey(string $kind): string
    {
        return 'themes.'.str_replace('-', '_', $kind);
    }
}
