### Fixed

- **When mail is sent without the template system (Notifications module absent, or the template not installed), adding
  a client login, sending a new invitation or a password reset no longer shows an error page if the mail server is
  down (#443).** The login stays added and staff get a warning that the invitation was not sent (and, when it also
  failed, the notice to the account owner); a new invitation that could not be sent leaves the earlier link as it was;
  a password reset that could not be sent is refused with a message. Each failure is reported and written to the
  activity log as `account.notice_failed`. With the template system, mail is queued and a down server never reached
  the request.
- **Staff can send an invitation to a login whose first invitation was not sent (#443).** The login's menu offers
  "Send an invitation" for a login nobody has used and that has not verified its email, the logins staff add. Such an
  invitation replaces the login's password with a new random one and ends its other sessions, so only the link sets a
  password.
- **A sign-up whose verification email cannot be sent (mail server down, no template system) no longer shows an
  error page.** The person is signed in and asked to resend the email; the failure is reported and logged as
  `auth.email_verification_failed`.
- **A staff email change whose notice to the new address alone fails no longer says the previous address was not
  told (#443).** The general "security notice could not be delivered" warning is shown instead.
- **The "Invited" badge on a client's logins shows only while the invitation still works (#97).** A login that has
  signed in, set a password or turned on two-factor authentication since shows no badge, and its menu offers a
  password reset.
- **Client import: a row that is not UTF-8 text is named in the upload refusal (#171).** The company-name duplicate
  check reads matching clients 2,000 at a time, as id and name only, so its memory no longer grows with the number of
  clients that share a first word.
- The email template preview takes the invitation validity from the same setting as the real invitations (#97).

### Security

- **A client-login invitation stops working once the login's password changes by any path (#97).** The invitation
  keeps a fingerprint of the password hash it was sent with. Accepting it now locks the login row and checks it as
  locked.
- **Staff can no longer add an existing login that never verified its email to a client account, make such a login
  the owner, or move it to another client by merging (security review 2026-10-29, M2).** Holding a login with an
  unverified address proves nothing about the mailbox. Adding is refused with what the person must do (verify the
  address from their own account); a merge is refused with the unverified logins listed, a login with a pending
  invitation included, so staff wait for it to be accepted or remove the login first. "Make owner" is no longer
  offered for such a login.
- **A password reset, a password change and accepting an invitation now end the login's other sessions (M2).** Stored
  sessions are deleted and remember-me cookies stop working; a change keeps the session that made it. Other session
  drivers than `database` cannot be searched by login, so only the remember-me cookies end there.
- Upgrade note: migration `2026_11_03_000200_add_password_fingerprint_to_client_invitations` adds a nullable column to
  `client_invitations` (expand only). Invitations sent before it keep the earlier checks until they are accepted or
  replaced. After the upgrade, signed-in users who reset or change their password are signed out on their other
  devices.
