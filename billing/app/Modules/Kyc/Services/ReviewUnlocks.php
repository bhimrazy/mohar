<?php

namespace App\Modules\Kyc\Services;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Kyc\Models\KycReviewUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which clients' documents a staff member has opened, each until its own expiry (spec §7). One window for the unlock
 * and the password step-up: it ends at the earlier of 15 minutes after the unlock and the end of the step-up, so the
 * images never stop loading while the page still shows the documents.
 *
 * The state is a database row per staff member and client, bound to the session that opened it (the step-up is
 * per session too), not part of the session payload: a session is saved whole by every
 * request, so one that began before Hide documents would write the old unlock back (issue #258). Show and Hide change
 * the row under a lock (ADR 0021), and a Show built from an older page is refused by its generation.
 *
 * An ended entry stays until forgetEnded() notices it, once, so the browser history that still holds the numbers is
 * cleared exactly once (issue #240).
 */
class ReviewUnlocks
{
    public const MINUTES = 15;

    /** The end of a window opened now for this request's staff member, step-up included. */
    public function windowEnd(Request $request): CarbonImmutable
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $stepUpEnds = $confirmedAt + RequireRecentPasswordConfirmation::timeout();

        return CarbonImmutable::createFromTimestamp(min(CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp(), $stepUpEnds));
    }

    /**
     * What ties an unlock to one session: a keyed hash of the session id, never the id. Laravel makes a new id at
     * sign-in, so signing out and in again, or another browser of the same login, has a different one. A session id
     * that is regenerated while the session lives (a fixation guard) ends the unlock too: it fails closed.
     */
    public function binding(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->session()->getId(), (string) config('app.key'));
    }

    /**
     * Open one client's documents for the session $binding. $generation is what the page showed; when the row has
     * moved on (Hide, or a Show from another tab) nothing changes and this returns false.
     */
    public function open(int $userId, int $clientId, CarbonImmutable $until, int $generation, string $binding = ''): bool
    {
        return DB::transaction(function () use ($userId, $clientId, $until, $generation, $binding): bool {
            // Make sure the row exists (a no-op when it does), then take it: the lock is what orders Show and Hide.
            KycReviewUnlock::query()->insertOrIgnore(['user_id' => $userId, 'client_id' => $clientId, 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->firstOrFail();

            if ($row->generation !== $generation) {
                return false;
            }

            $row->forceFill(['generation' => $row->generation + 1, 'expires_at' => $until, 'session_hash' => $binding])->save();

            return true;
        });
    }

    /**
     * Hide one client's documents again before the window ends, in whichever session opened them. True when they were
     * open (or ended and not yet noticed). The conditional update waits for a Show that holds the row, then applies
     * on top of it.
     */
    public function close(int $userId, int $clientId): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->whereNotNull('expires_at')
            ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]) > 0;
    }

    /** Sign-out: every unlock of this staff member ends, whichever session opened it. */
    public function closeAll(int $userId): void
    {
        KycReviewUnlock::query()->where('user_id', $userId)->whereNotNull('expires_at')
            ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
    }

    public function until(int $userId, int $clientId, string $binding = ''): ?CarbonImmutable
    {
        $expires = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', $binding)->value('expires_at');
        $until = $expires === null ? null : CarbonImmutable::parse($expires);

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /** The generation a page shows and sends back with Show. */
    public function generation(int $userId, int $clientId): int
    {
        return (int) KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->value('generation');
    }

    /** Whole seconds left, counted by this server, so the browser never compares its own clock with ours. */
    public function remaining(?CarbonImmutable $until): ?int
    {
        return $until === null ? null : max(0, (int) ceil(now()->diffInSeconds($until, false)));
    }

    /**
     * Drop this session's ended unlocks. True when one had ended, so the caller clears the browser history. One
     * statement, so two requests noticing the same end cannot both be told. The generation is left alone: only Show
     * and Hide move it, so a page that was open when the end came can still press Show.
     */
    public function forgetEnded(int $userId, string $binding = ''): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->where('session_hash', $binding)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['expires_at' => null, 'updated_at' => now()]) > 0;
    }

    /** Whether this session has any entry, open or ended and not yet noticed. */
    public function any(int $userId, string $binding = ''): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->where('session_hash', $binding)->whereNotNull('expires_at')->exists();
    }

    /**
     * Compatibility for one release (remove in the release after next): the previous release kept unlocks in the
     * session under kyc.unlocks.<staff id> (client id => expiry). An entry whose time has passed is dropped, once,
     * and the caller clears the browser history that holds its numbers, as forgetEnded() does for rows.
     */
    public function forgetEndedLegacy(Request $request): bool
    {
        $key = $this->legacyKey($request);
        $entries = array_map('intval', (array) $request->session()->get($key, []));
        $open = array_filter($entries, fn (int $expires): bool => $expires > now()->getTimestamp());

        if (count($open) === count($entries)) {
            return false;
        }

        $this->storeLegacy($request, $key, $open);

        return true;
    }

    /** Compatibility for one release (remove with forgetEndedLegacy): Hide also ends a legacy session entry. */
    public function forgetLegacy(Request $request, int $clientId): void
    {
        $key = $this->legacyKey($request);
        $entries = (array) $request->session()->get($key, []);
        unset($entries[$clientId]);
        $this->storeLegacy($request, $key, $entries);
    }

    private function legacyKey(Request $request): string
    {
        return 'kyc.unlocks.'.(int) $request->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<int|string, mixed>  $entries
     */
    private function storeLegacy(Request $request, string $key, array $entries): void
    {
        if ($entries === []) {
            $request->session()->forget($key);
        } else {
            $request->session()->put($key, $entries);
        }
    }
}
