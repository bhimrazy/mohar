<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Themes\Http\Requests\BrandAssetRequest;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BrandAssetController extends Controller
{
    public function __construct(private BrandAssets $assets, private ActivityLogger $activity) {}

    public function store(BrandAssetRequest $request, string $kind): RedirectResponse
    {
        $file = $request->file('file');
        abort_unless($file !== null && ! is_array($file), 422);

        try {
            $path = $this->assets->store($kind, $file);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['file' => __("Couldn't store the image. Check that object storage is running, then try again.")]);
        }

        $this->activity->log('Brand image uploaded', properties: ['kind' => $kind, 'path' => $path, 'size' => $file->getSize()], event: 'themes.brand_asset_uploaded');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image uploaded.')]);

        return back();
    }

    /**
     * Back to the shipped brand: removes every upload and the custom tagline.
     */
    public function reset(): RedirectResponse
    {
        $this->authorize('themes.manage');

        try {
            $removed = $this->assets->reset();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['brand' => __("Couldn't reach object storage to remove the images. Try again.")]);
        }

        $this->activity->log('Brand reset to default', properties: ['removed' => $removed], event: 'themes.brand_reset');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand reset to the default.')]);

        return back();
    }

    public function destroy(string $kind): RedirectResponse
    {
        $this->authorize('themes.manage');

        try {
            $this->assets->delete($kind);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['file' => __("Couldn't reach object storage to remove the image. Try again.")]);
        }

        $this->activity->log('Brand image removed', properties: ['kind' => $kind], event: 'themes.brand_asset_removed');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image removed.')]);

        return back();
    }

    /**
     * Public: logos and favicon appear on login pages, emails and the storefront. Registered
     * outside the "web" group (no session, cookies or CSRF). Only the current version (?v=content
     * hash) is cached as immutable; anything else redirects to it with a short cache.
     */
    public function show(Request $request, string $kind): Response
    {
        $path = $this->assets->path($kind);
        $version = $this->assets->version($kind);
        abort_if($path === null || $version === null, 404);

        if ($request->query('v') !== $version) {
            return redirect()->to((string) $this->assets->url($kind), 302)
                ->setPublic()->setMaxAge(300);
        }

        $type = BrandAssets::mime($path) ?? abort(404);

        try {
            $contents = Storage::disk(BrandAssets::DISK)->get($path);
        } catch (Throwable) {
            abort(404);
        }

        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
