<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\AuthorType;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Models\StaffSignature;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use Illuminate\Support\Str;

/**
 * Creates thread messages (rendered through the safe Markdown renderer) and stores their files.
 */
class MessageWriter
{
    public function __construct(
        private MarkdownRenderer $markdown,
        private AttachmentStore $attachments,
        private MergeTokens $tokens,
    ) {}

    /**
     * @param  list<IncomingFile>  $files
     */
    public function write(
        Ticket $ticket,
        TicketAuthor $author,
        MessageType $type,
        string $body,
        TicketSource $source = TicketSource::Web,
        array $files = [],
        ?string $emailMessageId = null,
        bool $autoresponse = false,
        bool $unverified = false,
    ): TicketMessage {
        if ($author->isStaff() && $type === MessageType::Reply && ! $autoresponse) {
            $body = $this->withSignature($this->tokens->resolve($body, $ticket), $author->user);
        }

        if ($emailMessageId === null && $type === MessageType::Reply) {
            $emailMessageId = self::outboundMessageId();
        }

        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'type' => $type,
            'author_type' => $author->type,
            'user_id' => $author->user?->id,
            'contact_id' => $author->contactId,
            'author_name' => mb_substr($author->name, 0, 255),
            'author_email' => $author->email,
            'body' => $body,
            'body_html' => $this->markdown->render($body),
            'source' => $source,
            'email_message_id' => $emailMessageId,
            'is_autoresponse' => $autoresponse,
            'is_unverified_sender' => $unverified,
        ]);

        foreach ($files as $index => $file) {
            $this->attachments->store($ticket, $message, $file, $index + 1);
        }

        return $message;
    }

    /**
     * A system-authored note (e.g. rejected inbound attachments). Staff-only.
     */
    public function systemNote(Ticket $ticket, string $body): TicketMessage
    {
        return $this->write($ticket, TicketAuthor::system(), MessageType::Note, $body, TicketSource::Staff);
    }

    /**
     * Update ticket reply timestamps for a new public reply.
     */
    public function touchReplyTimestamps(Ticket $ticket, TicketAuthor $author): void
    {
        $now = now();
        $ticket->last_reply_at = $now;

        if ($author->type === AuthorType::Staff) {
            $ticket->last_staff_reply_at = $now;
            $ticket->first_staff_reply_at ??= $now;
        } elseif ($author->type->isCustomer()) {
            $ticket->last_client_reply_at = $now;
        }
    }

    /**
     * Pre-assigned RFC 5322 Message-ID for outbound mail of this message, so email replies
     * (In-Reply-To/References) thread back to the ticket.
     */
    public static function outboundMessageId(): string
    {
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        return '<'.Str::uuid()->toString().'@'.$host.'>';
    }

    private function withSignature(string $body, ?User $user): string
    {
        $signature = $user !== null ? StaffSignature::query()->where('user_id', $user->id)->value('signature') : null;

        return is_string($signature) && trim($signature) !== '' ? rtrim($body)."\n\n".trim($signature) : $body;
    }
}
