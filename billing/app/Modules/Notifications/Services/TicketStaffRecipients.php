<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Support\TicketStaffDirectory;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\StaffCategory;

/**
 * Which staff receive a ticket's mail (Support spec §5): the department's members for a new ticket, the assignee for a
 * customer reply (the members while it is unassigned), the members of the new department when a moved ticket is
 * unassigned. Everyone must be active and hold `support.tickets.view`.
 *
 * When that yields nobody (no Support module, a department with no members, an inactive assignee), the mail goes to
 * the staff who subscribed to the support alert category, as before.
 */
class TicketStaffRecipients
{
    private const VIEW = 'support.tickets.view';

    public function __construct(private TicketStaffDirectory $tickets, private RecipientResolver $recipients) {}

    /**
     * @return list<Recipient>
     */
    public function forNewTicket(int $ticketId): array
    {
        return $this->resolve($this->tickets->newTicketMembers($ticketId));
    }

    /**
     * @return list<Recipient>
     */
    public function forCustomerReply(int $ticketId): array
    {
        $assignee = $this->tickets->assigneeId($ticketId);
        $assigned = $assignee !== null ? $this->recipients->staff(StaffCategory::Support, self::VIEW, [$assignee]) : [];

        return $assigned !== [] ? $assigned : $this->resolve($this->tickets->replyMembers($ticketId));
    }

    /**
     * A moved ticket that still has an assignee needs no mail: the assignee keeps it.
     *
     * @return list<Recipient>
     */
    public function forDepartmentMove(int $ticketId): array
    {
        $assignee = $this->tickets->assigneeId($ticketId);

        if ($assignee !== null && $this->recipients->staff(StaffCategory::Support, self::VIEW, [$assignee]) !== []) {
            return [];
        }

        return $this->resolve($this->tickets->newTicketMembers($ticketId));
    }

    /**
     * @param  list<int>  $userIds
     * @return list<Recipient>
     */
    private function resolve(array $userIds): array
    {
        $members = $userIds !== [] ? $this->recipients->staff(StaffCategory::Support, self::VIEW, $userIds) : [];

        return $members !== [] ? $members : $this->recipients->staff(StaffCategory::Support, self::VIEW);
    }
}
