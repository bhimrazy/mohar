### Changed

- **Indexes for the billing day, the dashboards and the queue.** The billing-day tasks (late fees, capture,
  reminders, auto-cancel) take open invoices from a partial index instead of walking every invoice; the renewal run
  takes live services from a partial index, which the other service tasks can also use; the admin dashboard's receivables read open and cancelled invoices from their own indexes; the client invoice
  list and client dashboard read a client's invoices newest first from an index; a database-queue pop no longer
  scans past other queues' backlogs. At 287,000 invoices a reminder chunk went from 81 ms to 4.6 ms and the client
  invoice list from 1.9 ms to 0.08 ms. Upgrade note: the migrations build seven indexes with `CREATE INDEX
  CONCURRENTLY` on PostgreSQL, which blocks no reads or writes but can take minutes on a large `invoices` or
  `jobs` table; each build waits for transactions already running, so run the migration outside the busiest
  hour. Nothing is dropped except `products_slug_unique` (below).
- **A deleted product no longer holds on to its slug.** A new product can use the slug of a deleted one; two live
  products still cannot share one. Upgrade note: `products_slug_unique` is replaced by `products_slug_live_unique`
  (`WHERE deleted_at IS NULL`). Rolling back refuses while a deleted and a live product share a slug.
