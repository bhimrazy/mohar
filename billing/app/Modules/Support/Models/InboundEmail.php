<?php

namespace App\Modules\Support\Models;

use App\Modules\Support\Enums\InboundEmailStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $provider
 * @property string $message_id
 * @property string $from
 * @property string|null $to
 * @property string|null $subject
 * @property InboundEmailStatus $status
 * @property string|null $reason
 * @property int|null $ticket_id
 * @property string|null $raw_path
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $created_at
 * @property-read Ticket|null $ticket
 */
#[Table('support_inbound_emails')]
#[Fillable(['provider', 'message_id', 'from', 'to', 'subject', 'status', 'reason', 'ticket_id', 'raw_path', 'received_at'])]
class InboundEmail extends Model
{
    protected function casts(): array
    {
        return [
            'status' => InboundEmailStatus::class,
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
