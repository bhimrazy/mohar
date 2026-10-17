<?php

namespace App\Modules\Support\Enums;

/**
 * Outcome of the inbound email pipeline (spec §6), recorded on inbound_emails.status.
 */
enum InboundEmailStatus: string
{
    case Received = 'received';
    case TicketCreated = 'ticket_created';
    case ReplyAdded = 'reply_added';
    case Blocked = 'blocked';
    case Imported = 'imported';
    /** Processing threw; the provider's retry re-processes the same Message-ID. */
    case Failed = 'failed';
    case RejectedAutoSubmitted = 'rejected_auto_submitted';
    case RejectedLoop = 'rejected_loop';
    case RejectedRateLimit = 'rejected_rate_limit';
    case RejectedSpam = 'rejected_spam';
    case RejectedNoDepartment = 'rejected_no_department';
    case BouncedClosed = 'bounced_closed';
    case BouncedRepliesOnly = 'bounced_replies_only';
    case BouncedUnregistered = 'bounced_unregistered';

    public function isRejected(): bool
    {
        return str_starts_with($this->value, 'rejected_') || str_starts_with($this->value, 'bounced_');
    }
}
