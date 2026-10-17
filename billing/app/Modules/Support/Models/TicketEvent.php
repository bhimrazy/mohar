<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail entry for a ticket (spec §2 ticket_events).
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property string $actor client|guest|staff|system
 * @property string $type
 * @property string|null $from
 * @property string|null $to
 * @property array<string, mixed>|null $meta
 * @property int $version
 * @property CarbonImmutable|null $created_at
 */
#[Table('support_ticket_events')]
#[Fillable(['ticket_id', 'user_id', 'actor', 'type', 'from', 'to', 'meta', 'version'])]
class TicketEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['meta' => 'array', 'version' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
