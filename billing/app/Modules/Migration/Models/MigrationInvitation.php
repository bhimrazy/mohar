<?php

namespace App\Modules\Migration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One login staff asked to invite from a client import: queued → sending → sent, skipped, refused or unavailable
 * (the ClientLoginInvitations outcome). Ids and a status only; the mail itself is in the email log.
 *
 * @property int $id
 * @property int $migration_run_id
 * @property int $client_id
 * @property int $user_id
 * @property string $status
 * @property int|null $requested_by
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['migration_run_id', 'client_id', 'user_id', 'status', 'requested_by', 'sent_at'])]
class MigrationInvitation extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    /** Rows still waiting for the sender: they are not queued a second time. */
    public const PENDING = [self::QUEUED, self::SENDING];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
