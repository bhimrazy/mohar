<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Platform\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a staff member is signed in as a client: enforce the time cap, block account-security
 * routes (password, email, 2FA, passkeys, login management) and share the banner prop.
 */
class HandleImpersonation
{
    /** Route-name patterns that are off limits while impersonating. */
    public const BLOCKED_ROUTES = [
        'profile.update',
        'profile.destroy',
        'security.edit',
        'user-password.update',
        'password.confirm*',
        'two-factor.*',
        'passkey*',
        'admin.*',
    ];

    public function __construct(private Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(Impersonation::SESSION_KEY)) {
            return $next($request);
        }

        $session = $this->impersonation->current($request);

        if ($session === null || $session->user_id !== $request->user()?->getKey()) {
            $request->session()->forget([Impersonation::SESSION_KEY, ActivityLogger::IMPERSONATOR_SESSION_KEY, ClientContext::SESSION_KEY]);

            return $next($request);
        }

        if ($this->impersonation->expiresAt($session)->isPast()) {
            $clientId = $this->impersonation->end($request, 'expired');
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The client session ended after :minutes minutes.', ['minutes' => $this->impersonation->maxMinutes()])]);

            return redirect()->route('admin.clients.show', $clientId);
        }

        $name = (string) $request->route()?->getName();

        if ($name === 'logout') {
            // Signing out ends the impersonation instead of logging the customer out of their devices.
            $clientId = $this->impersonation->end($request, 'logout');

            return redirect()->route('admin.clients.show', $clientId);
        }

        if ($name !== 'platform.impersonation.leave' && $this->blocked($name)) {
            abort(403, __('This action is not available while signed in as a client.'));
        }

        Inertia::share('impersonation', [
            'staff' => $session->staff->name,
            'user' => $session->user->email,
            'client' => $session->client->display_name,
            'expires_at' => $this->impersonation->expiresAt($session)->toIso8601String(),
        ]);

        return $next($request);
    }

    private function blocked(string $routeName): bool
    {
        foreach (self::BLOCKED_ROUTES as $pattern) {
            if (fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
