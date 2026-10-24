### Added

- **Clients can show their hosting password in the client area (#44).** Welcome emails no longer carry passwords, so a
  live service with a stored password now has a Password row with "Show password". It asks for the account password
  (recent confirmation), shows the password for 60 seconds with a copy button, and is limited to 5 reveals per 10
  minutes per login. The account owner and users granted the services area can use it. Panels that sign in only by
  SSO store no password and show no row.

### Changed

- **An unknown provisioning outcome sends a "being checked" notice, not a failure (#147).** When a create or other
  module command gets no clear answer (a timeout after sending), staff get the new `staff.provisioning_unknown` email
  instead of `staff.provisioning_failed`. If the server check then finds the account, it is adopted with no failure
  mail. Upgrade note: `app:install` (run by the deploy) adds the new template; until it runs, unknown outcomes send no
  staff email.
- **A missing email template is logged.** When an event's template row does not exist (before `app:install` adds a
  new default, or after one was deleted), the log gets a warning naming the template key instead of nothing.

### Security

- **Each client password reveal is audited (#44).** The activity log records "Service password revealed in the client
  area" with who, which service and when, never the value. The password is fetched on demand as JSON with
  `Cache-Control: no-store`, and is never in page props, browser history or logs.
