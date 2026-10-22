<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the browser's encrypted Inertia history whenever the person behind a tab stops being signed in, for staff and
 * clients alike (issue #250). Pages that call `Inertia::encryptHistory()` stay readable from Back or a restored tab
 * until the tab's key in `sessionStorage` is dropped, and only a response carrying `clearHistory` drops it.
 *
 * It covers:
 * - a request that ends or swaps the login (logout, account deletion, a deactivated login signed out by
 *   `EnsureActiveUser`, sign in as a client and back): the flag is set in the new session and reaches the next page;
 * - every guest view of the login and two-factor challenge pages, which is where a session that ended on the server
 *   (idle expiry, deleted session rows) leads, with nothing left in it to say why.
 *
 * A hover prefetch neither sets nor uses up the one-time flag: the browser may never show its response.
 */
class ClearHistoryOnSignOut
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        if ($request->header('Purpose') === 'prefetch') {
            return $this->withoutPendingFlag($request, $next);
        }

        $before = Auth::id();

        if ($before === null && $request->isMethod('GET') && $request->routeIs('login', 'two-factor.login')) {
            Inertia::clearHistory();
        }

        $response = $next($request);

        // Logout flushes the session, so the flag is set after $next, in the session that follows.
        if ($before !== null && Auth::id() !== $before) {
            Inertia::clearHistory();
        }

        return $response;
    }

    /**
     * Run a prefetch with any pending flag held back, then put the flag back for the next real visit.
     *
     * @param  Closure(Request): Response  $next
     */
    private function withoutPendingFlag(Request $request, Closure $next): Response
    {
        $pending = (bool) $request->session()->pull(SessionKey::CLEAR_HISTORY, false);
        $response = $next($request);

        if ($pending) {
            Inertia::clearHistory();
        }

        return $response;
    }
}
