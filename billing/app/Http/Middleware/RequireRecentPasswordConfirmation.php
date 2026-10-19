<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive mutations ("password.recent", ADR 0018 §4): the only step-up mechanism. The
 * user must have confirmed their password (Fortify confirm-password, which alone sets auth.password_confirmed_at)
 * within platform.password_confirmation_seconds. It lives in the kernel so every module's sensitive routes resolve
 * whichever modules are installed.
 *
 * Unlike Laravel's RequirePassword (which redirects, and so cannot resume a POST), this rejects the request: JSON
 * clients get 423, Inertia gets a "password_confirmation" validation error that the UI turns into a confirm-password
 * dialog before retrying. A login without a password cannot pass step-up: it gets "password_setup" as well, so the UI
 * offers "Set a password" instead of the dialog.
 */
class RequireRecentPasswordConfirmation
{
    public const ERROR_KEY = 'password_confirmation';

    /** Added next to ERROR_KEY when the login has no password to confirm. */
    public const SETUP_KEY = 'password_setup';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::recentlyConfirmed($request)) {
            return $next($request);
        }

        $passwordless = $request->user() instanceof User && ! $request->user()->hasPassword();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(423, $passwordless ? __('Set a password to continue.') : __('Password confirmation required.'));
        }

        throw ValidationException::withMessages(self::errors($request));
    }

    /**
     * The validation errors a refused step-up returns.
     *
     * @return array<string, string>
     */
    public static function errors(Request $request): array
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasPassword()) {
            return [self::ERROR_KEY => __('Set a password to continue.'), self::SETUP_KEY => __('This login has no password yet.')];
        }

        return [self::ERROR_KEY => __('Confirm your password to continue.')];
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
