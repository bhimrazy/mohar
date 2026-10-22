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
 * The state is a database row per staff member and client, not part of the session: a session is saved whole by every
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
     * Open one client's documents. With $generation (what the page showed) the row must still be at it, else nothing
     * changes and this returns false. Without it the call is a deliberate new Show.
     */
    public function open(int $userId, int $clientId, CarbonImmutable $until, ?int $generation = null): bool
    {
        return DB::transaction(function () use ($userId, $clientId, $until, $generation): bool {
            // Make sure the row exists (a no-op when it does), then take it: the lock is what orders Show and Hide.
            KycReviewUnlock::query()->insertOrIgnore(['user_id' => $userId, 'client_id' => $clientId, 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->firstOrFail();

            if ($generation !== null && $row->generation !== $generation) {
                return false;
            }

            $row->forceFill(['generation' => $row->generation + 1, 'expires_at' => $until])->save();

            return true;
        });
    }

    /**
     * Hide one client's documents again before the window ends. True when they were open (or ended and not yet
     * noticed). The conditional update waits for a Show that holds the row, then applies on top of it.
     */
    public function close(int $userId, int $clientId): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->whereNotNull('expires_at')
            ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]) > 0;
    }

    public function until(int $userId, int $clientId): ?CarbonImmutable
    {
        $expires = KycReviewUnlock::query()->where('user_id', $userId)->where('client_id', $clientId)->value('expires_at');
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
     * Drop this staff member's ended unlocks. True when one had ended, so the caller clears the browser history. One
     * statement, so two requests noticing the same end cannot both be told.
     */
    public function forgetEnded(int $userId): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['expires_at' => null, 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]) > 0;
    }

    /** Whether this staff member has any entry, open or ended and not yet noticed. */
    public function any(int $userId): bool
    {
        return KycReviewUnlock::query()->where('user_id', $userId)->whereNotNull('expires_at')->exists();
    }
}
