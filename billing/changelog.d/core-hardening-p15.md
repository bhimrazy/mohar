### Added

- **`php artisan app:admin-password <email>` gives a full administrator a new one-time password (#60, #459).** It is
  printed once, must be changed at sign-in and expires after 24 hours, like the installer's. Use it when the first
  one expired or was lost. The old password's remember-me cookies stop working, and so do its signed-in sessions
  with the database session driver (the same revoke staff deactivation uses, now `LoginSessions` in Core). The
  administrator is mailed (new template `staff.password_reissued`, always sent), and the activity log records who
  ran it (OS user and host).
- **The role form says what an upgrade does to a system role (#60).** Its permissions are reset to the built-in set
  on every upgrade; the name and description are kept.

### Changed

- **One `app:install` runs at a time (#216, #60, #459).** After the pre-flight it holds a 15-minute lock
  (`app:install`) in the shared cache store; a second run stops, exits 1 and says how to recover. If a run was killed
  before it finished, wait 15 minutes or run `php artisan app:install --release-lock --force`, which asks you to
  confirm in a terminal that no install is running. Two runs that still collide on a unique key end with a plain
  message instead of a database error.
- **The APP_URL pre-flight warning also covers `0.0.0.0`, `[::1]`, other `127.x` addresses, `*.local` and
  `*.localhost` (#60),** and no longer warns when the scheme is written in capitals (`HTTPS://`).

### Fixed

- **A "Run now" status check can no longer make the scheduled automation run skip the day (#132, #459).** Checking
  for a run in progress (`RunAutomation::inProgress()`) only reads the run lock and never takes it.
- **A system role renamed before the role-key upgrade is found again (#60).** Upgrade: a migration gives such a role
  its key when its permissions still match a missing system role exactly; any other keyless system role becomes a
  custom role, keeping its name, permissions and staff, and can then be edited or deleted.

### Security

- **The one-time administrator password from `app:install --admin-email` expires after 24 hours (#60).** Sign-in
  with it is then refused with how to get a new one ("Forgot password" or `app:admin-password`), and so is the end of a
  two-factor challenge started before the expiry, every password confirmation (step-up, the security page,
  two-factor changes, passkey registration) even in a session opened before it, and its use as the current
  password when changing the password. Each refusal is in the activity log (`auth.password_expired`). Passkey sign-in is unaffected. Upgrade: a
  one-time password issued before this release does not expire; reissue it with `app:admin-password` if it was
  never used.
- **The confirm-password page says "download this file" only while the download is the page it will return to
  (#305).** An abandoned download no longer labels a later confirmation for something else. A confirmation in the dialog now forgets only a remembered download, not another remembered page.
