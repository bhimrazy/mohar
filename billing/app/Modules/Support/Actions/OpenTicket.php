<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketAssigned;
use App\Events\Support\TicketOpened;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Enums\MessageType;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\MessageWriter;
use App\Modules\Support\Services\TicketNumberGenerator;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens a ticket with its first message (spec §3: client/guest/email → open, staff → answered).
 */
class OpenTicket
{
    public function __construct(
        private TicketNumberGenerator $numbers,
        private MessageWriter $messages,
        private TicketRecorder $recorder,
    ) {}

    public function handle(TicketDraft $draft): Ticket
    {
        $department = Department::query()->findOrFail($draft->departmentId);
        $byStaff = $draft->author->isStaff();

        $ticket = DB::transaction(function () use ($draft, $department, $byStaff): Ticket {
            $now = now();
            $number = $this->numbers->generate($now);

            $ticket = new Ticket([
                'number' => $number ?? 'pending-'.Str::random(20),
                'access_key' => Str::random(32),
                'department_id' => $department->id,
                'client_id' => $draft->clientId,
                'user_id' => $draft->userId,
                'contact_id' => $draft->contactId,
                'requester_name' => mb_substr($draft->requesterName, 0, 255),
                'requester_email' => mb_strtolower($draft->requesterEmail),
                'subject' => mb_substr($draft->subject, 0, 255),
                'status' => $draft->status ?? ($byStaff ? TicketStatus::Answered : TicketStatus::Open),
                'priority' => $draft->priority,
                'source' => $draft->source,
                'service_id' => $draft->serviceId,
                'assigned_to' => $draft->assignedTo,
                'cc' => array_values(array_unique(array_map('mb_strtolower', $draft->cc))),
                'tags' => [],
                'prevent_client_close' => $department->prevent_client_close,
            ]);
            $this->messages->touchReplyTimestamps($ticket, $draft->author);
            $ticket->save();

            if ($number === null) {
                $ticket->number = $this->numbers->render($now, $ticket->id).'-'.$ticket->id;
                $ticket->saveQuietly();
            }

            $this->messages->write($ticket, $draft->author, MessageType::Reply, $draft->body, $draft->source, $draft->files, $draft->emailMessageId);
            $this->recorder->record($ticket, $draft->author, 'opened', null, $ticket->status->value);

            if (! $byStaff && $department->send_autoresponse && filled($department->autoresponse_message)) {
                $this->messages->write($ticket, TicketAuthor::system(), MessageType::Reply, (string) $department->autoresponse_message, autoresponse: true);
            }

            foreach ($draft->notes as $note) {
                $this->messages->systemNote($ticket, $note);
            }

            return $ticket;
        });

        TicketOpened::dispatch($ticket->id, $ticket->client_id, $ticket->department_id, $byStaff);

        if ($ticket->assigned_to !== null && $ticket->assigned_to !== $draft->author->user?->id) {
            TicketAssigned::dispatch($ticket->id, $ticket->assigned_to);
        }

        return $ticket;
    }
}
