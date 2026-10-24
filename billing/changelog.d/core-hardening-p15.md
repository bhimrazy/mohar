### Added

- **`php artisan app:admin-password <email>` gives a full administrator a new one-time password (#60).** It is
  printed once, must be changed at sign-in and expires after 24 hours, like the installer's. Use it when the first
  one expired or was lost.
- **The role form says what an upgrade does to a system role (#60).** Its permissions are reset to the built-in set
  on every upgrade; the name and description are kept.

### Changed

- **One `app:install` runs at a time (#216, #60).** After the pre-flight it takes a lock in the shared cache store;
  a second run stops with "Another app:install is running" and exits 1. Two runs that still collide on a unique key
  end with a plain message instead of a database error.
- **The APP_URL pre-flight warning also covers `0.0.0.0`, `[::1]`, other `127.x` addresses, `*.local` and
  `*.localhost` (#60),** and no longer warns when the scheme is written in capitals (`HTTPS://`).

### Fixed

- **A "Run now" status check can no longer make the scheduled automation run skip the day (#132).** Checking for a
  run in progress only reads the run lock.
- **A system role renamed before the role-key upgrade is found again (#60).** Upgrade: a migration gives such a role
  its key when its permissions still match a missing system role exactly; any other keyless system role becomes a
  custom role, keeping its name, permissions and staff, and can then be edited or deleted.

### Security

- **The one-time administrator password from `app:install --admin-email` expires after 24 hours (#60).** Sign-in
  with it is then refused with how to get a new one ("Forgot password" or `app:admin-password`). Upgrade: a
  one-time password issued before this release does not expire; reissue it with `app:admin-password` if it was
  never used.
- **The confirm-password page says "download this file" only while the download is the page it will return to
  (#305).** An abandoned download no longer labels a later confirmation for something else. A confirmation in the dialog now forgets only a remembered download, not another remembered page.
