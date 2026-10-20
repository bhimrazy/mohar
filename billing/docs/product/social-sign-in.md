# Social sign-in — Product Spec (v1)

Status: revised after the architect check on #102 · Owner: social sign-in workstream · Approved by the owner 2026-10-19
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
- **SS-3 (MUST)** Microsoft has a tenant setting: `common` (default), `organizations`, `consumers`, a tenant GUID or a
  domain name. The value goes into the URL path, so anything else (`../`, slashes, spaces, a query) is refused
  when the setting is saved and again when a flow starts.
- **SS-3a (MUST)** Microsoft identities come from the ID token returned by the token endpoint over TLS (OpenID Connect
  Core §3.1.3.7, so no JWT library and no signature check). The provider reads `oid`, `tid` and `xms_edov` and refuses
  the token unless `aud` is our client ID, `iss` is the issuer for that `tid`, and `exp` is in the future. It never
  requests `offline_access`.
- Socialite ships Google and GitHub. Microsoft is not in Socialite core, so we write our own provider on Socialite's
  `AbstractProvider` (about 100 lines, no community package; architect decision). All three are thin subclasses that
  take their URLs from `Endpoints` and make their HTTP calls through Laravel's `Http` (§7).

## 3. Admin settings

A settings section "Social sign-in", registered by the module and guarded by the new sensitive permission `core.social_sign_in.manage`. Wildcards never
grant it, every save needs a recent password (§2 of `docs/security/standards.md`), and the #89 structural tests cover it
without changes: `SecretSettingsTest` sees the `client_secret` keys, and `SensitiveRouteStepUpTest` sees the section on
the settings route. PR 1 is rebased on #89 and its tests confirm both.

`security.client_signup_enabled` is defined in **Core** (ADR 0018 §6), not in Platform's security block, because
Fortify's `/register` and the module both read it with Platform absent.

| Setting                           | Control             | Default | Notes                                                                          |
| --------------------------------- | ------------------- | ------- | ------------------------------------------------------------------------------ |
| `social.<provider>.enabled`       | toggle              | off     | Shown on the login and sign-up pages only when on and both credentials are set |
| `social.<provider>.client_id`     | text                | empty   |                                                                                |
| `social.<provider>.client_secret` | secret (write-only) | empty   | `secret: true`: encrypted at rest, never in props, masked in the audit diff    |
| `social.<provider>.staff_enabled` | toggle              | off     | Staff may sign in with this provider                                           |
| `social.<provider>.staff_domains` | list (one per line) | empty   | Required when staff sign-in is on: the provider email must be in one of them   |
| `social.microsoft.tenant`         | select or tenant ID | common  |                                                                                |
| `security.client_signup_enabled`  | toggle (Core)       | on      | Closes `/register` and social sign-up together (§5)                            |

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
  closed clients and the login lockout refuse it with the same messages (`SignInGate`). A failed provider sign-in for a
  known login counts towards the same lockout as a failed password (ADR 0018 §2).
- **SS-10 (MUST)** A login with 2FA goes through the existing two-factor challenge after the provider returns. The
  provider never counts as a second factor. A staff login without 2FA while `security.staff_2fa_required` is on is sent
  to enrolment, as after a password login.
- **SS-11 (MUST)** A provider sign-in never counts as a password confirmation. Step-up (`password.recent`,
  `password.confirm`) still asks for the password.
- **SS-12 (MUST)** Staff: provider sign-in works only when that provider's `staff_enabled` is on, the provider email is
  **verified** (§2) and its domain is in `staff_domains`, and the identity is already linked to that staff login (§6). It never creates a staff
  login and never links one by email.
- **SS-13 (MUST)** The session is regenerated on sign-in, and "remember me" is off for provider sign-in.

## 5. Sign-up

