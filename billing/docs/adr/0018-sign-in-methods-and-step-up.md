# ADR 0018 — Sign-in methods and step-up

- Status: Accepted · 2026-10-22 (proposed 2026-10-19) · Owner: Principal architect
- Related: ADR 0001 (modular monolith), ADR 0009 and 0012 (kernel extension contracts, registries), ADR 0011 (events
  after commit), `docs/security/standards.md` §2 (step-up), `billing/docs/product/social-sign-in.md`, PR #102
  (architect check), issue #81, PR #89 (structural tests for secrets and step-up).

## Context

Social sign-in (#102) is the first sign-in method that does not come from Fortify. The architect check found that
the things it must reuse live in places a module cannot import:

- the login refusals: `EnsureActiveUser` and `LoginLockout` are in Platform, and the lockout is keyed to the
  `login.store` route;
- step-up: Platform registers `password.recent`, and Billing registers its own copy when Platform is absent. With
  `MODULES=Services` or `MODULES=Notifications`, sensitive routes return 500 (#81);
- client sign-up: the login, client, owner link and `ClientRegistered` steps are written out in `CreateNewUser` and
  again in Orders' `RegisterCheckoutClient`;
- the security-notice fallback is `Platform\Notifications\SecurityNotice`;
- the login view props are built in `app/Providers/FortifyServiceProvider.php`, which would have to name each
  provider.

A login without a password (`users.password` null) is also new. Step-up and the "last way to sign in" rule have to
say what that login can do.

## Decision

### 1. Sign-in methods registry

`App\Support\Auth\SignInMethods` is a kernel singleton. Modules register `App\Data\Auth\SignInMethod` (final,
readonly) in `bootModule()`: `key`, `label`, `startUrl`, `staff` (shown on the admin login), and an optional
`IntegrationLogo`. The register call takes a closure that is evaluated per request, so a method can disappear when an
administrator turns it off.

The Fortify login and register views pass `signInMethods` from the registry. They never name a provider. Passwords
and passkeys stay native Fortify features and are not registered.

### 2. Sign-in gate

`App\Contracts\Auth\SignInGate::refusal(User $user, string $ip): ?string` returns the message a password login would
show (disabled login, closed client, lockout), or null.

- Platform implements it from `EnsureActiveUser` and `LoginLockout`, so there is one rule set.
- Core binds an allow-all null implementation with `bindIf`.
- Failures that a method reports through `SignInGate` count towards the same lockout as failed passwords.

### 3. One way to complete a sign-in

`App\Support\Auth\CompleteSignIn::for(Request, User, string $method): RedirectResponse` is the only code outside
Fortify that logs a user in. In order, it:

1. asks `SignInGate` and returns to the login page with the refusal;
2. when the login has confirmed 2FA, puts `login.id` and `login.remember = false` in the session and redirects to
   `two-factor.login`. These are Fortify's challenge keys, and only this class writes them;
3. otherwise calls `Auth::login($user, false)`, regenerates the session and redirects to the intended URL, which
   must be on `APP_URL`.

The staff 2FA enrolment rule stays in Platform's `EnforceStaffSecurity`, which runs on every request whichever
method was used.

A kernel test pins the Fortify contract: a user handed over by `CompleteSignIn` gets the challenge, and a wrong code
signs nobody in. A Fortify upgrade that renames the keys fails that test, not production.

No sign-in method counts as a second factor or as a password confirmation.

### 4. Step-up: `password.recent`, once, in the kernel

- `RequireRecentPasswordConfirmation` moves from Platform to `App\Http\Middleware`. The alias is registered in
  `bootstrap/app.php`, and Billing's `ConfirmPasswordForAction` copy is deleted (#81).
- The window is `auth.step_up_seconds`, read from `AUTH_STEP_UP_SECONDS` (default 900 when unset or empty; a whole
  number from 60 to 3600, and any other value fails when the configuration loads). It does not change
  `password_timeout`.
- It is the only step-up mechanism. It reads `auth.password_confirmed_at`, which only Fortify's `confirm-password`
  sets. No module adds another form of step-up, such as an emailed link, a provider re-authentication or a module
  timestamp.
- A passkey assertion may count later only by setting the same timestamp, and only through an amendment to this ADR.
- A login without a password cannot pass step-up. The 423 or validation response tells the UI to offer "Set a
  password" (the reset link), not the confirm dialog. Shared props carry `auth.user.has_password`.
- A kernel test boots each module alone (`MODULES=<module>`) and checks that every route's middleware resolves.

### 5. Logins without a password

- `users.password` is nullable. `User::hasPassword()` is the only check, and `@property ?string $password`.
  Laravel already refuses a null hash in `validateCredentials`, `SessionGuard::login` and `AuthenticateSession`,
  and the hasher behind the `current_password` rule refuses it too.
- **Staff logins always have a password.** The `User` saving hook refuses `is_staff = true` with a null password,
  and so does every staff invitation or promotion path. Staff step-up must always be possible.

### 6. Client registration in Core

`App\Modules\Core\Actions\RegisterClientAccount` creates the login, the client and the owner link in one
transaction. After commit it dispatches `ClientRegistered` and Laravel's `Registered`. Its input is a
`Data\Core\NewClientAccount`: name, email, an optional password, and an optional email-verified time.

`CreateNewUser`, Orders' checkout registration and social sign-up all call it. Whether sign-up is open is a separate
question: `security.client_signup_enabled` is defined in Core and covers `/register` and social sign-up, not
checkout.

### 7. Security notices in Core

`SecurityNotice` moves to `App\Modules\Core\Notifications`. It is the fallback any module sends when
`SystemMailer::send()` returns Unavailable, never when it returns Refused. A module that needs a security template
registers its key with `EmailTemplates`, and Core's fallback renders the same subject and body with no theme.

### 8. The last way to sign in

A login always keeps at least one way to sign in:

| Method          | Counts when                                                            |
| --------------- | ---------------------------------------------------------------------- |
| Password        | `hasPassword()`                                                        |
| Passkey         | at least one registered passkey                                        |
| Social identity | its provider is enabled now, and staff sign-in is on for a staff login |

- `App\Contracts\Auth\SignInMethodCounter::count(User): int` is a kernel contract. Core counts passwords and
  passkeys. Each module that adds a method decorates the counter with its own count, so Core never reads a module's
  table.
- Removing a passkey, disconnecting an identity or clearing a password is refused, with the reason shown, when the
  count would drop to 0.
- A password reset always stays available. It is the recovery path when a provider is turned off, so it is not
  counted.

## Consequences

- PR 0 (kernel, CTO) lands before the social sign-in module. It contains:
    - `SignInMethods` and `SignInMethod`;
    - `SignInGate` (Platform implementation, Core null);
    - `CompleteSignIn`;
    - `password.recent` in the kernel;
    - `users.password` nullable and `hasPassword()`;
    - `RegisterClientAccount` (replacing two copies);
    - `SecurityNotice` in Core;
    - `SignInMethodCounter`.

    It closes #81.

- Platform keeps the lockout policy, staff 2FA enforcement and the settings UI. It loses the step-up middleware and
  the notice class.
- Contract evolution follows ADR 0010. `SignInMethod` gains only trailing defaulted fields. A new kind of method
  needs a registry entry and a counter decorator, not a kernel edit.
- Risk: `CompleteSignIn` depends on Fortify's private session keys. The pinned test and the 7-day upgrade cooldown
  (ADR 0007) contain that risk.

## Addendum (2026-10-21, client importer #184): email verification on an email change

- **Client logins.** The `User` saving hook clears `email_verified_at` when a client login (`is_staff` false) is saved
  with a new email, unless the same save sets it. A model hook is the one place every writer passes through, since
  there is no single change-email action. Query-builder `update()` bypasses it, so `users.email` is never written
  that way.
- **Staff logins.** The hook leaves them alone, and Platform's `UpdateStaff` owns the rule for the staff page. A change
  made by another admin clears verification and sends the verification mail. A staff member's own change keeps it.
  Both addresses get the change notice either way. Admin routes require `verified`, so clearing a self-change with no
  mail would send the only full admin to the verify prompt. Settings → Profile (`ProfileController`) still clears
  verification on any self-change, staff included.
- **What verification says.** For a client login it also bounds the last email change, because a change clears it.
  `LoginImporter::linkable()` relies on that: it links a non-staff login whose email was verified at least 30 days
  ago.
- **`users.email_changed_at` is deferred.** While the hook clears verification on every client email change, "verified
  30 days ago" already implies "unchanged for 30 days", and no consumer needs the two facts apart. The column is added,
  set in the same hook, with the first consumer that does: linking a social identity by verified email (§1), or a
  rule for staff. `clients.email_changed_at` stays the column for the client record.

## Addendum (2026-10-23, #388): changing the sign-in email needs step-up

- A login's own email change (Settings → Profile) needs `password.recent`, like the staff edit (`UpdateStaff`), which
  already had it. Whoever controls the email controls password resets, so the change is account recovery and a session
  alone must not be able to make it. A name-only save needs no step-up.
- A login without a password cannot pass step-up (§4), so it cannot change its email until it sets a password. No
  emailed confirmation link is used instead: that would be a second step-up mechanism, which §4 rules out.
- After every change the old address gets a notice with the new address partly masked (`auth.email_changed`, or
  `staff.email_changed` for staff), and the new address must be verified again. This holds for a self-change on both
  paths (Settings → Profile and Admin → Staff → Edit) and for a staff member editing someone else: the new mailbox
  receives resets and has not been proven. A staff member who changes their own email is sent to verify before
  returning to the admin area.
- If the old-address notice is refused or suppressed by the template system, no other path sends it; the person
  sees a warning and the activity log records the outcome.
- `SensitiveRouteStepUpTest` lists the routes known to write `users.email` and checks each refuses without step-up.
  A new route that changes a login's email is added there.

## Amendment (#459) — 2026-10-24, one-time password expiry and the step-up download key

- **Expiry is a property of the password.** A one-time password (from `app:install --admin-email` or
  `app:admin-password`) carries `users.password_expires_at`, 24 hours after it was issued. Only the password method
  enforces it, so `SignInGate` does not; passkey sign-in is unaffected. `User::passwordExpired()` is the one check.
- **Where it is refused** (`App\Actions\Fortify\RefuseExpiredPassword`, always after the password itself checked out,
  so the message tells nothing to someone without it):
    - at password sign-in, before the two-factor challenge (`RememberValidatedLogin`) and on Fortify's fallback step
      (`SignInValidatedUser`), because the challenge signs in without the pipeline's later steps;
    - when a challenge started before the expiry is completed after it (a `ValidTwoFactorAuthenticationCodeProvided`
      listener clears `login.id` and returns to the login page);
    - at step-up (§4): `password.recent` treats a login with an expired one-time password as not confirmed, even in a
      session that confirmed before the expiry, and `confirm-password` refuses it. Such a login recovers with "Forgot
      password" or `app:admin-password`.
    - Each refusal writes `auth.password_expired` (the step only, no secret).
- **Any password change clears it**, with the forced change, in the `User` saving hook, unless the same save sets a
  new expiry: that save writes a one-time password, so the issuer needs one save.
- **A reissue ends the old credential's access**: `app:admin-password` rotates the remember token and deletes the
  login's stored sessions (`LoginSessions`), and mails the administrator (`staff.password_reissued`).
- **The step-up download key holds a URL.** `RequireRecentPasswordConfirmation::DOWNLOAD_KEY` stores the file URL that
  sent the person to the confirm page, not a flag. The page calls itself a download only while that URL equals
  `url.intended`, and a dialog (JSON) confirm drops `url.intended` only when it is that download.
