### Fixed

- **A client's credit ledger, payment attempts and payment batches can no longer be deleted with the client row.**
  Their foreign keys to `clients` were `ON DELETE CASCADE` and are now `RESTRICT`, as invoices and transactions
  already were, so a `DELETE` of a client with any money history fails instead of erasing it. No screen deletes
  clients (closing and merging keep the row), so nothing changes for staff. Upgrade note: a support script or
  `psql` session that deletes clients now has to remove or move those rows deliberately first.
- **A tax rule an invoice was calculated with can no longer be deleted.** Admin → Billing → Tax rules says how many
  invoices use the rule, and new foreign keys on `invoices.tax1_rule_id` and `tax2_rule_id` (`RESTRICT`) back it.
  Upgrade note: invoices that already name a deleted rule are left unchanged and listed in the application log
  ("Invoices name a deleted tax rule"); on PostgreSQL that key then stays `NOT VALID` (new writes and rule deletes
  are still checked) until those ids are corrected and the `VALIDATE CONSTRAINT` statement in the log entry is run.
- **The database refuses billing values the rules never allow (PostgreSQL).** Invoice and transaction statuses
  outside their lists, a negative total on an issued invoice, a negative amount paid, credit applied, amount in,
  amount out or fee, a line quantity below 1 and a tax rate outside 0 to 100. Upgrade note: the migration checks
  existing rows first; if any break a rule it stops, names the table, rule and row ids, and adds nothing for that
  rule, so the deploy halts before the new release starts. Correct those rows and deploy again. On SQLite these
  checks are not added.
