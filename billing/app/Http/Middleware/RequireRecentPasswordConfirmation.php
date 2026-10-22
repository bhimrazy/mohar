<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive mutations ("password.recent" middleware, ADR 0018 §4): the user must have
 * confirmed their password (Fortify confirm-password, which sets auth.password_confirmed_at) within
 * auth.step_up_seconds. It lives in the kernel and is aliased in bootstrap/app.php, so every module's sensitive
 * routes resolve whichever modules are installed (#81).
 *
 * Laravel's own `password.confirm` (RequirePassword) is not reused: it reads a different timestamp window
 * (`auth.password_timeout`), and ADR 0018 §4 makes `auth.step_up_seconds` the one step-up window.
 *
 * Unlike Laravel's RequirePassword (which redirects, and so cannot resume a POST), this rejects the request:
 * JSON clients get 423, Inertia gets a "password_confirmation" validation error that the UI turns into a
 * confirm-password dialog before retrying.
 *
 * Downloads (GET routes, #272) cannot use that dialog by themselves, so they have three paths, all of which keep the
 * rule that the file is never sent without a recent confirmation:
 * - a probe (`X-Step-Up-Check` header on a safe request) is answered here, 204 or 423, and never reaches the
 *   controller; the download link uses it to open the dialog first and then navigate to the file. It reports
 *   only whether this session's password confirmation is recent: a missing permission still shows as 403 when
 *   the link then navigates;
 * - a HEAD request is answered here too (204, or the confirm page) and never reaches the controller, so it
 *   cannot write a download audit row or build the file;
 * - a plain browser GET or HEAD is sent to the confirm-password page like Laravel's RequirePassword does, and
 *   returns to the file afterwards;
 * - an Inertia GET (the confirm page's own redirect back to the file, or a link followed by the router) is turned
 *   into a full page load of the same URL, so a file never streams into an XHR.
 */
class RequireRecentPasswordConfirmation
{
    public const ERROR_KEY = 'password_confirmation';

    public const PROBE_HEADER = 'X-Step-Up-Check';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $safe = $request->isMethodSafe();
        $confirmed = self::recentlyConfirmed($request);

        if ($safe && $request->hasHeader(self::PROBE_HEADER)) {
            return $confirmed ? response()->noContent() : abort(423, __('Password confirmation required.'));
        }

        if ($request->isMethod('HEAD')) {
            return $confirmed ? response()->noContent() : $this->toConfirmPage();
        }

        if ($safe && $request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        if ($confirmed) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(423, __('Password confirmation required.'));
        }

        if ($safe) {
            return $this->toConfirmPage();
        }

        throw ValidationException::withMessages([self::ERROR_KEY => __('Confirm your password to continue.')]);
    }

    private function toConfirmPage(): Response
    {
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Confirm your password to download this file.')]);
        session()->put('step_up_download', true);

        return redirect()->guest(route('password.confirm'));
    }

    public static function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

        return time() - $confirmedAt < self::timeout();
    }

    public static function timeout(): int
    {
        return (int) config('auth.step_up_seconds', 900);
    }
}
