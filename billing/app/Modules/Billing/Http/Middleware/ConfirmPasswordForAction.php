<?php

namespace App\Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fallback for the "password.recent" step-up middleware when the Platform module (which owns it) isn't installed,
 * e.g. MODULES=Billing test runs. Same contract: JSON clients get 423, Inertia gets a "password_confirmation"
 * validation error that the PasswordConfirmationGate turns into a confirm-password dialog before retrying.
 */
class ConfirmPasswordForAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

        if (time() - $confirmedAt < (int) config('platform.password_confirmation_seconds', 900)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(423, __('Password confirmation required.'));
        }

        throw ValidationException::withMessages(['password_confirmation' => __('Confirm your password to continue.')]);
    }
}
