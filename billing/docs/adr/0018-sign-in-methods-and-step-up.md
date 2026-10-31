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
  `password_timeout` (which, since #538, opens no route: `password.confirm` uses this window too).
- It is the only step-up mechanism. It reads `auth.password_confirmed_at`, which only Fortify's `confirm-password`
  sets (superseded by #538: its own key, `auth.step_up_confirmed_at`). No module adds another form of step-up, such as an emailed link, a provider re-authentication or a module
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
      listener clears `login.id` and returns to the login page). A recovery code used there is spent: Fortify
      replaces it before the event, and the person held both the password and the code;
    - at every password confirmation, even in a session that confirmed before the expiry: `password.recent` (§4,
      `recentlyConfirmed()`) and Laravel's `password.confirm` (3-hour window; `RequirePasswordUnlessExpired` is bound
      in place of `RequirePassword`) both treat such a login as unconfirmed, and `confirm-password` refuses the
      expired password. That covers the security page, two-factor changes and passkey registration.
      `ExpiredPasswordConfirmationScanTest` calls every route behind either middleware;
    - as the current password (change-password and delete-account forms).
    - Such a login recovers with "Forgot password" or `app:admin-password`.
    - Each refusal writes `auth.password_expired` (the step only, no secret).
- **Any password change clears it**, with the forced change, in the `User` saving hook, unless the same save sets a
  new expiry: that save writes a one-time password, so the issuer needs one save.
- **A reissue ends the old credential's access**: `app:admin-password` rotates the remember token and deletes the
  login's stored sessions (`LoginSessions`, database session driver), and mails the administrator
  (`staff.password_reissued`).
- **Accepted:** a remember-me cookie or session from before the expiry still signs in after it. It can do nothing
  that needs a confirmation or the current password, and `must_change_password` keeps it out of the admin area.
- **The step-up download key holds a URL.** `RequireRecentPasswordConfirmation::DOWNLOAD_KEY` stores the file URL that
  sent the person to the confirm page, not a flag. The page calls itself a download only while that URL equals
  `url.intended`, and a dialog (JSON) confirm drops `url.intended` only when it is that download.

## Amendment (#490) — 2026-10-25, a step-up refusal carries no old input

