<?php

namespace App\Contracts\Support;

/**
 * Which staff a ticket concerns, for mail that goes to staff rather than the requester. Implemented by Support; Core
 * binds a null default (no members, no assignee), so a caller falls back to its own staff list when Support is absent.
 *
 * Ids are staff logins. The caller still checks that each is active and may view tickets. An unknown ticket id yields
 * an empty list or null. A list holds at most 200 ids (a department's members beyond that are not returned).
 */
interface TicketStaffDirectory
{
    /**
     * Members of the ticket's department who asked for new-ticket mail (their `notify_new` flag), by user id.
     *
     * @return list<int>
     */
    public function newTicketMembers(int $ticketId): array;

    /**
     * Members of the ticket's department who asked for reply mail (their `notify_replies` flag), by user id.
     *
     * @return list<int>
     */
    public function replyMembers(int $ticketId): array;

    /**
     * The staff member the ticket is assigned to, or null when unassigned.
     */
    public function assigneeId(int $ticketId): ?int;

    /**
     * Of the given staff, those who may open the ticket: members of its department, or holders of the access-all
     * permission. An assignee removed from the department, or a subscriber limited to other departments, is dropped.
     * The null default returns the ids unchanged (no department rules to apply).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function withDepartmentAccess(int $ticketId, array $userIds): array;
}
