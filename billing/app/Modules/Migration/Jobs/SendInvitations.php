<?php

namespace App\Modules\Migration\Jobs;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
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

/**
 * Sends the queued client-import invitations (client-import spec §5.7), install-wide, at most
 * migration.invite_per_hour in any hour and BATCH per job, then queues the next job (delayed until the hour has room).
 *
 * One sender at a time (a cache lock: mutual exclusion between jobs, ADR 0021 §2). A job that finds the lock taken
 * stops; the holder checks for queued rows after releasing it, so a row queued meanwhile is never left behind.
 * Queries: the throttle counts on (status, sent_at), the batch reads on (status, id). Each row is claimed with a
 * conditional update (queued → sending), so a redelivered job never sends it twice.
 */
final class SendInvitations implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const BATCH = 50;

    private const LOCK = 'migration.invitations.sender';

    public int $tries = 3;

    public int $timeout = Queues::IMPORT_JOB_TIMEOUT_SECONDS;

    public function __construct()
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(ClientLoginInvitations $invitations, Settings $settings, ActivityLogger $audit): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $perHour = max(1, (int) $settings->get('migration.invite_per_hour'));

        try {
            $room = min(self::BATCH, $perHour - $this->sentInLastHour());

            if ($room > 0) {
                $this->sendBatch($invitations, $audit, $room);
            }
        } finally {
            $lock->release();
        }

        if (MigrationInvitation::query()->where('status', MigrationInvitation::QUEUED)->exists()) {
            dispatch(new self)->delay($this->sentInLastHour() >= $perHour ? $this->nextRoom() : 0);
        }
    }

    private function sendBatch(ClientLoginInvitations $invitations, ActivityLogger $audit, int $room): void
    {
        $tally = [];

        foreach (MigrationInvitation::query()->where('status', MigrationInvitation::QUEUED)->orderBy('id')->limit($room)->get() as $row) {
            $claimed = MigrationInvitation::query()->whereKey($row->id)->where('status', MigrationInvitation::QUEUED)->update(['status' => MigrationInvitation::SENDING, 'updated_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            // The same login queued from two imports at once is invited once.
            $duplicate = MigrationInvitation::query()->where('user_id', $row->user_id)->whereKeyNot($row->id)
                ->where(fn ($query) => $query->where('status', MigrationInvitation::SENDING)->orWhere(fn ($query) => $query->where('status', MigrationInvitation::SENT)->where('sent_at', '>=', $row->created_at)))
                ->exists();
            $outcome = $duplicate ? InvitationOutcome::Skipped : $invitations->invite($row->client_id, $row->user_id, ClientLoginInvitations::TEMPLATE_MIGRATION, $row->requested_by);
            $row->forceFill(['status' => $outcome->value, 'sent_at' => $outcome === InvitationOutcome::Sent ? now() : null])->save();
            $tally[$row->migration_run_id][$outcome->value] = ($tally[$row->migration_run_id][$outcome->value] ?? 0) + 1;
        }

        foreach ($tally as $runId => $counts) {
            $run = MigrationRun::query()->find($runId);
            $audit->log('Client import invitations sent', $run, properties: ['file' => $run?->file_name, ...$counts], event: 'migration.invitations_sent');
        }
    }

    private function sentInLastHour(): int
    {
        return MigrationInvitation::query()->where('status', MigrationInvitation::SENT)->where('sent_at', '>', now()->subHour())->count();
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
