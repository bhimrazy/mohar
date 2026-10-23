### Changed

- **The client dashboard shows what the customer owns and owes (#417).** An unpaid invoice now puts a banner on top
  with its amount, its due date and a Pay button (red once overdue; it says how many other invoices are due). Below the
  counters, "Your services", "Recent invoices", "Support" and "Domains" list the few most relevant items with a link to
  the full page and an empty state with a next step. They replace the "Quick links" list, which repeated the sidebar. A
  panel is absent when its module is not installed.
- **Account users see only the areas the owner granted them on the dashboard.** Before, a user without the billing,
  services, domains or support area still saw that area's counters (for example the unpaid total). Upgrade note: no
  action needed; owners are unaffected.
