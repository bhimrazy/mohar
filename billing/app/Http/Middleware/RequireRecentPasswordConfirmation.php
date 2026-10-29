<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
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
 * JSON clients get 423, Inertia gets a "password_confirmation" validation error (redirected back without the old
 * input, so no submitted secret reaches the session) that the UI turns into a confirm-password dialog before retrying.
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

    /** Machine-readable reason for a refusal because the login has no password (no secret data). */
    public const NO_PASSWORD_CODE = 'password_not_set';

    /** Validation error key that carries NO_PASSWORD_CODE for Inertia clients. */
    public const REASON_KEY = 'step_up_reason';

    public const PROBE_HEADER = 'X-Step-Up-Check';

    /** Session key holding the file URL a download sent to the confirm page. */
    public const DOWNLOAD_KEY = 'step_up_download';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $safe = $request->isMethodSafe();
        $confirmed = self::recentlyConfirmed($request);

        if ($safe && $request->hasHeader(self::PROBE_HEADER)) {
            return $confirmed ? response()->noContent() : $this->locked($request);
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
            return $this->locked($request);
        }

        if ($safe) {
            return $this->toConfirmPage();
        }

        // Back with the error only, never the old input: this runs before any form request that keeps secrets out of
        // the session, and the dialog replays the visit itself (security standards §6).
        return back()->withErrors(
            $this->hasNoPassword($request)
                ? [self::ERROR_KEY => __('Your account has no password. Set a password to continue.'), self::REASON_KEY => self::NO_PASSWORD_CODE]
                : [self::ERROR_KEY => __('Confirm your password to continue.')],
        );
    }

    private function hasNoPassword(Request $request): bool
    {
        return $request->user() !== null && ! $request->user()->hasPassword();
    }

    private function locked(Request $request): Response
    {
        if ($this->hasNoPassword($request)) {
            return response()->json(['message' => __('Your account has no password. Set a password to continue.'), 'code' => self::NO_PASSWORD_CODE], 423);
        }

        abort(423, __('Password confirmation required.'));
    }

    /**
     * The confirm page labels itself a download only while the remembered URL is still this file (#305): the session
     * keeps the URL, not a yes/no that a later, unrelated confirm page would inherit.
     */
    private function toConfirmPage(): Response
    {
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Confirm your password to download this file.')]);
        $redirect = redirect()->guest(route('password.confirm'));
        session()->put(self::DOWNLOAD_KEY, session('url.intended'));

        return $redirect;
    }

    /**
     * Whether the remembered URL is the download that sent the person to the confirm page.
     */
    public static function confirmingDownload(Request $request): bool
    {
        $download = $request->session()->get(self::DOWNLOAD_KEY);

        return is_string($download) && $download === $request->session()->get('url.intended');
    }

    /**
     * A login without a password is never confirmed, whatever the session holds: a passkey confirmation or a
     * stale timestamp must not stand in for a password it does not have (ADR 0018 §4).
     */
    public static function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

        if ($request->user() !== null) {
            return self::confirmedFor($request->user(), $confirmedAt);
        }

        return time() - $confirmedAt < self::timeout();
    }

    /**
     * The same rule for a stored user and the timestamp of their confirmation, for work with no request (a queued
     * export, #354). A login without a password or with an expired one-time password is never confirmed.
     */
    public static function confirmedFor(User $user, int $confirmedAt): bool
    {
        // An expired one-time password no longer confirms anything, even in a session opened before it expired.
        if (! $user->hasPassword() || $user->passwordExpired()) {
            return false;
        }

        // A stored time is data from outside this request: zero, negative or in the future (beyond a minute of clock
        // skew) is never a confirmation.
        $now = time();

        return $confirmedAt > 0 && $confirmedAt <= $now + 60 && $now - $confirmedAt < self::timeout();
    }

    public static function timeout(): int
    {
        return (int) config('auth.step_up_seconds', 900);
    }
}
