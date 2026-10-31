### Security

- **The hourly wrong-password budget holds under concurrent guesses (#523).** A step-up, password-change or
  account-deletion guess is counted before the password is compared and refunded when it was not wrong, so parallel
  requests can no longer get past the 20-an-hour cap. The wrong password that uses the cap up now also signs that
  session out (it cycles the remember-me token too) and says why. Signing in again and "Forgot password"
  still work, and step-up stays blocked until the hour is over.
- **Failed step-up by a client login shows on its client account (#523).** The `auth.confirm_password_failed`
  activity entry now carries the client account the login was acting for, so guessing on a sub-login appears in that
  client's activity in the admin area. Entries for staff logins are unchanged. A new `auth.password_guesses_exhausted` entry records the
  sign-out.
- **Entering a provisioning secret needs step-up (security review L3).** Saving a product's provisioning settings or
  an add-on with a new secret driver value now asks for the password first, as other secret settings do. Saves that
  leave secrets blank (kept) or change only other settings are unaffected.
