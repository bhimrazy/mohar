### Security

- **A passkey confirmation no longer counts as a password confirmation (#538).** Changing the sign-in email, secrets,
  roles, staff and other step-up actions now need the password typed within the last 15 minutes; a passkey
  confirmation from the same session is not enough. The confirm-password page no longer offers "Confirm with passkey".
- **Adding or removing a passkey, and every two-factor change, need a password confirmation from the last 15
  minutes (#538)**, not the 3-hour window. The Settings → Security page asks again after 15 minutes too.
- **Adding a passkey now mails the account (#538)** with the new always-sent templates "Passkey added" (clients) and
  "Staff passkey added", and writes `auth.passkey_added` to the activity log. Upgrade note: run
  `php artisan app:install`, which adds new reference data, so existing installations get the two templates; until then
  the notice goes out as a plain mail.
