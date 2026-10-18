<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive mutations ("password.recent" middleware): the staff member must
 * have confirmed their password (Fortify confirm-password) within platform.password_confirmation_seconds.
 *
 * Unlike Laravel's RequirePassword (which redirects, and so cannot resume a POST), this rejects the request:
 * JSON clients get 423, Inertia gets a "password_confirmation" validation error that the UI turns into a
 * confirm-password dialog before retrying.
 */
class RequireRecentPasswordConfirmation
{
    public const ERROR_KEY = 'password_confirmation';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::recentlyConfirmed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(423, __('Password confirmation required.'));
        }

        throw ValidationException::withMessages([self::ERROR_KEY => __('Confirm your password to continue.')]);
    }

    public static function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

        return time() - $confirmedAt < self::timeout();
    }

    public static function timeout(): int
    {
        return (int) config('platform.password_confirmation_seconds', 900);
    }
}
