### `TicketStaffDirectory` and `TicketAccessLinkReplaced` (#18, #30)

- New contract `App\Contracts\Support\TicketStaffDirectory`: `newTicketMembers(int $ticketId): list<int>`,
  `replyMembers(int $ticketId): list<int>` (department members with `notify_new` / `notify_replies` on, by user id) and
  `assigneeId(int $ticketId): ?int`. Support binds `SupportTicketStaff`; Core binds `NullTicketStaffDirectory` with
  `bindIf` (empty lists, null), so Notifications keeps its category-based recipients when Support is absent. Additive.
- New event `App\Events\Support\TicketAccessLinkReplaced(int $ticketId, ?int $clientId)`, dispatched after a merge of
  tickets from different requesters replaced a guest target ticket's access key. Notifications maps it to the new
  `ticket.link_replaced` template. Additive.
