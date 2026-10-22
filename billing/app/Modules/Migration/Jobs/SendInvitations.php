<?php

namespace App\Modules\Migration\Jobs;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends the queued client-import invitations (client-import spec §5.7), install-wide, at most
 * migration.invite_per_hour in any hour and BATCH per job, then queues the next job (delayed until the hour has room).
 *
 * - One sender at a time: a cache lock (mutual exclusion between jobs, ADR 0021 §2). The hourly limit holds only when
 *   that lock is shared by every worker (database or Redis cache store; #260 checks the store). A job that finds the
 *   lock taken stops; the holder checks for queued rows after releasing it, so a row queued meanwhile is never left
 *   behind.
 * - One chain: next() dispatches only when no sender is already scheduled (a cache marker that lasts until the
 *   scheduled job is due, plus its timeout), so clicks and runs never start parallel chains.
 * - Each row is claimed with a conditional update (queued → sending), so a redelivered job never sends it twice. A
 *   send that throws marks its row failed with a reason, and the error goes to the job (it is retried for the rest).
 *   A row left `sending` by a sender that died is failed by the next sender, never queued again: its mail may have
 *   gone out.
 * - While invitations cannot be sent (ClientLoginInvitations::problem()), nothing is sent and no next job is queued;
 *   the rows stay queued and the page shows the reason.
 *
 * Queries: the throttle counts on (status, sent_at), the batch reads on (status, id), the stale sweep on
 * (status, id) with updated_at.
 */
final class SendInvitations implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const BATCH = 50;

    private const LOCK = 'migration.invitations.sender';

    private const SCHEDULED = 'migration.invitations.scheduled';

    public int $tries = 3;

    public int $timeout = Queues::IMPORT_JOB_TIMEOUT_SECONDS;

    public function __construct()
    {
        $this->onQueue(Queues::IMPORTS);
    }

    /**
     * Queues the sender in $delay seconds unless one is already scheduled.
     */
    public static function next(int $delay = 0): void
    {
        if (Cache::add(self::SCHEDULED, true, $delay + Queues::IMPORT_JOB_TIMEOUT_SECONDS + 30)) {
            dispatch(new self)->delay($delay);
        }
    }

    public function handle(ClientLoginInvitations $invitations, Settings $settings, ActivityLogger $audit): void
    {
        Cache::forget(self::SCHEDULED);
        $lock = Cache::lock(self::LOCK, MigrationInvitation::STALE_SECONDS);

        if (! $lock->get()) {
            return;
        }

        $perHour = max(1, (int) $settings->get('migration.invite_per_hour'));

        try {
            $this->failStale();

            if ($invitations->problem() !== null) {
                return;
            }

            $room = min(self::BATCH, $perHour - $this->sentInLastHour());

            if ($room > 0) {
                $this->sendBatch($invitations, $audit, $room);
            }
        } finally {
            $lock->release();
        }

        if (MigrationInvitation::query()->where('status', MigrationInvitation::QUEUED)->exists()) {
            self::next($this->sentInLastHour() >= $perHour ? $this->nextRoom() : 0);
        }
    }

    /**
     * Rows a dead sender left `sending`: older than the sender's lock, so no live sender holds them.
     */
    private function failStale(): void
    {
        MigrationInvitation::query()->where('status', MigrationInvitation::SENDING)->where('updated_at', '<', now()->subSeconds(MigrationInvitation::STALE_SECONDS))
            ->update(['status' => MigrationInvitation::FAILED, 'reason' => __('The sender stopped before this invitation was confirmed; it may have been sent. Send a new link from the client\'s logins if needed.'), 'updated_at' => now()]);
    }

    private function sendBatch(ClientLoginInvitations $invitations, ActivityLogger $audit, int $room): void
    {
        $tally = [];

        try {
            foreach (MigrationInvitation::query()->where('status', MigrationInvitation::QUEUED)->orderBy('id')->limit($room)->get() as $row) {
                // Links stopped working part way (the host check): leave the rest queued.
                if ($invitations->problem() !== null) {
                    break;
                }

                $claimed = MigrationInvitation::query()->whereKey($row->id)->where('status', MigrationInvitation::QUEUED)->update(['status' => MigrationInvitation::SENDING, 'updated_at' => now()]);

                if ($claimed !== 1) {
                    continue;
                }

                // The same login queued from two imports at once is invited once; a stale or failed row does not count.
                $duplicate = MigrationInvitation::query()->where('user_id', $row->user_id)->whereKeyNot($row->id)
                    ->where(fn ($query) => $query->where(fn ($query) => $query->where('status', MigrationInvitation::SENDING)->where('updated_at', '>=', now()->subSeconds(MigrationInvitation::STALE_SECONDS)))
                        ->orWhere(fn ($query) => $query->where('status', MigrationInvitation::SENT)->where('sent_at', '>=', $row->created_at)))
                    ->exists();

                try {
                    $outcome = $duplicate ? InvitationOutcome::Skipped : $invitations->invite($row->client_id, $row->user_id, InvitationPurpose::Import, $row->requested_by);
                } catch (Throwable $exception) {
                    $row->forceFill(['status' => MigrationInvitation::FAILED, 'reason' => __('Sending failed with an error. Send the invitations again to retry.')])->save();
                    $this->tally($tally, $row->migration_run_id, MigrationInvitation::FAILED);

                    throw $exception;
                }

                $row->forceFill([
                    'status' => $outcome->value,
                    'sent_at' => $outcome === InvitationOutcome::Sent ? now() : null,
                    'reason' => $outcome === InvitationOutcome::Refused ? __('The link failed the security check. Check APP_URL, then send again.') : null,
                ])->save();
                $this->tally($tally, $row->migration_run_id, $outcome->value);
            }
        } finally {
            foreach ($tally as $runId => $counts) {
                $run = MigrationRun::query()->find($runId);
                $audit->log('Client import invitations sent', $run, properties: ['file' => $run?->file_name, ...$counts], event: 'migration.invitations_sent');
            }
        }
    }

    /**
     * @param  array<int, array<string, int>>  $tally  run id => status => count
     */
    private function tally(array &$tally, int $runId, string $status): void
    {
        $tally[$runId][$status] = ($tally[$runId][$status] ?? 0) + 1;
    }

    private function sentInLastHour(): int
    {
        // A row being sent counts too, so a second sender can never pass the limit.
        return MigrationInvitation::query()->where('status', MigrationInvitation::SENT)->where('sent_at', '>', now()->subHour())->count()
            + MigrationInvitation::query()->where('status', MigrationInvitation::SENDING)->where('updated_at', '>=', now()->subSeconds(MigrationInvitation::STALE_SECONDS))->count();
    }

    /**
     * Seconds until the oldest invitation of the last hour leaves the window.
     */
    private function nextRoom(): int
    {
        $oldest = MigrationInvitation::query()->where('status', MigrationInvitation::SENT)->where('sent_at', '>', now()->subHour())->min('sent_at');

        return $oldest === null ? 0 : max(1, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse((string) $oldest)->addHour())) + 1);
    }
}
