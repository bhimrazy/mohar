<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\Support\Database\Factories\TicketMessageFactory;
use App\Modules\Support\Enums\AuthorType;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $ticket_id
 * @property MessageType $type
 * @property AuthorType $author_type
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property string $author_name
 * @property string|null $author_email
 * @property string $body
 * @property string $body_html
 * @property TicketSource $source
 * @property string|null $email_message_id
 * @property bool $is_autoresponse
 * @property bool $is_unverified_sender
 * @property int|null $rating
 * @property CarbonImmutable|null $edited_at
 * @property int|null $edited_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Ticket $ticket
 */
#[Table('support_ticket_messages')]
#[UseFactory(TicketMessageFactory::class)]
#[Fillable([
    'ticket_id', 'type', 'author_type', 'user_id', 'contact_id', 'author_name', 'author_email', 'body', 'body_html',
    'source', 'email_message_id', 'is_autoresponse', 'is_unverified_sender', 'rating', 'edited_at', 'edited_by',
    'created_at',
])]
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'author_type' => AuthorType::class,
            'source' => TicketSource::class,
            'is_autoresponse' => 'boolean',
            'is_unverified_sender' => 'boolean',
            'rating' => 'integer',
            'edited_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'message_id');
    }

    public function isNote(): bool
    {
        return $this->type === MessageType::Note;
    }

    public function isStaffReply(): bool
    {
        return $this->type === MessageType::Reply && $this->author_type === AuthorType::Staff && ! $this->is_autoresponse;
    }

    /**
     * Messages a client or guest may see (no internal notes).
     *
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('type', MessageType::Reply->value);
    }
}
