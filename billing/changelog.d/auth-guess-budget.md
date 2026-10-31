### Security

- **The hourly wrong-password budget holds under concurrent guesses (#523).** A step-up, password-change or
  account-deletion guess is counted, with the cache store's atomic add and increment, before the password is
  compared, and refunded when it was not wrong, only into the hour it was counted in. Parallel requests no longer get
  past the 20-an-hour cap, also after a correct password has brought the count back down; the one remaining gap is a
  refund that checks its hour in the same instant the hour ends. Guesses still being compared count, so under
  concurrency the cap can be reached slightly early.
- **The wrong password that uses up the hourly budget signs that session out (#523).** The step-up dialog and forms
  go to the sign-in page, which says why. Other sessions of the login stay signed in, but signing out resets the
  login's remember-me token, so "remember me" stops working on every device. Signing in again and "Forgot password"
  still work; step-up stays blocked until the hour is over. A new `auth.password_guesses_exhausted` activity entry
  records the sign-out.
- **Failed step-up by a client login shows on its client account (#523).** The `auth.confirm_password_failed`
  activity entry now carries the client account the login was acting for, so guessing on a sub-login appears in that
  client's activity in the admin area. Entries for staff logins are unchanged.
- **Provisioning settings that hold secrets need step-up (security review L3).** Saving a product's provisioning
  settings or an add-on asks for the password first when a secret driver value is entered, or when settings that
  hold a stored secret change in any way or move to another driver. Saving them unchanged, with secrets left blank,
  does not. A login without the catalog permission gets 403 before any prompt, and a login without a password is
  told to set one.
