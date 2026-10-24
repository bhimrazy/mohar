### Added

- **Retention for the gateway log, usage history, automation log and queue tables (#49).** New daily housekeeping
  tasks delete gateway log entries after 180 days (Settings → Payment log retention, 90 to 3,650; needs the security
  settings permission and a recent password),
  disk and bandwidth snapshots after 395 days (Settings → Services, 90 to 3,650; each service keeps its latest),
  automation runs after 365 days (Settings → Security, 90 to 3,650; each task keeps its latest run), failed
  background jobs and finished job batches after 30 days (Settings → Security, 7 to 365; unfinished batches are
  kept), and expired rows of the database cache. Upgrade note: on the first daily run after the upgrade these tasks
  delete existing rows older than those windows, at most 100,000 rows per table and run; a larger backlog is worked
  through over the following days. Lower the windows only after checking what you need to keep: deletion is final.
- **Identity image files left without a document are listed for staff (#298).** A daily check finds files under `kyc/`
  that no identity document refers to and that are older than a day (left when an upload was killed partway). It
  deletes nothing: the count shows under Needs attention and on Identity verification, and staff with the permission
  to approve documents delete the listed files after a password step-up and a typed confirmation. Each file is checked
  again just before it is deleted, and the delete is in the activity log (count and client ids, never paths). Many
  files usually mean the database does not own the storage bucket (a restored database, or a second installation).
  Upgrade note: a migration adds the `kyc_orphan_files` table.

### Changed

- **Log pages no longer count the whole log (#49).** The activity log, gateway log and module log show Previous and
  Next with the page number instead of a total, so they stay fast on large tables.
- **Every batched retention delete stops at 100,000 rows a run and logs the table and count.** This now also applies to
  the existing activity log, module log, registrar log, webhook delivery and cart prunes. Upgrade note: a migration adds
  an index on `failed_jobs.failed_at` (built concurrently on PostgreSQL); no migration deletes data.
- **`automation:run --date` refuses dates older than the automation log retention (365 days by default), and every
  date the prune has already deleted, even after the retention is raised.** Their run records are gone, so such a date
  would run every task again. Retention tasks count their window from today when
  `--date` is in the future, so a rehearsal never deletes rows still inside their window.
