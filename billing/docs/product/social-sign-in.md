# Social sign-in — Product Spec (v1)

Status: draft for architecture review · Owner: social sign-in workstream · Approved by the owner 2026-10-19
(`docs/handoff/requirements.md`, "Social sign-in"). Clean-room: behaviour comes from the providers' public OAuth 2.0 and
OpenID Connect references and from how hosting billing products present "Sign in with …"; naming, schemas and wording
are ours.

## 1. Purpose and scope

Clients can create an account and sign in with Google, GitHub or Microsoft instead of a password. Staff can do the same
when an administrator allows it, but social sign-in never replaces staff 2FA or the password step-up. An identity is
connected to a login only after the person proves they own that login.

In scope: the three providers, admin settings, sign-in, sign-up, linking and unlinking, security notices, audit, a dev-only
OAuth simulator, and the UI on the login, sign-up, profile and admin settings pages.

Out of scope for v1: other providers (Facebook, Apple, X, GitLab), SAML or OIDC single sign-on for staff with a tenant's
own identity provider, storing provider tokens to call provider APIs later, avatar import, and social sign-in on the
public API.

## 2. Providers

| Provider  | Protocol                                                  | Identity we keep                         | When the email counts as verified                                                                                |
| --------- | --------------------------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Google    | OpenID Connect, authorization code + PKCE                 | `sub`                                    | `email_verified` is true in the userinfo response                                                                |
| GitHub    | OAuth 2.0, authorization code + PKCE, scope `user:email`  | numeric user `id`                        | the address is `primary` and `verified` in `GET /user/emails`                                                    |
| Microsoft | OpenID Connect on the identity platform v2.0, code + PKCE | `oid` within `tid` (stored as `tid:oid`) | the ID token carries `xms_edov: true` (an optional claim the host adds in the app registration); otherwise never |

- **SS-1 (MUST)** Each provider is off until an administrator enables it and enters a client ID and secret.
- **SS-2 (MUST)** The identity key is the provider's stable subject, never the email. An email change at the provider
  does not break the link; a recycled email never inherits a link.
- **SS-3 (MUST)** Microsoft has a tenant setting: `common` (default), `organizations`, `consumers` or one tenant ID.
- Socialite ships Google and GitHub. Microsoft is not in Socialite core, so we write a small provider on Socialite's
  `AbstractProvider` (no community package). All three are thin subclasses that take their URLs from `Endpoints`
  (§7).

## 3. Admin settings

A settings section "Social sign-in", guarded by the new sensitive permission `core.social_sign_in.manage`. Wildcards never
grant it, every save needs a recent password (§2 of `docs/security/standards.md`), and the #89 structural tests cover it
without changes: `SecretSettingsTest` sees the `client_secret` keys, and `SensitiveRouteStepUpTest` sees the section on
the settings route.

| Setting                           | Control             | Default | Notes                                                                          |
| --------------------------------- | ------------------- | ------- | ------------------------------------------------------------------------------ |
| `social.<provider>.enabled`       | toggle              | off     | Shown on the login and sign-up pages only when on and both credentials are set |
| `social.<provider>.client_id`     | text                | empty   |                                                                                |
| `social.<provider>.client_secret` | secret (write-only) | empty   | `secret: true`: encrypted at rest, never in props, masked in the audit diff    |
| `social.<provider>.staff_enabled` | toggle              | off     | Staff may sign in with this provider                                           |
| `social.<provider>.staff_domains` | list (one per line) | empty   | Required when staff sign-in is on: the provider email must be in one of them   |
| `social.microsoft.tenant`         | select or tenant ID | common  |                                                                                |
| `security.client_signup_enabled`  | toggle (Security)   | on      | Closes `/register` and social sign-up together (§5)                            |

- **SS-4 (MUST)** The section shows each provider's exact redirect URI, read-only and copyable, built from `APP_URL`
  (§8).
- **SS-5 (MUST)** Enabling a provider without a client ID and secret is refused with the reason next to the toggle.
- **SS-6 (MUST)** Turning a provider off hides its buttons and refuses its callbacks at once. Existing links stay, so
  turning it back on restores them; users whose only sign-in method was that provider are told to reset their password
  (§6).

