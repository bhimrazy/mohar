<?php

namespace App\Modules\Kyc\Http\Middleware;

use App\Modules\Kyc\Services\ReviewUnlocks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the encrypted browser history on the first request after a staff member's identity-document unlock ended,
 * whichever page that is, so Back cannot bring the numbers back (spec §7, issue #240). Logout, a forced sign-out and
 * the guest login pages are handled for every page by `App\Http\Middleware\ClearHistoryOnSignOut` (issue #250), which
 * also keeps hover prefetches from using up the one-time flag.
 */
class ClearEndedReviewHistory
{
    public function __construct(private ReviewUnlocks $unlocks) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Auth::check() && $request->header('Purpose') !== 'prefetch'
            && $this->unlocks->forgetEnded($request)) {
            Inertia::clearHistory();
        }

        return $next($request);
    }
}
