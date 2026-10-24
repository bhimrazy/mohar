### Added

- **Retention for the gateway log, usage history, automation log and queue tables (#49).** New daily housekeeping
  tasks delete gateway log entries after 180 days (Settings → Billing, "Keep the gateway log for", 90 to 3,650),
  disk and bandwidth snapshots after 395 days (Settings → Services, 90 to 3,650; each service keeps its latest),
  automation runs after 365 days (Settings → Security, 90 to 3,650; each task keeps its latest run), failed
  background jobs and finished job batches after 30 days (Settings → Security, 7 to 365; unfinished batches are
  kept), and expired rows of the database cache. Upgrade note: on the first daily run after the upgrade these tasks
  delete existing rows older than those windows, at most 100,000 rows per table and run; a larger backlog is worked
  through over the following days. Lower the windows only after checking what you need to keep: deletion is final.
- **Identity image files left without a document are deleted (#298).** A daily task removes files under `kyc/` that
  no identity document refers to and that are older than a day, left when an upload was killed partway. At most
  200 files a run; the log lists counts and client ids, never paths.

### Changed

- **Log pages no longer count the whole log (#49).** The activity log, gateway log and module log show Previous and
  Next with the page number instead of a total, so they stay fast on large tables.
- **Every batched retention delete stops at 100,000 rows a run and logs the table and count.** This now also applies to
  the existing activity log, module log, registrar log, webhook delivery and cart prunes. Upgrade note: a migration adds
  an index on `failed_jobs.failed_at` (built concurrently on PostgreSQL); no migration deletes data.
