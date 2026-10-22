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
 * An ended entry stays (also when another session ended it) until forgetEnded() of its own session notices it, once, so the browser history that still holds the numbers is
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
     * What ties an unlock to one session: a hash of the session id, never the id (not keyed with APP_KEY, so a key rotation leaves it valid). Laravel makes a new id at
     * sign-in, so signing out and in again, or another browser of the same login, has a different one. A session id
     * that is regenerated while the session lives (a fixation guard) ends the unlock too: it fails closed.
     */
    public function binding(Request $request): string
    {
        return hash('sha256', (string) $request->session()->getId());
    }

    /**
     * Open one client's documents for the session $binding (each session has its own row, so another session's Show or end does not touch this one). $generation is what the page showed; when the row has
     * moved on (Hide, or a Show from another tab) nothing changes and this returns false.
     */
    public function open(int $userId, int $clientId, CarbonImmutable $until, int $generation, string $binding = ''): bool
    {
        return DB::transaction(function () use ($userId, $clientId, $until, $generation, $binding): bool {
            // Make sure the row exists (a no-op when it does), then take it: the lock is what orders Show and Hide.
            $this->dropAbandoned($userId);
            KycReviewUnlock::query()->insertOrIgnore(['user_id' => $userId, 'client_id' => $clientId, 'session_hash' => $binding, 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', $binding)->lockForUpdate()->firstOrFail();

            if ($row->generation !== $generation) {
                return false;
            }

            $row->forceFill(['generation' => $row->generation + 1, 'expires_at' => $until])->save();

            return true;
        });
    }

    /**
     * Hide one client's documents again. This session's row is closed; every other session of the same login that
     * still has them open gets its row marked ended (not deleted), so its next request notices the end and clears its
     * browser history, once. True when anything was open. The conditional update on this row waits for a Show that
     * holds it, then applies on top.
     */
    public function close(int $userId, int $clientId, string $binding = ''): bool
    {
        return DB::transaction(function () use ($userId, $clientId, $binding): bool {
            // Every row this touches, locked in one query in id order (ADR 0021 §3): two sessions ending each other's
            // rows cannot take them in opposite orders.
            KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->orderBy('id')->lockForUpdate()->pluck('id');
            $own = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', $binding)->whereNotNull('expires_at')
                ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
            $others = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', '!=', $binding)->where('expires_at', '>', now())
                ->update(['expires_at' => now(), 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);

            return $own + $others > 0;
        });
    }

    /**
     * Sign-out of one session: its own rows are closed (the sign-out response clears its history), and every other
     * session of the login that has documents open has them marked ended, as close() does.
     */
    public function closeAll(int $userId, string $binding = ''): void
    {
        DB::transaction(function () use ($userId, $binding): void {
            KycReviewUnlock::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->pluck('id');
            KycReviewUnlock::query()->where('user_id', $userId)->where('session_hash', $binding)->whereNotNull('expires_at')
                ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
            KycReviewUnlock::query()->where('user_id', $userId)->where('session_hash', '!=', $binding)->where('expires_at', '>', now())
                ->update(['expires_at' => now(), 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
        });
    }

    /**
     * Drop this user's rows that no live session can still use: untouched for longer than a session lasts (plus an
     * hour), so they were never signed out. A row that is open (at most 15 minutes) or ended and still to be noticed
     * is far younger. Locked in id order, skipping rows another request holds.
     */
    private function dropAbandoned(int $userId): void
    {
        $cutoff = now()->subMinutes((int) config('session.lifetime') + 60);
        $ids = KycReviewUnlock::query()->where('user_id', $userId)->where('updated_at', '<', $cutoff)->orderBy('id')->lock('for update skip locked')->pluck('id');

        if ($ids->isNotEmpty()) {
            KycReviewUnlock::query()->whereKey($ids->all())->delete();
        }
    }

    public function until(int $userId, int $clientId, string $binding = ''): ?CarbonImmutable
    {
        $expires = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', $binding)->value('expires_at');
        $until = $expires === null ? null : CarbonImmutable::parse($expires);

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /** The generation a page shows and sends back with Show. */
    public function generation(int $userId, int $clientId, string $binding = ''): int
    {
        return (int) KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->where('session_hash', $binding)->value('generation');
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