- **SS-14 (MUST)** An identity that is not linked, with an email no login uses, creates a client account (login,
  client, owner link, `ClientRegistered`, through Core's `RegisterClientAccount`) only when all hold:
  `security.client_signup_enabled` is on, the provider is
  enabled, and the provider reports the email as verified (§2). Otherwise the user sees why and, when sign-up is open,
  is offered the normal sign-up form.
- **SS-15 (MUST)** The new login has no password. It is marked email-verified, because the provider verified it.
- **SS-16 (MUST)** `security.client_signup_enabled` off also closes `/register`: the page says sign-up is closed, and
  `POST /register` is refused in `CreateNewUser`, not only hidden. Today registration is always open; this setting is the
  single switch. It does **not** cover the account created at checkout (Orders), so a closed sign-up still lets a buyer
  create an account by ordering; the owner can widen it later.

## 6. Account linking

### 6.1 The provider email matches an existing login

- **SS-17 (MUST)** Never link silently, even when the provider says the email is verified. Show "An account already
  uses this address" with two ways to prove ownership:
    1. **Log in with your password.** The pending identity is kept in the session for 10 minutes. After the password and,
       when the login has it, the 2FA challenge, it is offered only if the login that signed in is the one whose email
       matched, and linked only after an explicit "Connect Google (ann@example.com)?" confirmation. Any other login
       discards it.
    2. **Email me a link**, offered only when the provider reports the email as verified (§2). A single-use link, valid
       for 30 minutes, goes to the login's address (template `account.sign_in_method_confirm`). The mail names the
       provider and the provider account (its email) being connected. The link is bound to the browser that started the
       flow: a random nonce in a cookie, stored hashed with the token, must match when the link is opened, so a link
       forwarded to or opened in another browser is refused. Opening it links the identity and then signs in through
       SS-10, so 2FA still applies. The token is stored hashed (standards §6). The mail follows the secret-link rules: no
       CC or BCC, masked in the stored copy, never resent.
- **SS-18 (MUST)** Staff logins get only option 1.
- **SS-19 (MUST)** Both answers are the same whether the address belongs to a client or a staff login, so the page does
  not reveal which.

### 6.2 Connected accounts in the profile

- **SS-20 (MUST)** Profile → "Connected accounts" lists each enabled provider: connected (with the provider email and
  the date), or a "Connect" button.
- **SS-21 (MUST)** Connecting or disconnecting from the profile needs a recent password confirmation (`password.recent`),
  the one step-up mechanism. Connecting adds lasting access to the account, so a stolen session must not be able to do
  it. A login without a password sets one first (SS-25).
- **SS-22 (MUST)** "Disconnect" is disabled, with the reason, when it would leave the login with no way to sign in
  (ADR 0018 §8). The kernel `SignInMethodCounter` counts a password and passkeys; this module decorates it with the
  login's identities whose provider is enabled now (and, for a staff login, whose staff sign-in is on). A password
  reset is recovery, not a sign-in method, so it is not counted.
- **SS-23 (MUST)** An identity connected to another login is refused ("This Google account is connected to a different
  login"). One identity per provider per login.
- **SS-24 (SHOULD)** Staff with `core.users.manage` see a client login's connected providers and can disconnect one
  (step-up, audited, notice sent). Staff logins are managed by `core.staff.manage` the same way.

### 6.3 Logins without a password

- **SS-25 (MUST)** The profile offers "Set a password", which sends the password-reset link. Every surface with a
  confirm-password dialog (connect, disconnect, enabling 2FA, every `password.recent` action) and the settings password
  form (which requires the current password) show "Set a password" instead when `User::hasPassword()` is false.
- **SS-25a (MUST)** Staff logins always have a password, because step-up depends on it. Promoting a passwordless login
  to staff, or accepting a staff invitation with a passwordless login, is refused with the reason.

## 7. Outbound calls

- **SS-26 (MUST)** Every call to a provider (token, userinfo, user emails) goes through
  `Endpoints::for('social.<provider>.<api>', <default>)`, as the payment drivers do. The guarantee is the `Endpoints` one
  (ADR 0016 §3): the provider hosts are fixed defaults, not staff-entered, so with no override the request goes to that
  host with TLS verification on; an override, honoured only in `local` and `testing`, keeps TLS verification on against
  the scenario CA and is resolved and pinned through `OutboundAddressGuard`. There is no extra production guard call
  for fixed hosts. Every call also has a 5 s connect and 10 s total timeout and follows no redirects. The browser
  redirect to the authorize URL uses the same `Endpoints` base, so a simulator replaces the whole provider.
- **SS-27 (MUST)** Socialite's own HTTP client is raw Guzzle (`AbstractProvider::getHttpClient()`), which would skip the
  endpoint options. Our provider classes override `getAccessTokenResponse()` and `getUserByToken()` (which covers
  GitHub's `/user/emails`) and call
  `Http::withOptions($endpoint->options)->connectTimeout(5)->timeout(10)->withoutRedirecting()`. An arch test fails if
  anything in the module calls `getHttpClient()`, and a feature test fails if a provider call is sent without the
  endpoint options, the timeouts or `allow_redirects: false`.
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
- **SS-39 (MUST)** Dev-only simulators in `billing/tools/simulators` (ADR 0016): one shared OAuth core in
  `src/Kernel` with three registered keys, `google-oauth`, `github-oauth` and `microsoft-identity`, each with its own
  `reference()`. They speak each provider's authorize, token and profile endpoints over TLS; the Microsoft one issues
  ID tokens with `iss`, `aud`, `tid`, `oid` and `xms_edov` (unsigned, as the app does not check signatures). It checks the client secret, the redirect URI, PKCE (S256) and
  single-use codes. Control actions pick the identity (subject, email, verified flag), deny consent, return an error,
  or replay a code. A test in the `Simulators` suite runs sign-up, sign-in with 2FA and linking against it, through
  `Endpoints` and the address guard. The three rows are added to ADR 0016 §2.
- **SS-40 (MUST)** Screenshots in `docs/screenshots/social-sign-in/` (optimised with `npm run images:optimize`): login
  with providers, sign-up, "an account already uses this address", Connected accounts, and the admin settings, in light
  and dark.

## 11. Data

- `social_identities`: `id`, `user_id` (FK, cascade on delete), `provider` (string 32), `provider_user_id`
  (string 255, the OIDC maximum for `sub`), `email` (string 255, nullable), `linked_at`, `last_used_at` (nullable), timestamps. Unique
  `(provider, provider_user_id)` and `(user_id, provider)`.
- `social_link_confirmations`: `id`, `user_id` (FK, cascade), `provider`, `provider_user_id`, `email`, `token_hash`
  (unique), `browser_nonce_hash`, `expires_at`, `consumed_at` (nullable), `created_at`. Pruned by housekeeping after expiry.
- `users.password` becomes nullable for clients (SS-15), with `User::hasPassword()` and `@property ?string $password`
  (kernel, PR 0). A null password never matches: `EloquentUserProvider::validateCredentials`, the `current_password`
  rule and `confirm-password` all refuse it, and `SessionGuard`/`AuthenticateSession` skip it.

## 12. Architecture

- A new module `app/Modules/SocialSignIn` (architect decision). Not Core, the always-loaded base, which should not carry
  a vendor OAuth dependency or provider tables; not Platform, which is already large. It must work when Notifications
  and Platform are absent.
- It depends only on kernel pieces, added first in **PR 0** and specified by
  [ADR 0018](../adr/0018-sign-in-methods-and-step-up.md) ("Sign-in methods and step-up"):
    - `App\Support\Auth\SignInMethods`, a registry of `App\Data\Auth\SignInMethod` (key, label, start URL, whether it
      is offered to staff). Fortify's login and register views read it; the module registers its providers. Login view
      props never name a provider. Passkeys can use it later.
    - `App\Contracts\Auth\SignInGate::refusal(User, string $ip): ?string`. Platform implements it (disabled login,
      closed client, lockout) and Core binds an allow-all null. Plus one kernel `CompleteSignIn` helper: it sends a 2FA
      login to the challenge (`login.id`, `login.remember=false`) or logs in, regenerates the session and redirects to
      the intended URL. Only this helper writes Fortify's session keys; a test fails if Fortify's challenge stops reading
      `login.id`.
    - The `password.recent` middleware moves to the kernel (`app/Http/Middleware`), so there is one implementation, and
      Billing's fallback copy goes (issue #81). A test boots each module alone and checks its routes resolve.
    - A Core `RegisterClientAccount` action (login, client, owner link, `ClientRegistered` after commit), replacing the
      copies in `CreateNewUser` and Orders' `RegisterCheckoutClient`.
    - `SecurityNotice` moves to Core, as the `SystemMailer` Unavailable fallback.
    - `users.password` nullable and `User::hasPassword()`, the staff-always-have-a-password invariant (SS-25a), and the
      `security.client_signup_enabled` definition with the `/register` refusal (SS-16).
    - `App\Contracts\Auth\SignInMethodCounter` (SS-22), counting passwords and passkeys in Core.
- Mail goes through `App\Contracts\Notifications\SystemMailer`; the templates are added to the Notifications catalogue
  as always sent.
- Rules from ADR 0018: a provider is never a second factor and never a step-up; `password.recent` is the only step-up,
  and passkeys do not count for it yet; only `CompleteSignIn` completes a sign-in outside Fortify.
- Dependency: `laravel/socialite`, an exact version at least 7 days old that supports Laravel 13, with
  `composer deps:check` passing (ADR 0007). Its dependency `league/oauth1-client` comes with it; we use no OAuth 1
  provider.

## 13. Delivery

Three PRs, each under 1,500 changed lines:

0. **Kernel (PR 0):** everything in §12's list, with its tests. No provider code.
1. **Module (PR 1, rebased on #89):** Socialite pin, module, settings and permission, migrations, the three providers
   through `Endpoints` and `Http`, sign-in, sign-up, linking (both paths), unlinking, throttles, audit, notice templates,
   and the SS-38 tests.
2. **UI and simulators (PR 2):** login and sign-up buttons, Connected accounts, "Set a password" surfaces, admin
   settings polish (redirect URIs), the three simulator keys with conformance and `Simulators` suite tests, the ADR 0016
   rows, and screenshots.

## 14. Acceptance criteria

1. Given Google enabled with credentials, When a guest signs in with a Google identity linked to a client login without
   2FA, Then they land in the client area and `last_used_at` is set.
2. Given that login has 2FA, Then the two-factor challenge is shown before any page, and a wrong code signs nobody in.
3. Given a Google identity whose verified email no login uses and sign-up open, Then a client account is created with no
   password and a verified email, and "Account created with a sign-in provider" is logged.
4. Given the same with `email_verified` false, or sign-up closed, Then no account is created and the reason is shown.
5. Given a provider email equal to an existing client login, Then nothing is linked until the user logs in with the
   password (and 2FA) as that login and confirms "Connect Google (…)?", or opens the emailed link; afterwards the
   identity is linked to that login and a "connected" notice is sent.
6. Given the emailed link opened twice, after 30 minutes, or in a browser other than the one that started the flow,
   Then it is refused and nothing is linked.
7. Given a provider that reports the email as unverified, Then no emailed link is offered.
8. Given the password path completed by a different login than the one whose email matched, Then the pending identity
   is discarded.
9. Given a callback with a state that does not match the session, Then it is refused and nothing is created or linked.
10. Given a staff login with a linked GitHub identity, `staff_enabled` off, Then provider sign-in is refused; with it on
    and the domain allowed, the staff member signs in and still passes 2FA.
11. Given a staff email that matches a provider email but no linked identity, Then nothing is linked and no email link is
    offered.
12. Given a login whose only sign-in method is one provider, Then "Disconnect" is disabled with the reason.
13. Given a profile connect without a recent password confirmation, Then the password is asked first.
14. Given any provider call, Then it goes through `Endpoints` with its options, a 5 s connect and 10 s total timeout and
    no redirects; an override is honoured only in `local` and `testing`, where it is pinned through the guard.
15. Given a staff member with only `core.settings.update`, Then the Social sign-in section is read-only to them, and
    saving it needs `core.social_sign_in.manage` and a recent password.
16. Given the settings page, Then no client secret is in the props, and the audit diff masks it.
17. Given a passwordless login, When it is promoted to staff or accepts a staff invitation, Then it is refused.
18. Given sign-up closed, When `POST /register` is sent directly, Then it is refused and no account is created.
