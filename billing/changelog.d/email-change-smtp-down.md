### Fixed

- **A sign-in email change no longer ends in an error page when the mail server is down (#402).** On Settings →
  Profile and Admin → Staff the change is saved and a warning says which mail was not sent (the verification email,
  the notice to the previous address, or both). "Re-send the verification email" also warns instead of failing.
  Only mail transport failures are caught; other errors still surface.
- **Security notices that could not be delivered are visible to staff.** A notice that failed (mail server down, or
  refused or held back by the template system) is recorded in the activity log as `account.notice_failed` with the
  recipient, and the toast on staff and client-login changes, two-factor resets and settings changes becomes a
  warning. A failed verification email is logged as `auth.email_verification_failed`.