## 4. Sign-in

- **SS-7 (MUST)** "Continue with Google / GitHub / Microsoft" on the login page starts the authorization code flow with
  `state` and PKCE (S256). The state and verifier live in the session, are single-use and expire after 10 minutes.
- **SS-8 (MUST)** On the callback, a mismatched or missing state, a reused code, a provider error or a cancelled consent
  returns to the login page with a plain message ("Sign-in with Google was cancelled or expired. Try again."). Nothing
  is created or linked.
- **SS-9 (MUST)** A known identity signs in its login, under the same rules as a password login: disabled logins,
  closed clients and the login lockout refuse it with the same messages.
- **SS-10 (MUST)** A login with 2FA goes through the existing two-factor challenge after the provider returns. The
  provider never counts as a second factor. A staff login without 2FA while `security.staff_2fa_required` is on is sent
  to enrolment, as after a password login.
- **SS-11 (MUST)** A provider sign-in never counts as a password confirmation. Step-up (`password.recent`,
  `password.confirm`) still asks for the password.
- **SS-12 (MUST)** Staff: provider sign-in works only when that provider's `staff_enabled` is on, the provider email's
  domain is in `staff_domains`, and the identity is already linked to that staff login (§6). It never creates a staff
  login and never links one by email.
- **SS-13 (MUST)** The session is regenerated on sign-in, and "remember me" is off for provider sign-in.

## 5. Sign-up

- **SS-14 (MUST)** An identity that is not linked, with an email no login uses, creates a client account (login,
  client, owner link, `ClientRegistered`) only when all hold: `security.client_signup_enabled` is on, the provider is
  enabled, and the provider reports the email as verified (§2). Otherwise the user sees why and, when sign-up is open,
  is offered the normal sign-up form.
- **SS-15 (MUST)** The new login has no password. It is marked email-verified, because the provider verified it.
- **SS-16 (MUST)** `security.client_signup_enabled` off also closes `/register` (the page says sign-up is closed).
  Today registration is always open; this setting is the single switch.

## 6. Account linking

### 6.1 The provider email matches an existing login

- **SS-17 (MUST)** Never link silently, even when the provider says the email is verified. Show "An account already
  uses this address" with two ways to prove ownership:
    1. **Log in with your password.** After the password and, when the login has it, the 2FA challenge, the identity is
       linked to that login. The pending identity is kept in the session for 10 minutes and linked only to the login that
       then signs in.
    2. **Email me a link.** A single-use link, valid for 30 minutes, goes to the login's address (template
       `account.sign_in_method_confirm`). Opening it links the identity and then signs in through SS-10, so 2FA still
       applies. The token is stored hashed (standards §6). The mail follows the secret-link rules: no CC or BCC, masked
       in the stored copy, never resent.
- **SS-18 (MUST)** Staff logins get only option 1.
- **SS-19 (MUST)** Both answers are the same whether the address belongs to a client or a staff login, so the page does
  not reveal which.

### 6.2 Connected accounts in the profile

- **SS-20 (MUST)** Profile → "Connected accounts" lists each enabled provider: connected (with the provider email and
  the date), or a "Connect" button.
- **SS-21 (MUST)** Connecting or disconnecting from the profile needs a recent password confirmation. A login without a
  password confirms with an emailed link instead. Connecting adds lasting access to the account, so a stolen session
  must not be able to do it.
- **SS-22 (MUST)** "Disconnect" is disabled, with the reason, when it would leave the login with no way to sign in.
  Ways to sign in are: a password, another connected provider that is enabled, or a passkey.
