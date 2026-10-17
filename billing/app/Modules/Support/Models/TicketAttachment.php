<?php

namespace App\Modules\Support\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $stored_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable|null $pruned_at
 * @property CarbonImmutable|null $created_at
 * @property-read Ticket $ticket
 * @property-read TicketMessage|null $message
 */
#[Table('support_ticket_attachments')]
#[Fillable(['ticket_id', 'message_id', 'disk', 'path', 'original_name', 'stored_name', 'mime', 'size_bytes', 'sha256', 'pruned_at'])]
class TicketAttachment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'pruned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'message_id');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }
}