A mutation refused for the step-up (the `password.recent` middleware, and form requests that check the step-up
themselves, such as the platform settings sections) answers with a redirect back that carries the
`password_confirmation` error and no old input. The middleware runs before the route's form request, so flashing the
input would store a typed API key or certificate passphrase in the session store; the confirm dialog replays the visit
itself, so nothing needs the old input. Once the step-up passes, the form request's own validation errors flash the
input without its secrets (`KeepsSecretsOutOfOldInput`; the platform settings request treats every submitted value
except the section's non-secret settings as secret). JSON clients still get 423 (middleware) or a
422 validation response (form requests). First shipped for the middleware in #489, completed for the settings
sections in #490.

## Amendment (#538) — 2026-10-31, step-up has its own session key, and password.confirm is step-up

Security review 2026-10-31 (M1): Fortify's passkey confirmation (`POST /passkeys/confirm`) also sets
`auth.password_confirmed_at`, so §4's "only Fortify's `confirm-password` sets it" no longer held. Passkeys still do
not count as step-up; this restores §4 rather than changing it.

- **Own key.** Step-up reads `RequireRecentPasswordConfirmation::SESSION_KEY` (`auth.step_up_confirmed_at`), written
  only by `markConfirmed()` from `PasswordConfirmedResponse`, which Fortify sends only after the password matched.
  `auth.password_confirmed_at` stays Laravel's and Fortify's and gates nothing here. Every reader uses the new key:
  `recentlyConfirmed()` (and so `password.confirm` and the settings checks) and `ReviewUnlocks`. `ReportExporter`'s
  docblock names it as the timestamp a queued export will carry; no caller passes one yet. Impersonation drops both
  keys.
- **`password.confirm` is step-up.** `RequirePasswordUnlessExpired`, bound in place of Laravel's `RequirePassword`,
  applies `recentlyConfirmed()`: a password confirmation within `auth.step_up_seconds`, never for a login without a
  password. `auth.password_timeout` (3 hours) no longer opens any route. This covers Fortify's passkey registration and
  removal, every two-factor route, the security page and the staff and role pages. Only a page load still goes to the
  confirm page and back. JSON requests get 423.
- **A refused Inertia mutation is answered in place, never by a redirect.** `password.recent`, `password.confirm`
  and `RequireRecentPasswordConfirmation::refusal()` answer it with a plain JSON 423 carrying `X-Step-Up-Required`
  (and `code: password_not_set` for a login without a password). Inertia raises its `httpException` event; the
  confirm dialog cancels it, asks for the password and sends the same visit again, so the form keeps its input and
  callbacks. A redirect back would reload the page the form is on, which is itself gated (the security page) and, for
  two-factor setup, lets Fortify drop an unconfirmed secret. No gated page or endpoint is ever served without a
  recent confirmation, and a refusal changes no server state. The two-factor setup and passkey registration retry
  their own step (`withStepUp`, `useStepUpForm`, `ensureStepUp` in `resources/js`). A plain form post without
  JavaScript still goes back with the `password_confirmation` error. The route arguments (redirect route and timeout)
  are ignored.
- **The confirm page's way out** leads to Settings → Profile for a settings page, and to the dashboard otherwise.
  The window it and the dialog name comes from `auth.step_up_seconds` (shared as `auth.step_up_minutes`).
- **Unused vendor routes.** Fortify always registers `POST /passkeys/confirm` and `/user/confirmed-password-status`
  and has no option to drop them; both read or write only Laravel's key. A test keeps the frontend from calling them.
- **The confirm page has no passkey option**, since a passkey confirmation would not satisfy it. Passkey sign-in is
  unchanged.
- **Notice:** adding a passkey writes `auth.passkey_added` to the activity log and mails the login
  (`auth.passkey_added` or `staff.passkey_added`, always sent; `LoginEmailNotice` when the template system is absent).
  The passkey's name is left out of the mail, since whoever added it chose it.
- Counting a passkey assertion as step-up still needs its own amendment and an owner decision.

## Amendment (#555) — 2026-10-31, a pending password checked once at the source (Accepted)

Status: Accepted 2026-10-31: the owner approved `client-import.md` Q13 (default window 90 days, at most 180; forwarding the typed password once is acceptable; password plus mailbox confirmation for former 2FA accounts). The licence review (Q13(c)) gates marketing, not the build. Research:
`docs/research/2026-10-31-whmcs-login-migration.md`. The #555 design review findings (B2, B5, M3, M6, M7) are folded
in. Companion amendments: ADR 0012 (#555), for contracts that receive secrets, and ADR 0015 (#555), for outbound calls
inside a request.

- **What it is for.** An imported login has a placeholder password that nobody knows (`client-import.md` §6.9).
  Sign-in carry-over (§3.4) checks that login's first password sign-in once against the system it came from. On
  success the typed password becomes the login's own hash. This extends the **password** method. It is not a new
  sign-in method, so nothing is registered in `SignInMethods` (§1).
- **The contract.** `App\Contracts\Auth\PendingPasswordCheck` has three methods:
    - `active(): bool`: whether any source may answer now, so the failure floor applies;
    - `pending(User $user): bool`: whether this login has an open carry-over. It receives no secret;
    - `check(User $user, #[\SensitiveParameter] string $password, string $ip): PendingPasswordOutcome`.

    The outcome is the closed enum `App\Enums\PendingPasswordOutcome`: NotApplicable, Verified, ConfirmByEmail,
    Rejected, Unavailable. Its docblock says it is closed (ADR 0010).
    - `active()` and `pending()` are hot-path reads (ADR 0020): cached, never remote, never throwing.
    - Core binds a null implementation with `bindIf`: both reads false. Migration binds the one real implementation.
      ADR 0012 Amendment (#555) closes the contract to packages: an Arch test and a boot check allow exactly that
      implementation and the null one.
    - `#[\SensitiveParameter]` is on the interface and on every implementation, because PHP does not inherit it.

- **Where it is called (M7).** Only from `Fortify::authenticateUsing`, which both pipeline shapes use, with 2FA on or
  off. The kernel calls it only when all of these hold:
    - the local hash check failed for an existing login;
    - the login is not staff (staff never have a placeholder password, §5);
    - `SignInGate::refusal()` returned null, so a disabled, closed or locked login causes no outbound call;
    - `pending()` is true;
    - the guess budget allows it.

    The call happens inside the `login` limiter and outside any transaction. The kernel wraps `check()` and maps any
    `Throwable` to Unavailable. It logs a fixed sentence and the exception class only.

- **Outcomes.**
    - **Verified:** the implementation has stored the new hash by compare-and-set against the placeholder. The
      pipeline continues as for a local match: refusals, the 2FA challenge, session regeneration.
    - **Rejected:** a failed sign-in. Fortify's `Failed` event fires and the lockout counts it (§2).
    - **Unavailable:** the ordinary failure message, **without** `Failed`, so a source outage locks nobody out (M3).
    - **NotApplicable:** the ordinary failure.
    - **Over the budget, over the cap, or no floor slot:** no call is made; the guess is refunded and `Failed` does
      not fire.
    - Fortify fires `Failed` whenever the `authenticateUsing` callback returns null. So for Unavailable and the
      no-call cases, the callback throws the ordinary failure itself.
    - **ConfirmByEmail:** no session (below).
    - Every refusal uses the ordinary failure text. None says "try again" or anything else that would set imported
      logins apart.
- **Not step-up.** `confirmPasswordsUsing`, `password.recent` and `password.confirm` never call it (§4). A signed-in
  login already has a local password.
- **ConfirmByEmail (B5).** The source said the password is right, but the account had a second factor there, which
  we cannot check.
    - A single-use link goes to the login's own address, bound to the browser.
    - A GET shows a confirm page. A POST with CSRF consumes the link.
    - On the POST, everything is checked again, then the held hash is stored and the email verified. The sign-in
      completes **only through `CompleteSignIn::for($request, $user, 'password')`** (§3).
    - The route pair `login.carry-over.show` and `login.carry-over.confirm` is the one route this amendment allows.
    - This is a sign-in proof for an account in transit, like the social sign-in emailed link (SS-17). It is not a
      step-up mechanism, so §4's "no emailed link" rule stands.
- **Verification.** A carry-over does not set `email_verified_at`, because knowing a password does not prove the
  mailbox. The ConfirmByEmail path does set it. The 2026-10-21 addendum still holds: verified 30 days ago bounds the
  last email change, and a carry-over never changes the email.
- **Enumeration.** While `active()` is true, a failed password sign-in answers no sooner than a floor taken from the
  measured remote p95. The number of requests waiting in the floor is bounded, and a sign-in takes its floor slot
  before any remote call. With no slot free, every email answers at once and no call is made. The message is the
  same for every failure.
- **Secrets (M6).**
    - The implementation runs the call inside `RedactionScope::during()` with a `RedactionSet` of the password and the
      API secret.
    - It never reports an HTTP-client exception.
    - It uses no HTTP-client events.
    - It keeps the password out of request attributes, the session and old input.
    - ADR 0023 needs no new keys: `password2` and `passwordhash` already match its `password` rule.
- **Must not be added.**
    - A second implementer, including any third-party package (ADR 0012 Amendment (#555)).
    - Any other sign-in route or controller for imported logins than the confirmation pair above.
    - A "migration" entry in `SignInMethods`.
    - A per-carry-over domain event.
    - A generic external-password-provider registry.
    - Any column on `users`; the state, including the placeholder, lives in the Migration module's table.
    - Any call from step-up.
- **Consequences.**
    - It is a kernel PR by the CTO (`client-import.md` PR 8a). It lands after this ADR's PR 0 (`SignInGate`,
      `CompleteSignIn`), or brings those two forward.
    - It adds a `kernel-changelog.d` fragment and regenerates `kernel-api.txt`.
    - The atomic guess counting is extracted from `PasswordGuesses`, so there is one implementation of it.
      Carry-over uses its own key prefix, so it never uses up the step-up budget.
    - Contract evolution follows ADR 0010. The enum is closed, so a new outcome needs an amendment.