- **SS-23 (MUST)** An identity connected to another login is refused ("This Google account is connected to a different
  login"). One identity per provider per login.
- **SS-24 (SHOULD)** Staff with `core.users.manage` see a client login's connected providers and can disconnect one
  (step-up, audited, notice sent). Staff logins are managed by `core.staff.manage` the same way.

### 6.3 Logins without a password

- **SS-25 (MUST)** The profile offers "Set a password", which sends the password-reset link. Enabling 2FA and other
  password-confirmed actions ask the user to set a password first.

## 7. Outbound calls

- **SS-26 (MUST)** Every call to a provider (token, userinfo, user emails) uses `Endpoints::for('social.<provider>.<api>',
<default>)` and is resolved and pinned through `OutboundAddressGuard` (connect-to, no proxy, no redirects), with a
  5 s connect and 10 s total timeout. The browser redirect to the authorize URL uses the same `Endpoints` base, so a
  simulator replaces the whole provider in `local` and `testing` only (ADR 0016 §3).
- **SS-27 (MUST)** Socialite gets its Guzzle client from Laravel's HTTP client (`Http::withOptions(...)->buildClient()`),
  so the pinning applies and `Http::fake` works in tests.
- Endpoint keys and defaults: `social.google.auth` `https://accounts.google.com/o/oauth2/v2/auth`,
  `social.google.token` `https://oauth2.googleapis.com/token`, `social.google.userinfo`
  `https://openidconnect.googleapis.com/v1/userinfo`; `social.github.auth` `https://github.com/login/oauth/authorize`,
  `social.github.token` `https://github.com/login/oauth/access_token`, `social.github.api` `https://api.github.com`;
  `social.microsoft.login` `https://login.microsoftonline.com`.

## 8. Security

- **SS-28 (MUST)** The redirect URI is exactly `{APP_URL}/auth/{provider}/callback`. It is built from `APP_URL`, never
  from the request host (#59), and must match the URI registered at the provider.
- **SS-29 (MUST)** No provider tokens are stored. The access token is used once to read the profile and then dropped;
  no refresh token is requested. We store the provider, its subject, the provider email at link time, `linked_at` and
  `last_used_at`.
- **SS-30 (MUST)** Throttles: starting a flow and the callback, 20 a minute per IP; sending the emailed confirmation
  link, 3 an hour per login and 10 an hour per IP; opening it, 10 a minute per IP.
- **SS-31 (MUST)** Audit, with fixed descriptions and the values in properties: "Sign-in method connected",
  "Sign-in method disconnected", "Account created with a sign-in provider", "Signed in with a sign-in provider" (staff
  only). Callback failures are logged at `info` with the reason, never with the code, state or tokens.
- **SS-32 (MUST)** A security notice goes to the login's address whenever a provider is connected or disconnected
  (templates `account.sign_in_method_connected` and `account.sign_in_method_disconnected`, always sent, cannot be turned
  off). Without the Notifications module the `SecurityNotice` fallback sends it.
- **SS-33 (MUST)** Client secrets never appear in props, logs, old input or the module log. Callback query values
  (code, state) are redacted from any log.
- **SS-34 (MUST)** Open redirects are impossible: the return target after sign-in is the stored intended URL, checked
  to be on `APP_URL`.

## 9. UI

- **SS-35 (MUST)** Login and sign-up show one outlined button per enabled provider under the form, separated by "or".
  Provider names and marks are the providers' own; everything else uses the host brand (`BrandProvider`). The admin
  login page (product identity) shows staff-enabled providers only.
- **SS-36 (MUST)** Profile → Connected accounts and the admin settings section follow the product standards: proper
  controls, reasons next to disabled actions, step-up on sensitive buttons, breadcrumb, theme tokens, light and dark.
- **SS-37 (MUST)** Every failure is shown where the user acted, in plain words with what to do next.

## 10. Testing

- **SS-38 (MUST)** Feature tests with `Http::fake` for each provider: sign-in, sign-up, every refusal in §4 and §5, both
  linking paths, unlinking and the last-method rule, 2FA after sign-in, staff rules, throttles, notices and audit.
- **SS-39 (MUST)** A dev-only `oauth` simulator in `billing/tools/simulators` (ADR 0016) speaks the three providers'
  authorize, token and profile endpoints over TLS. It checks the client secret, the redirect URI, PKCE (S256) and
  single-use codes. Control actions pick the identity (subject, email, verified flag), deny consent, return an error,
  or replay a code. A test in the `Simulators` suite runs sign-up, sign-in with 2FA and linking against it, through
  `Endpoints` and the address guard.
- **SS-40 (MUST)** Screenshots in `docs/screenshots/social-sign-in/` (optimised with `npm run images:optimize`): login
  with providers, sign-up, "an account already uses this address", Connected accounts, and the admin settings, in light
  and dark.

## 11. Data

- `social_identities`: `id`, `user_id` (FK, cascade on delete), `provider` (string 32), `provider_user_id`
  (string 191), `email` (string 255, nullable), `linked_at`, `last_used_at` (nullable), timestamps. Unique
  `(provider, provider_user_id)` and `(user_id, provider)`.
- `social_link_confirmations`: `id`, `user_id` (FK, cascade), `provider`, `provider_user_id`, `email`, `token_hash`
  (unique), `expires_at`, `consumed_at` (nullable), `created_at`. Pruned by housekeeping after expiry.
- `users.password` becomes nullable (SS-15). A null password never matches at login.

## 12. Architecture

- A new module `app/Modules/SocialSignIn`. It reads users through the `User` model and Core's client creation, and
  sends mail through `App\Contracts\Notifications\SystemMailer`. It must work when Notifications is absent.
- Kernel touch points, kept minimal and listed in the PR: the Fortify login view props (enabled providers), the
  two-factor handoff (Fortify's session keys `login.id` and `login.remember`), and the `security.client_signup_enabled`
  check on `/register`.
- Dependency: `laravel/socialite`, an exact version at least 7 days old that supports Laravel 13, with
  `composer deps:check` passing (ADR 0007). Its dependency `league/oauth1-client` comes with it; we use no OAuth 1
  provider.

## 13. Delivery

Two PRs, each under 1,500 changed lines:

1. **Core:** Socialite pin, module, settings and permission, migrations, the three providers through `Endpoints` and
   the guard, sign-in, sign-up, linking (both paths), unlinking, 2FA handoff, throttles, audit, notice templates, and
   the SS-38 tests.
2. **UI and simulator:** login and sign-up buttons, Connected accounts, admin settings polish (redirect URIs), the `oauth`
   simulator with conformance and `Simulators` suite tests, and screenshots.

## 14. Acceptance criteria

1. Given Google enabled with credentials, When a guest signs in with a Google identity linked to a client login without
   2FA, Then they land in the client area and `last_used_at` is set.
2. Given that login has 2FA, Then the two-factor challenge is shown before any page, and a wrong code signs nobody in.
3. Given a Google identity whose verified email no login uses and sign-up open, Then a client account is created with no
   password and a verified email, and "Account created with a sign-in provider" is logged.
4. Given the same with `email_verified` false, or sign-up closed, Then no account is created and the reason is shown.
5. Given a provider email equal to an existing client login, Then nothing is linked until the user logs in with the
   password (and 2FA) or opens the emailed link; afterwards the identity is linked to that login and a "connected"
   notice is sent.
6. Given the emailed link opened twice, or after 30 minutes, Then the second or late use is refused.
7. Given a callback with a state that does not match the session, Then it is refused and nothing is created or linked.
8. Given a staff login with a linked GitHub identity, `staff_enabled` off, Then provider sign-in is refused; with it on
   and the domain allowed, the staff member signs in and still passes 2FA.
9. Given a staff email that matches a provider email but no linked identity, Then nothing is linked and no email link is
   offered.
10. Given a login whose only sign-in method is one provider, Then "Disconnect" is disabled with the reason.
11. Given a profile connect without a recent password confirmation, Then the password is asked first.
12. Given any provider call, Then it goes to the address the guard resolved, with no proxy, and an `Endpoints` override
    is honoured only in `local` and `testing`.
13. Given a staff member with only `core.settings.update`, Then the Social sign-in section is read-only to them, and
    saving it needs `core.social_sign_in.manage` and a recent password.
14. Given the settings page, Then no client secret is in the props, and the audit diff masks it.
