<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketAssigned;
use App\Events\Support\TicketClosed;
use App\Events\Support\TicketDepartmentChanged;
use App\Events\Support\TicketPriorityChanged;
use App\Events\Support\TicketReopened;
use App\Events\Support\TicketStatusChanged;
use App\Events\Support\TicketUnassigned;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Exceptions\TicketVersionConflict;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff changes to ticket options: status, priority, department, assignee, subject, CC, tags,
 * pin, prevent-close, related service and client link. Each change is audited and evented.
 */
class UpdateTicket
{
    /** @var list<object> */
    private array $pending = [];

    public function __construct(
        private TicketRecorder $recorder,
        private TicketAccess $access,
        private CloseTicket $closer,
    ) {}

    /**
     * @param  array{status?: string, priority?: string, department_id?: int, assigned_to?: int|null, subject?: string, cc?: list<string>, tags?: list<string>, is_pinned?: bool, prevent_client_close?: bool, is_unverified_requester?: bool, service_id?: int|null, client_id?: int|null}  $changes
     *
     * @throws TicketVersionConflict|ValidationException
     */
    public function handle(Ticket $ticket, User $staff, array $changes, ?int $expectedVersion = null, bool $force = false): Ticket
    {
        $this->pending = [];
        $actor = TicketAuthor::staff($staff);

        DB::transaction(function () use ($ticket, $actor, $changes, $expectedVersion, $force): void {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($expectedVersion !== null && ! $force && $locked->version !== $expectedVersion) {
                throw new TicketVersionConflict($locked->version, $this->recorder->changesSince($locked, $expectedVersion));
            }

            if (array_key_exists('department_id', $changes) && (int) $changes['department_id'] !== $locked->department_id) {
                $this->moveDepartment($locked, $actor, (int) $changes['department_id']);
            }

            if (array_key_exists('assigned_to', $changes)) {
                $this->assign($locked, $actor, $changes['assigned_to']);
            }

            if (isset($changes['priority']) && $changes['priority'] !== $locked->priority->value) {
                $from = $locked->priority->value;
                $locked->priority = TicketPriority::from($changes['priority']);
                $locked->save();
                $this->recorder->record($locked, $actor, 'priority', $from, $locked->priority->value);
                $this->pending[] = new TicketPriorityChanged($locked->id, $from, $locked->priority->value);
            }

            if (isset($changes['subject']) && $changes['subject'] !== $locked->subject) {
                $from = $locked->subject;
                $locked->subject = mb_substr($changes['subject'], 0, 255);
                $locked->save();
                $this->recorder->record($locked, $actor, 'subject', $from, $locked->subject);
            }

            if (array_key_exists('cc', $changes)) {
                $cc = array_values(array_unique(array_map('mb_strtolower', $changes['cc'])));

                if ($cc !== ($locked->cc ?? [])) {
                    $locked->cc = $cc;
                    $locked->save();
                    $this->recorder->record($locked, $actor, 'cc', null, implode(', ', $cc));
                }
            }

            if (array_key_exists('tags', $changes)) {
                $tags = array_values(array_unique(array_filter(array_map('trim', $changes['tags']))));

                if ($tags !== ($locked->tags ?? [])) {
                    $locked->tags = $tags;
                    $locked->save();
                    $this->recorder->record($locked, $actor, 'tags', null, implode(', ', $tags));
                }
            }

            if (array_key_exists('is_pinned', $changes) && $changes['is_pinned'] !== $locked->is_pinned) {
                $locked->is_pinned = $changes['is_pinned'];
                $locked->save();
                $this->recorder->record($locked, $actor, 'pinned', null, $locked->is_pinned ? '1' : '0');
            }

            if (array_key_exists('prevent_client_close', $changes)) {
                $locked->prevent_client_close = $changes['prevent_client_close'];
                $locked->save();
            }

            if (array_key_exists('is_unverified_requester', $changes) && $changes['is_unverified_requester'] !== $locked->is_unverified_requester) {
                $locked->is_unverified_requester = $changes['is_unverified_requester'];
                $locked->save();
                $this->recorder->record($locked, $actor, 'requester_verified', null, $locked->is_unverified_requester ? '0' : '1');
            }

            if (array_key_exists('service_id', $changes)) {
                $locked->service_id = $changes['service_id'];
                $locked->save();
            }

            if (array_key_exists('client_id', $changes) && $changes['client_id'] !== $locked->client_id) {
                $client = $changes['client_id'] !== null ? Client::query()->findOrFail($changes['client_id']) : null;
                $locked->client_id = $client?->id;
                $locked->contact_id = null;
                $locked->is_unverified_requester = false;
                // Whoever held the old guest link must not follow the ticket onto another account; a linked
                // ticket's mail points at the client area, and an unlinked one mails the new key.
                $locked->access_key = Str::random(32);
                $locked->save();
                $this->recorder->record($locked, $actor, 'client_linked', null, $client?->display_name);
            }

            if (isset($changes['status']) && $changes['status'] !== $locked->status->value) {
                $this->changeStatus($locked, $actor, TicketStatus::from($changes['status']));
            }

            $ticket->setRawAttributes($locked->getAttributes(), true);
            $ticket->unsetRelations();
        });

        foreach ($this->pending as $event) {
            event($event);
        }

        return $ticket;
    }

