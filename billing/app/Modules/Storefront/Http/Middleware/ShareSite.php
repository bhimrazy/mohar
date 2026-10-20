<?php

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Storefront\Services\SiteData;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the public layout's data ("site") with Inertia on public routes only (storefront.public_routes), so admin
 * and client-area pages never pay for it. The prop is lazy: plain redirects and JSON responses never build it.
 */
class ShareSite
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isPublic($request)) {
            Inertia::share('site', fn (): array => app(SiteData::class)->forRequest($request));
        }

        return $next($request);
    }

    public static function isPublic(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        foreach ((array) config('storefront.public_routes', []) as $pattern) {
            if ($name === $pattern || (str_ends_with((string) $pattern, '.') && str_starts_with($name, (string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
