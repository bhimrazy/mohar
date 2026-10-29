### Security

- **Wrong passwords while signed in are limited per login.** Step-up confirmation (`POST /user/confirm-password`),
  password change (`PUT settings/password`) and account deletion (`DELETE settings/profile`) share one budget of
  wrong passwords: 5 a minute per login and IP, and 20 an hour per login from any IP. Correct passwords never count.
  Once a budget is used up, these checks are refused before the password is compared, even a correct one: "Too many
  password attempts. Try again in n seconds." or "Too many wrong passwords. Try again in n minutes." JSON clients get
  429 with `Retry-After`; forms show the message on the password field. Sign-in and "Forgot password" are not
  affected. Each failed step-up confirmation writes an `auth.confirm_password_failed` entry to the activity log (who
  and from where, never the password). Before, a stolen session could guess the password without limit and then
  pass every step-up, and account deletion had no limit at all.
- **Invoice, quote and credit or debit note PDF downloads are rate limited.** The client-area and admin PDF routes
  share the `billing-documents` limiter: 10 downloads a minute per client login, 60 per staff member. A refused
  download (429) is not a print: with Nepal VAT mode on it does not raise the print count or write a `printed`
  activity row. Before, a client could loop on its own PDFs and inflate the print counter without limit.