    private function changeStatus(Ticket $ticket, TicketAuthor $actor, TicketStatus $next): void
    {
        $from = $ticket->status;

        if ($next === TicketStatus::Closed) {
            $this->closer->apply($ticket, ClosedBy::Staff, $actor);
            $this->pending[] = new TicketClosed($ticket->id, $ticket->client_id, ClosedBy::Staff->value);

            return;
        }

        $ticket->status = $next;

        if ($from === TicketStatus::Closed) {
            $ticket->closed_at = null;
            $ticket->closed_by = null;
            $ticket->save();
            $this->recorder->record($ticket, $actor, 'reopened', $from->value, $next->value);
            $this->pending[] = new TicketReopened($ticket->id, $ticket->client_id, 'staff');

            return;
        }

        $ticket->save();
        $this->recorder->record($ticket, $actor, 'status', $from->value, $next->value);
        $this->pending[] = new TicketStatusChanged($ticket->id, $from->value, $next->value);
    }

    private function moveDepartment(Ticket $ticket, TicketAuthor $actor, int $departmentId): void
    {
        $from = Department::query()->findOrFail($ticket->department_id);
        $to = Department::query()->findOrFail($departmentId);

        $ticket->department_id = $to->id;
        $ticket->prevent_client_close = $to->prevent_client_close;
        $ticket->save();
        $this->recorder->record($ticket, $actor, 'department', $from->name, $to->name);
        $this->pending[] = new TicketDepartmentChanged($ticket->id, $from->id, $to->id);

        if ($ticket->assigned_to !== null) {
            $assignee = User::query()->find($ticket->assigned_to);

            if ($assignee === null || ! $this->access->isEligibleAssignee($assignee, $to->id)) {
                $this->unassign($ticket, $actor, $assignee);
            }
        }
    }

    private function assign(Ticket $ticket, TicketAuthor $actor, ?int $userId): void
    {
        if ($userId === $ticket->assigned_to) {
            return;
        }

        if ($userId === null) {
            $this->unassign($ticket, $actor, User::query()->find($ticket->assigned_to));

            return;
        }

        $assignee = User::query()->find($userId);

        if ($assignee === null || ! $this->access->isEligibleAssignee($assignee, $ticket->department_id)) {
            throw ValidationException::withMessages([
                'assigned_to' => __('Choose a staff member who works in this ticket\'s department.'),
            ]);
        }

        $ticket->assigned_to = $assignee->id;
        $ticket->save();
        $this->recorder->record($ticket, $actor, 'assigned', null, $assignee->name);

        if ($assignee->id !== $actor->user?->id) {
            $this->pending[] = new TicketAssigned($ticket->id, $assignee->id);
        }
    }

    private function unassign(Ticket $ticket, TicketAuthor $actor, ?User $previous): void
    {
        $previousId = $ticket->assigned_to;
        $ticket->assigned_to = null;
        $ticket->save();
        $this->recorder->record($ticket, $actor, 'unassigned', $previous?->name);

        if ($previousId !== null) {
            $this->pending[] = new TicketUnassigned($ticket->id, $previousId);
        }
    }
}
