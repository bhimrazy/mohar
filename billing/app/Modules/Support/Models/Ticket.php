<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Support\Database\Factories\TicketFactory;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
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
 * @property string $number
 * @property string $access_key
 * @property int $department_id
 * @property int|null $client_id
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property string $requester_name
 * @property string $requester_email
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketSource $source
 * @property int|null $service_id
 * @property int|null $domain_id
 * @property int|null $assigned_to
 * @property list<string>|null $cc
 * @property list<string>|null $tags
 * @property bool $is_pinned
 * @property bool $prevent_client_close
 * @property bool $is_unverified_requester guest whose email matched a client account; not yet verified
 * @property int|null $merged_into_id
 * @property string|null $merged_access_key_hash
 * @property int $version
 * @property CarbonImmutable|null $first_staff_reply_at
 * @property CarbonImmutable|null $last_reply_at
 * @property CarbonImmutable|null $last_client_reply_at
 * @property CarbonImmutable|null $last_staff_reply_at
 * @property CarbonImmutable|null $first_unanswered_at oldest customer reply staff have not answered; null when none (queue order, spec §13)
 * @property CarbonImmutable|null $closed_at
 * @property ClosedBy|null $closed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Department $department
 * @property-read Client|null $client
 * @property-read User|null $assignee
 */
#[Table('support_tickets')]
#[UseFactory(TicketFactory::class)]
#[Fillable([
    'number', 'access_key', 'department_id', 'client_id', 'user_id', 'contact_id', 'requester_name', 'requester_email',
    'subject', 'status', 'priority', 'source', 'service_id', 'domain_id', 'assigned_to', 'cc', 'tags', 'is_pinned',
    'prevent_client_close', 'is_unverified_requester', 'merged_into_id', 'first_staff_reply_at', 'last_reply_at', 'last_client_reply_at',
    'last_staff_reply_at', 'first_unanswered_at', 'closed_at', 'closed_by',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /** Changes to these attributes bump `version` (collision detection, spec §4.2). */
    public const VERSIONED = ['status', 'priority', 'department_id', 'assigned_to', 'subject', 'cc', 'last_reply_at', 'client_id', 'merged_into_id'];

    protected $hidden = ['access_key', 'merged_access_key_hash'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'is_pinned' => false,
        'prevent_client_close' => false,
        'is_unverified_requester' => false,
        'source' => 'web',
        'priority' => 'medium',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'source' => TicketSource::class,
            'closed_by' => ClosedBy::class,
            'cc' => 'array',
            'tags' => 'array',
            'is_pinned' => 'boolean',
            'prevent_client_close' => 'boolean',
            'is_unverified_requester' => 'boolean',
            'version' => 'integer',
            'first_staff_reply_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'last_client_reply_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'first_unanswered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Ticket $ticket): void {
            if ($ticket->isDirty(self::VERSIONED)) {
                $ticket->version = $ticket->version + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'merged_into_id');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * @return HasMany<TicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /**
     * @return HasMany<InboundEmail, $this>
     */
    public function inboundEmails(): HasMany
    {
        return $this->hasMany(InboundEmail::class);
    }

    public function isGuest(): bool
    {
        return $this->client_id === null;
    }

    /**
     * Who the ticket belongs to: the client account, or for guest tickets the requester's email.
     * Two tickets are "the same requester" only when these match.
     */
    public function requesterIdentity(): string
    {
        return $this->client_id !== null ? 'client:'.$this->client_id : 'guest:'.mb_strtolower($this->requester_email);
    }

    /**
     * The ticket that finally receives replies (follows merge chains).
     */
    public function resolveMergeTarget(): self
    {
        $ticket = $this;
        $seen = [];

        while ($ticket->merged_into_id !== null && ! in_array($ticket->id, $seen, true)) {
            $seen[] = $ticket->id;
            $next = self::query()->find($ticket->merged_into_id);

            if ($next === null) {
                break;
            }

            $ticket = $next;
        }

        return $ticket;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNotMerged(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $statuses
     */
    public function scopeInStatuses(Builder $query, array $statuses): void
    {
        $query->whereIn('status', $statuses);
    }

    /**
     * Queue order for "awaiting reply": oldest *unanswered* customer message first, so repeated customer replies don't
     * bump a ticket (spec §13). Tickets without one sort last on every database. Matches the order of
     * support_tickets_awaiting_queue_index when preceded by `is_pinned desc` and followed by `id desc`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrderByOldestUnanswered(Builder $query): void
    {
        $query->orderByRaw('support_tickets.first_unanswered_at asc nulls last');
    }

    /**
     * The oldest customer reply after the last staff reply, read from the thread. The queue column is kept
     * incrementally; this recomputes it after a change the increments cannot follow (a merge, a deleted message).
     */
    public function oldestUnansweredReplyAt(): ?CarbonImmutable
    {
        $created = TicketMessage::query()->where('ticket_id', $this->id)->where('type', 'reply')
            ->whereIn('author_type', ['client', 'guest'])
            ->when($this->last_staff_reply_at !== null, fn (Builder $messages) => $messages->where('created_at', '>', $this->last_staff_reply_at))
            ->min('created_at');

        return $created !== null ? CarbonImmutable::parse($created) : null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(fn (Builder $query) => $query
            ->whereLike('number', $like)
            ->orWhereLike('subject', $like)
            ->orWhereLike('requester_email', $like)
            ->orWhereLike('requester_name', $like)
            ->orWhereHas('messages', fn (Builder $messages) => $messages->whereLike('body', $like)->where('type', 'reply')));
    }
}
