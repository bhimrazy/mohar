### Added

- **The admin client page opens with an account summary (#32).** Four money cards show the outstanding balance,
  the overdue amount, the credit balance and payments received (net of refunds, not counting account credit), in the
  client's currency; amounts held in another currency are named under the card instead of being added in. Counters
  show outstanding invoices (with how many are overdue), active services (with suspended and pending counts), domains
  (with how many expire within 30 days) and open tickets. Below them, "Invoices", "Services", "Domains" and "Open
  tickets" list five rows each with a "View all" link to the list filtered to the client. Every card links to the
  list behind its figure. Each part needs the permission of the list it summarises (`billing.invoices.view`,
  `billing.credit.manage`, `services.services.view`, `domains.domains.view`, `support.tickets.view`), tickets are
  limited to the departments the staff member may see, and a part is absent when its module is not installed. Overdue
  uses the company's time zone. The "Quick links" card stays for the lists the summary does not cover.
- **The admin invoice list has an "Outstanding" status filter.** It lists unpaid, collections and payment-pending
  invoices with a balance left, the same set the client page's outstanding balance and count add up.
