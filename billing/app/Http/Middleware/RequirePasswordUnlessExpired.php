<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;

/**
 * Laravel's `password.confirm` (RequirePassword), bound in its place, with the step-up rule of `password.recent`:
 * a password confirmation (never a passkey one) within auth.step_up_seconds, refused for a login without a password
 * or with an expired one-time password (#459, ADR 0018 §4). Fortify puts it on passkey registration and removal and
 * on every two-factor route; the security page and the staff and role pages use it too.
 *
 * A page load (a safe, non-JSON request) still goes to the confirm page and back. Anything the page sends itself
 * (JSON, or an Inertia form) gets `password.recent`'s answer, a 423 or a `password_confirmation` error, so the
 * confirm dialog asks and the step is retried in place (#538). The route arguments (redirect route and timeout) are
 * not used: there is one confirm page and one window.
 */
class RequirePasswordUnlessExpired extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            return parent::handle($request, $next);
        }

        return app(RequireRecentPasswordConfirmation::class)->handle($request, $next);
    }

    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null)
    {
        return ! RequireRecentPasswordConfirmation::recentlyConfirmed($request);
    }
}
