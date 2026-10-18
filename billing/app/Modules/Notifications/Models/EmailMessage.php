<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Like;
use App\Modules\Notifications\Database\Factories\EmailMessageFactory;
use App\Modules\Notifications\Enums\EmailStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound email (the email log, spec §2). Bodies are the redacted copy: secrets are replaced by ••••.
 *
 * @property int $id
 * @property string $uuid
 * @property string $dedupe_key
 * @property string|null $template_key
 * @property int|null $client_id
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property int|null $staff_id
 * @property string $recipient
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property string|null $from
 * @property string $subject
 * @property string|null $body_html
 * @property string|null $body_text
 * @property bool $has_redactions
 * @property string $locale
 * @property string|null $related_type
 * @property int|null $related_id
 * @property EmailStatus $status
 * @property string|null $suppressed_reason
 * @property string|null $failure_reason
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $body_purged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 */
#[UseFactory(EmailMessageFactory::class)]
#[Fillable([
    'uuid', 'dedupe_key', 'template_key', 'client_id', 'user_id', 'contact_id', 'staff_id', 'recipient', 'to', 'cc', 'bcc',
    'from', 'subject', 'body_html', 'body_text', 'has_redactions', 'locale', 'related_type', 'related_id',
    'status', 'suppressed_reason', 'failure_reason', 'attempts', 'provider_message_id', 'queued_at', 'sent_at', 'body_purged_at',
])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    public const REDACTED = '••••';

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'has_redactions' => 'boolean',
            'status' => EmailStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'body_purged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Free-text search over recipient, subject and template key (literal, case-insensitive).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = Like::contains($term);
        $query->where(function (Builder $query) use ($like): void {
            Like::where($query, 'recipient', $like);
            Like::where($query, 'subject', $like, 'or');
            Like::where($query, 'template_key', $like, 'or');
        });
    }
}
