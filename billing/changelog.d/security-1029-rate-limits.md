### Security

- **Password confirmation (step-up) is rate limited.** `POST /user/confirm-password` now allows five attempts a
  minute per login and IP, the same budget as the sign-in form. After that the step-up dialog and the confirm page
  say "Too many password attempts. Try again in n seconds." (429 for JSON clients, with `Retry-After`). Each failed
  confirmation writes an `auth.confirm_password_failed` entry to the activity log (who and from where, never the
  password). Before, a stolen session could guess the password without limit and then pass every step-up.
- **Invoice, quote and credit or debit note PDF downloads are rate limited.** The client-area and admin PDF routes
  share the `billing-documents` limiter: 10 downloads a minute per client login, 60 per staff member. A refused
  download (429) is not a print: with Nepal VAT mode on it does not raise the print count or write a `printed`
  activity row. Before, a client could loop on its own PDFs and inflate the print counter without limit.
