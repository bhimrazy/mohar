<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Themes\Http\Requests\BrandAssetRequest;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BrandAssetController extends Controller
{
    public function __construct(private BrandAssets $assets) {}

    public function store(BrandAssetRequest $request, string $kind): RedirectResponse
    {
        $file = $request->file('file');
        abort_unless($file !== null && ! is_array($file), 422);

        $this->assets->store($kind, $file);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image uploaded.')]);

        return back();
    }

    /**
     * Back to the shipped brand: removes every upload and the custom name/tagline.
     */
    public function reset(): RedirectResponse
    {
        $this->authorize('themes.manage');

        $this->assets->reset();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand reset to the default.')]);

        return back();
    }

    public function destroy(string $kind): RedirectResponse
    {
        $this->authorize('themes.manage');

        $this->assets->delete($kind);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image removed.')]);

        return back();
    }

    /**
     * Public: logos and favicon appear on login pages and the storefront.
     */
    public function show(string $kind): Response
    {
        $path = $this->assets->path($kind);
        $disk = Storage::disk(BrandAssets::DISK);

        abort_if($path === null || ! $disk->exists($path), 404);

        $type = BrandAssets::mime($path) ?? abort(404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
