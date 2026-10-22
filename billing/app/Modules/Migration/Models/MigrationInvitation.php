<?php

namespace App\Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One login staff asked to invite from a client import: queued → sending → sent, skipped, refused or unavailable
 * (the ClientLoginInvitations outcome), or failed (the send threw, or the sender stopped while it was sending). A
 * Held outcome (mail switched off) puts the row back to queued with its reason. Ids, a status and a fixed reason
 * only; the mail itself is in the email log.
 *
 * @property int $id
 * @property int $migration_run_id
 * @property int $client_id
 * @property int $user_id
 * @property string $status
 * @property string|null $reason why it was not sent (a fixed sentence), for failed and refused rows and held queued ones
 * @property int|null $requested_by
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['migration_run_id', 'client_id', 'user_id', 'status', 'reason', 'requested_by', 'sent_at'])]
class MigrationInvitation extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /**
     * Seconds after which a row still `sending` belongs to a sender that died (the sender's lock lasts this long): it
     * is failed, never queued again, because its mail may have gone out.
     */
    public const STALE_SECONDS = 150;

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
