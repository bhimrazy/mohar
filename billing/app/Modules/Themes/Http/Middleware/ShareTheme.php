<?php

namespace App\Modules\Themes\Http\Middleware;

use App\Modules\Themes\Support\BrandAssets;
use App\Modules\Themes\Support\ThemeManager;
use App\Modules\Themes\Support\ThemeStylesheet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's theme: injects its CSS variables into the root Blade view
 * ($themeStyle, rendered in a nonce'd <style>) and shares theme/brand props with Inertia.
 */
class ShareTheme
{
    public function __construct(
        private ThemeManager $themes,
        private ThemeStylesheet $stylesheet,
        private BrandAssets $brand,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $area = ThemeManager::areaFor($request);
        $theme = $this->themes->resolve($area, $user, $this->previewOverride($request));
        $density = $this->themes->density($area, $user);
        $brand = $this->brand->brand();
        $canChoose = $area === 'admin' && $user?->is_staff && $this->themes->allowsUserChoice();

        View::share('themeStyle', $this->stylesheet->css($theme, $density));
        View::share('brandFavicon', $brand['favicon']);

        Inertia::share('brand', $brand);
        Inertia::share('theme', fn (): array => [
            'key' => $theme->key,
            'name' => $theme->name,
            'area' => $area,
            'density' => $density,
            'canChoose' => (bool) $canChoose,
            'preference' => $canChoose ? ['theme' => ThemeManager::preference($user, 'theme'), 'density' => ThemeManager::preference($user, 'density')] : null,
            'options' => $canChoose ? $this->themes->options() : [],
        ]);

        return $next($request);
    }

    /**
     * Local-only `?theme=` override for the dev design previews and screenshots.
     */
    private function previewOverride(Request $request): ?string
    {
        if (! app()->isLocal() || ! str_starts_with((string) $request->route()?->getName(), 'dev.')) {
            return null;
        }

        $key = $request->query('theme');

        return is_string($key) ? $key : null;
    }
}
