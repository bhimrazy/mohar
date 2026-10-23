### Fixed

- **A sign-in email change no longer ends in an error page when the mail server is down (#402).** On Settings →
  Profile and Admin → Staff, the change is saved and a warning says which mail could not be sent. For the profile
  page that includes the verification email, which can be resent from the same page. The failure is reported to
  the error log. The staff notice mails behave the same way for the other callers of the shared account-mail helper.
