<?php

namespace App\Http\Errors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replaces the framework's bare error page with the host's branded one for people in a browser.
 *
 * Only a request that names text/html in Accept gets it, and not the routes listed below: images and attachments
 * embedded in pages and machine callers keep the framework response (a browser 403 on a KYC image must not consume the
 * one-time clearHistory flag).
 */
final class BrandedErrorPage
{
    public const STATUSES = [403, 404, 419, 429, 500];

    /** Routes called by providers or scripts, never by a person; they always get the plain response. */
    public const MACHINE_ROUTES = ['billing.webhooks', 'billing.sandbox.tokens', 'support.inbound.email'];

    /** Routes embedded in pages (images, attachments); a browser error there is not a page the person navigated to. */
    public const EMBEDDED_ROUTES = [
        'admin.kyc.image',
        'admin.support.attachments.show',
        'client.support.tickets.attachments.show',
        'support.tickets.attachments.show',
    ];

    /** Headers of the original response that still apply to the branded one. */
    private const KEEP_HEADERS = ['retry-after', 'allow', 'www-authenticate'];

    public function __invoke(Response $response, Throwable $e, Request $request): Response
    {
        if (! $this->applies($response->getStatusCode(), $request)) {
            return $response;
        }

        try {
            $branded = Inertia::render('auth/error', [
                'status' => $response->getStatusCode(),
                'home' => $this->home($request),
            ])->toResponse($request)->setStatusCode($response->getStatusCode());

            foreach ($response->headers->all() as $name => $values) {
                if (in_array($name, self::KEEP_HEADERS, true) || str_starts_with($name, 'x-ratelimit-')) {
                    $branded->headers->set($name, $values);
                }
            }

            return $branded;
        } catch (Throwable $renderFailure) {
            report($renderFailure);

            return $response;
        }
    }

    private function applies(int $status, Request $request): bool
    {
        return in_array($status, self::STATUSES, true)
            && $request->hasSession()
            && ! $request->expectsJson()
            && ! $request->is('api/*')
            && str_contains(strtolower((string) $request->header('Accept')), 'text/html')
            && ! in_array($request->route()?->getName(), [...self::MACHINE_ROUTES, ...self::EMBEDDED_ROUTES], true)
            // A failed form submit keeps the framework response (a rendered page would consume its flashed errors);
            // only an expired page, which needs a reload, is answered for a submit.
            && ($request->isMethod('GET') || $status === 419)
            && ($status !== 404 || $request->is('admin', 'admin/*', 'client', 'client/*'))
            && ! ($status === 500 && config('app.debug'));
    }

    /** Where "Go to the home page" leads: the area the person was in. */
    private function home(Request $request): string
    {
        $user = $request->user();
        $name = match (true) {
            $user === null || ! $request->is('admin', 'admin/*', 'client', 'client/*') => 'home',
            $user->is_staff && $request->is('admin', 'admin/*') => 'admin.dashboard',
            default => 'client.dashboard',
        };

        return Route::has($name) ? route($name, absolute: false) : '/';
    }
}
