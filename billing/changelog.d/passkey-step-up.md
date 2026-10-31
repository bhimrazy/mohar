### Security

- **A passkey confirmation no longer counts as a password confirmation (#538).** Changing the sign-in email, secrets,
  roles, staff and other step-up actions now need the password typed within the last 15 minutes; a passkey
  confirmation from the same session is not enough. The confirm-password page no longer offers "Confirm with passkey".
- **Adding or removing a passkey, and every two-factor change, need a password confirmation from the last 15
  minutes (#538)**, not the 3-hour window. The Settings → Security page asks again after 15 minutes too. When the
  window runs out during two-factor setup, recovery codes, turning two-factor off or adding a passkey, the password
  dialog opens on the same page and the step carries on after it, with the typed code and the setup kept. A refused
  step now answers with a 423 that the dialog handles (all step-up forms, not only these), instead of a redirect. The confirm-password
  page's "Back" link now leads to the settings or the dashboard instead of the storefront, and the page and dialog
  name the configured window (`AUTH_STEP_UP_SECONDS`) instead of always "15 minutes".
- **Adding a passkey now mails the account (#538)** with the new always-sent templates "Passkey added" (clients) and
  "Staff passkey added", and writes `auth.passkey_added` to the activity log. The mail gives the date and time and
  links to the security settings. Staff mail no longer falls back to the clients' ticket list as its footer help link; an operator's help URL is
  still shown. Upgrade note: run
  `php artisan app:install`, which adds new reference data, so existing installations get the two templates; until then
  the notice goes out as a plain mail.
