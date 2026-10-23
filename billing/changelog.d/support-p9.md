### Fixed

- **The Awaiting reply queue no longer sorts by two subqueries per ticket (#51).** Each ticket now stores the time of its oldest unanswered customer reply (`first_unanswered_at`), and the queue reads it from an index that matches its order (pinned first, oldest unanswered, answered last). Repeated customer replies still do not move a ticket back. The upgrade migration adds the column and the index and fills the column in batches of 1,000 tickets; on a very large table it takes a few minutes and blocks no writes. A reply recorded by the previous release while both run is picked up at the next staff reply.

### Security

- **Merging tickets of different requesters replaces the target ticket's access key (#18).** A guest ticket opened under someone else's email address can no longer keep its old link into a merged thread. The previous link stops working, and a new link is emailed to the target ticket's requester address (new template `ticket.link_replaced`, editable under Admin, Settings, Email templates). A merge of tickets from one requester leaves the key unchanged.

### Changed

- **Staff ticket mail goes to the people the ticket concerns (#30).** A new ticket mails the department's members who have new-ticket mail on; a customer reply mails the assignee, or the members who have reply mail on while the ticket is unassigned; a ticket moved to another department mails the new department's members only when it ends up unassigned (a ticket that keeps its assignee sends no mail). Members need `support.tickets.view`. When nobody qualifies (a department without members, an assignee who is inactive), the mail goes to the staff subscribed to the support alert category, as before. Upgrade note: staff who are not department members no longer receive new-ticket or reply mail for that department, whatever their role's email subscriptions say.
