<?php

namespace App\Modules\Kyc\Http\Middleware;

use App\Modules\Kyc\Services\ReviewUnlocks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the encrypted browser history so Back cannot bring identity-document numbers back (spec §7, issue #240):
 * - on the first request after a staff member's document unlock ended, whichever page that is;
 * - when the staff member who held an unlock is logged out during the request;
 * - on every guest view of the login and two-factor challenge pages, which is where a session that ended on the server
 *   (idle expiry, a deactivated account whose sessions were deleted) leads, with nothing left in it to say why.
 * Hover prefetches never set or use up the one-time flag: the browser may never show their response.
 */
class ClearEndedReviewHistory
{
    public function __construct(private ReviewUnlocks $unlocks) {}

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

        $holder = Auth::id();

        if ($holder === null) {
            if ($request->isMethod('GET') && $request->routeIs('login', 'two-factor.login')) {
                Inertia::clearHistory();
            }

            return $next($request);
        }

        if ($this->unlocks->forgetEnded($request)) {
            Inertia::clearHistory();
        }

        $held = $this->unlocks->any($request);
        $response = $next($request);

        // Logout flushes the session, so the flag goes into the new one and reaches the next page.
        if ($held && Auth::id() !== $holder) {
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
