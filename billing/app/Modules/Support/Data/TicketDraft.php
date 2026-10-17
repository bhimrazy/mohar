<?php

namespace App\Modules\Support\Data;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketSource;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Services\IncomingFile;

/**
 * Everything needed to open a ticket, whoever opens it (client, guest, staff, inbound email).
 */
final readonly class TicketDraft
{
    /**
     * @param  list<string>  $cc
     * @param  list<IncomingFile>  $files
     * @param  list<string>  $notes  system notes added after the first message (e.g. rejected attachments)
     */
    public function __construct(
        public int $departmentId,
        public string $subject,
        public string $body,
        public TicketAuthor $author,
        public string $requesterName,
        public string $requesterEmail,
        public ?int $clientId = null,
        public ?int $userId = null,
        public ?int $contactId = null,
        public TicketPriority $priority = TicketPriority::Medium,
        public TicketSource $source = TicketSource::Web,
        public ?TicketStatus $status = null,
        public ?int $serviceId = null,
        public ?int $assignedTo = null,
        public array $cc = [],
        public array $files = [],
        public ?string $emailMessageId = null,
        public array $notes = [],
        public bool $unverifiedRequester = false,
    ) {}
}
