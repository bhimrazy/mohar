### Added

- **Mail server settings in Admin → Settings → Mail server.** Staff choose SMTP, Amazon SES or Log only and enter the
  host, port, encryption (STARTTLS required, TLS from the start, or automatic), username and password, or the SES access
  key, secret and region, without editing `.env`. Passwords and keys are stored encrypted, never sent back to the page
  and masked in the activity log. A value saved here replaces its `MAIL_*` / `AWS_*` counterpart; a value never saved
  keeps the `.env` one, including `MAIL_SCHEME` when encryption is "Automatic" (Automatic changes nothing). A `MAIL_URL`
  in `.env` is replaced by SMTP values saved here, and the page says so. A saved password or secret is removed with the
  "Remove the saved value on save" control; a blank field alone keeps it. The SMTP host may be on this machine or your
  network but not a link-local, cloud-metadata or reserved address. The sender address and Reply-To stay under
  Settings → Email delivery.
- **Send test email.** Sends one message at once, with the saved settings, to an address staff enter (their own by
  default), and shows the mail server's answer on the page with credentials masked. At most 5 per staff member per
  10 minutes; each attempt is in the activity log (`settings.mail_test`). Each SMTP answer is awaited at most 10 seconds
  and no new command starts after 20 seconds in total.

### Changed

- Upgrade notes: new sensitive permission `core.settings.mail` (Full Administrators hold it through `*`; a wildcard
  such as `core.settings.*` does not grant it). Opening, saving and test-sending need a recent password, and every
  save emails the full administrators. Queue workers use changed settings from their next job; no `queue:restart`
  is needed. The installer's mail warning now points to the new page and reads the mailer saved there. Staff who may
  view settings but not change the mail server see the SMTP username masked. `OutboundAddressGuard` gains an optional
  relay profile (kernel changelog).
