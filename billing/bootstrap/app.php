<?php

use App\Http\Errors\BrandedErrorPage;
use App\Http\Middleware\ClearHistoryOnSignOut;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Core\Http\Middleware\EnsureStaff;
use App\Modules\Core\Http\Middleware\ResolveClientContext;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Reject requests for hosts other than APP_URL's (Laravel skips this check in local and in tests).
        $middleware->trustHosts();

        // Proxies come from config('trustedproxy.proxies') (TRUSTED_PROXIES), read per request so a cached config
        // works; passing `at:` here would read the environment before .env is loaded. Only the three headers the edge
        // proxy itself sets are honoured, and only from those addresses. X-Forwarded-Port is not: Caddy passes a
        // client's own copy through unchanged, and the port follows from the trusted scheme (443 for https).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'staff' => EnsureStaff::class,
            'client.area' => ResolveClientContext::class,
            // Step-up for sensitive mutations; here, not in a module, so it resolves with any module set (#81).
            'password.recent' => RequireRecentPasswordConfirmation::class,
        ]);

        // Global (not only "web") so 404s, auth redirects and error pages carry the headers too.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleAppearance::class,
            ClearHistoryOnSignOut::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A branded page for the errors a person in a browser can reach (App\Http\Errors\BrandedErrorPage).
        $exceptions->respond(new BrandedErrorPage);
    })->create();
