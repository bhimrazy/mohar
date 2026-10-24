### `TicketStaffDirectory` and `TicketAccessLinkReplaced` (#18, #30)

- New contract `App\Contracts\Support\TicketStaffDirectory`: `newTicketMembers(int $ticketId): list<int>`,
  `replyMembers(int $ticketId): list<int>` (department members with `notify_new` / `notify_replies` on, by user id) and
  `assigneeId(int $ticketId): ?int` and `withDepartmentAccess(int $ticketId, list<int> $userIds): list<int>` (the given staff who may open the ticket's department). Support binds `SupportTicketStaff`; Core binds `NullTicketStaffDirectory` with
  `bindIf` (empty lists, null, ids unchanged), so Notifications keeps its category-based recipients when Support is absent. Additive.
- New event `App\Events\Support\TicketAccessLinkReplaced(int $ticketId, ?int $clientId)`, dispatched after a merge
  into a guest target ticket replaced its access key (every such merge). Notifications maps it to the new
  `ticket.link_replaced` template. Additive.
