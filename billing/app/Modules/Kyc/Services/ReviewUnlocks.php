<?php

namespace App\Modules\Kyc\Services;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Which clients' documents a staff member has opened, each until its own expiry (spec §7). One window for the unlock
 * and the password step-up: it ends at the earlier of 15 minutes after the unlock and the end of the step-up, so the
 * images never stop loading while the page still shows the documents.
 *
 * An ended entry stays in the session until forgetEnded() notices it, so the browser history that still holds the
 * numbers is cleared exactly once (issue #240).
 */
class ReviewUnlocks
{
    public const MINUTES = 15;

    public function unlock(Request $request, int $clientId): CarbonImmutable
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $stepUpEnds = $confirmedAt + RequireRecentPasswordConfirmation::timeout();
        $until = min(CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp(), $stepUpEnds);
        $unlocks = array_filter($this->all($request), fn (int $expires): bool => $expires > now()->getTimestamp());
        $unlocks[$clientId] = $until;
        $this->store($request, $unlocks);

        return CarbonImmutable::createFromTimestamp($until);
    }

    public function until(Request $request, int $clientId): ?CarbonImmutable
    {
        $expires = $this->all($request)[$clientId] ?? 0;

        return $expires > now()->getTimestamp() ? CarbonImmutable::createFromTimestamp($expires) : null;
    }

    /**
     * Hide one client's documents again before the window ends. True when they were open.
     */
    public function lock(Request $request, int $clientId): bool
    {
        $unlocks = $this->all($request);

        if (! array_key_exists($clientId, $unlocks)) {
            return false;
        }

        unset($unlocks[$clientId]);
        $this->store($request, $unlocks);

        return true;
    }

    /**
     * Drop this staff member's ended unlocks. True when one had ended, so the caller clears the browser history.
     */
    public function forgetEnded(Request $request): bool
    {
        $unlocks = $this->all($request);
        $open = array_filter($unlocks, fn (int $expires): bool => $expires > now()->getTimestamp());

        if (count($open) === count($unlocks)) {
            return false;
        }

        $this->store($request, $open);

        return true;
    }

    /** Whether this staff member has any entry, open or ended. */
    public function any(Request $request): bool
    {
        return $this->all($request) !== [];
    }

    /**
     * @param  array<int, int>  $unlocks
     */
    private function store(Request $request, array $unlocks): void
    {
        if ($unlocks === []) {
            $request->session()->forget($this->key($request));
        } else {
            $request->session()->put($this->key($request), $unlocks);
        }
    }

    /**
     * @return array<int, int>
     */
    private function all(Request $request): array
    {
        return array_map('intval', (array) $request->session()->get($this->key($request), []));
    }

    /** Keyed by the staff member too, so a session never carries another user's unlocks. */
    private function key(Request $request): string
    {
        return 'kyc.unlocks.'.(int) $request->user()?->getAuthIdentifier();
    }
}
