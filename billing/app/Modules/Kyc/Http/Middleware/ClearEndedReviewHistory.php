<?php

namespace App\Modules\Kyc\Http\Middleware;

use App\Modules\Kyc\Services\ReviewUnlocks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the encrypted browser history once a staff member's document unlock has ended (spec §7, issue #240), on the
 * first request after it ended, whichever page that is, so Back cannot bring the numbers back. Also when the staff
 * member who held an unlock logs out (or is logged out) during the request.
 */
class ClearEndedReviewHistory
{
    public function __construct(private ReviewUnlocks $unlocks) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $holder = $request->hasSession() ? Auth::id() : null;

        // A hover prefetch would take the one-time flag into a response the browser may never use; wait for a visit.
        if ($holder === null || $request->header('Purpose') === 'prefetch') {
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
}
