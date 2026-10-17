<?php

namespace App\Modules\Themes\Support;

use App\Modules\Core\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Logo and favicon storage. Files live on the private "objects" disk (shared across app servers,
 * S3/RustFS in production) and are served by BrandAssetController::show with long-lived cache
 * headers; the URL carries a content hash so a new upload busts caches.
 */
class BrandAssets
{
    public const KINDS = ['logo-light', 'logo-dark', 'favicon'];

    public const DISK = 'objects';

    public function __construct(private Settings $settings) {}

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

    public function path(string $kind): ?string
    {
        $path = $this->settings->get($this->settingKey($kind));

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function url(string $kind): ?string
    {
        $path = $this->path($kind);

        if ($path === null) {
            return null;
        }

        return route('branding.show', ['kind' => $kind, 'v' => pathinfo($path, PATHINFO_FILENAME)]);
    }

    /**
     * @return array{name: string, logoLight: string|null, logoDark: string|null, favicon: string|null}
     */
    public function brand(): array
    {
        return [
            'name' => (string) $this->settings->get('core.company_name', config('app.name')),
            'logoLight' => $this->url('logo-light'),
            'logoDark' => $this->url('logo-dark'),
            'favicon' => $this->url('favicon'),
        ];
    }

    private function settingKey(string $kind): string
    {
        return 'themes.'.str_replace('-', '_', $kind);
    }
}
