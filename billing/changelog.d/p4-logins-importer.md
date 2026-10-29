### Fixed

- **Adding a client login, sending a new invitation or a password reset no longer shows an error page when the mail
  server is down (#443).** The login stays added and staff get a warning saying the invitation was not sent; a new
  invitation that could not be sent leaves the earlier link as it was; a password reset that could not be sent is
  refused with a message. Each failure is reported and written to the activity log as `account.notice_failed`.
- **A staff email change whose notice to the new address alone fails no longer says the previous address was not
  told (#443).** The general "security notice could not be delivered" warning is shown instead.
- **The "Invited" badge on a client's logins shows only while the invitation still works (#97).** A login that has
  signed in, set a password or turned on two-factor authentication since shows no badge, and its menu offers a
  password reset.
- **Client import: a row that is not UTF-8 text is named in the upload refusal (#171).** The company-name duplicate
  check reads matching clients a page at a time, so its memory no longer grows with the number of clients that share
  a first word.
- The email template preview takes the invitation validity from the same setting as the real invitations (#97).

### Security

- **A client-login invitation stops working once the login's password changes by any path (#97).** The invitation
  keeps a fingerprint of the password hash it was sent with. Upgrade note: migration
  `2026_11_03_000200_add_password_fingerprint_to_client_invitations` adds a nullable column to `client_invitations`
  (expand only); invitations sent before it keep the earlier checks until they are accepted or replaced.
