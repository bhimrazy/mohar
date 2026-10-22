<?php

namespace App\Modules\Kyc\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Which clients' documents a staff member has opened, each until its own expiry (spec §7). One window for the unlock
 * and the password step-up: it ends at the earlier of 15 minutes after the unlock and the end of the step-up, so the
 * images never stop loading while the page still shows the documents.
 */
class ReviewUnlocks
{
    public const MINUTES = 15;

    public function unlock(Request $request, int $clientId): CarbonImmutable
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $stepUpEnds = $confirmedAt + (int) config('platform.password_confirmation_seconds', 900);
        $until = min(CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp(), $stepUpEnds);
        $unlocks = array_filter($this->all($request), fn (int $expires): bool => $expires > now()->getTimestamp());
        $unlocks[$clientId] = $until;
        $request->session()->put($this->key($request), $unlocks);

        return CarbonImmutable::createFromTimestamp($until);
    }

    public function until(Request $request, int $clientId): ?CarbonImmutable
    {
        $expires = $this->all($request)[$clientId] ?? 0;

        return $expires > now()->getTimestamp() ? CarbonImmutable::createFromTimestamp($expires) : null;
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
