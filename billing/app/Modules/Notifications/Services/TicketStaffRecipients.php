<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Support\TicketStaffDirectory;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\StaffCategory;

/**
 * Which staff receive a ticket's mail (Support spec §5): the department's members for a new ticket, the assignee for a
 * customer reply (the members while it is unassigned), the members of the new department when a moved ticket is
 * unassigned. Everyone must be active, hold `support.tickets.view` and be able to open the ticket's department.
 *
 * When that yields nobody (no Support module, a department with no members, an inactive assignee), the mail goes to the
 * staff who subscribed to the support alert category and can open the department.
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
        return $this->resolve($ticketId, $this->tickets->newTicketMembers($ticketId));
    }

    /**
     * @return list<Recipient>
     */
    public function forCustomerReply(int $ticketId): array
    {
        $assigned = $this->assignee($ticketId);

        return $assigned !== [] ? $assigned : $this->resolve($ticketId, $this->tickets->replyMembers($ticketId));
    }

    /**
     * A moved ticket that still has an assignee needs no mail: the assignee keeps it.
     *
     * @return list<Recipient>
     */
    public function forDepartmentMove(int $ticketId): array
    {
        return $this->assignee($ticketId) !== [] ? [] : $this->resolve($ticketId, $this->tickets->newTicketMembers($ticketId));
    }

    /**
     * @return list<Recipient> the assignee when active, allowed to view tickets and able to open the department
     */
    private function assignee(int $ticketId): array
    {
        $id = $this->tickets->assigneeId($ticketId);
        $allowed = $id !== null ? $this->tickets->withDepartmentAccess($ticketId, [$id]) : [];

        return $allowed !== [] ? $this->recipients->staff(StaffCategory::Support, self::VIEW, $allowed) : [];
    }

    /**
     * @param  list<int>  $userIds
     * @return list<Recipient>
     */
    private function resolve(int $ticketId, array $userIds): array
    {
        $allowed = $userIds !== [] ? $this->tickets->withDepartmentAccess($ticketId, $userIds) : [];
        $members = $allowed !== [] ? $this->recipients->staff(StaffCategory::Support, self::VIEW, $allowed) : [];

        return $members !== [] ? $members : $this->subscribers($ticketId);
    }

    /**
     * @return list<Recipient>
     */
    private function subscribers(int $ticketId): array
    {
        $subscribers = $this->recipients->staff(StaffCategory::Support, self::VIEW);
        $allowed = $this->tickets->withDepartmentAccess($ticketId, array_values(array_filter(array_map(fn (Recipient $recipient): ?int => $recipient->staffId, $subscribers))));

        return array_values(array_filter($subscribers, fn (Recipient $recipient): bool => in_array($recipient->staffId, $allowed, true)));
    }
}
