### Added

- **Mail server settings in Admin → Settings → Mail server.** Staff choose SMTP, Amazon SES or Log only and enter the
  host, port, encryption (STARTTLS required, TLS from the start, or automatic), username and password, or the SES access
  key, secret and region, without editing `.env`. Passwords and keys are stored encrypted, never sent back to the page
  and masked in the activity log. A value saved here replaces its `MAIL_*` / `AWS_*` counterpart; a value never saved
  keeps the `.env` one. The sender address and Reply-To stay under Settings → Email delivery.
- **Send test email.** Sends one message at once, with the saved settings, to an address staff enter (their own by
  default), and shows the mail server's answer on the page with credentials masked. At most 5 per staff member per
  10 minutes; each attempt is in the activity log (`settings.mail_test`).

### Changed

- Upgrade notes: new sensitive permission `core.settings.mail` (Full Administrators hold it through `*`; a wildcard
  such as `core.settings.*` does not grant it). Opening, saving and test-sending need a recent password, and every
  save emails the full administrators. Queue workers use changed settings from their next job; no `queue:restart`
  is needed. The installer's mail warning now points to the new page.
