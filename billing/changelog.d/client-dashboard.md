### Changed

- **The client dashboard shows what the customer owns and owes (#417).** An unpaid invoice now puts a banner on top
  with its amount, its due date and a Pay button. It turns red once overdue and says how many other invoices are due.
  Below the counters, "Your services", "Recent invoices" (with Pay on each invoice to pay), "Support" and "Domains" list
  the few most relevant items with a link to the full page and an empty state with a next step. They replace the
  "Quick links" list, which repeated the sidebar. A panel is absent when its module is not installed. The greeting
  uses the signed-in person's name rather than the account holder's, and on phones the counters sit two per row.
- **"Unpaid invoices" on the client dashboard counts what is still to pay.** It now counts unpaid and collections
  invoices with a balance, the same set as the banner. Invoices whose payment is already in progress
  (payment pending) are no longer counted.

### Security

- **Account users see only the areas the owner granted them on the dashboard.** Before, a user without the billing,
  services, domains or support area still saw that area's counters (for example the unpaid total). The page also
  sent the account's profile (email, phone, address, tax ID) to every linked user. It now sends only the company
  name. No action needed; owners are unaffected.
