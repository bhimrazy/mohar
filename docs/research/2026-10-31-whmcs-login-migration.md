# Bringing WHMCS client logins across without a forced reset, 2026-10-31

Research and decision note. Principal architect. Public WHMCS pages only, all read on 2026-10-31 (§9). All wording is
ours. Nothing was run against a WHMCS install; we have none. Not legal advice: the licence points are owner questions
(§8).

The owner asked us to "analyze and get the best options, also look if we can avoid resetting passwords too". This note
compares five ways to carry client sign-in from WHMCS to Mohar, recommends one, and proposes the spec and ADR changes.
They are in the same PR: `client-import.md` §3.4, and ADR 0018 Amendment (#555).

## 0. Answer in short

- **Recommended: sign-in carry-over through the WHMCS API (option 2, "lazy migration").** WHMCS documents an API
  action, `ValidateLogin`, that checks an email address and password against a WHMCS user. It also says whether that
  user has two-factor sign-in turned on. A client signs in to Mohar with their old email and password. Mohar has no
  usable password for them yet, so it asks the old WHMCS once. If WHMCS accepts the password, Mohar stores its own bcrypt
  hash and never asks again. No password database moves, no export is needed, and it runs on the API the host already
  opened for the importer. That puts it inside the 2026-10-17 API-first decision.
- **Fallback: the invitation mail (option 1) for whoever has not signed in by the cut-off date.** It is already built
  (C3, `client.migration_invitation`). It now reaches only the people who did not come back, not every client.
- **Not now: importing hashes (option 3).** WHMCS has stored client passwords as bcrypt since 6.3, so the hashes would
  work in Laravel. But it needs the host to hand over a database export, which is the licence question the owner is
  unsure about. It stays behind Q1 and Q2.
- **Later, if `ValidateLogin` goes away: WHMCS as an OpenID Connect provider (option 4).** WHMCS documents itself as
  an OIDC provider, and points to it as the successor to `ValidateLogin`. It moves no password at all. But it only
  links an identity: the client still needs a Mohar password or passkey before the old system is switched off. It needs
  per-host setup and a consent screen, and it depends on ADR 0018's sign-in-method kernel, which is not built yet.
- **Two small extras from the docs (option 5).** Clients who signed in to WHMCS with Google can use Mohar's Google
  sign-in when that module ships. A login page that tells people their old password still works keeps support mail
  down. `CreateSsoToken` and a WHMCS-side add-on were considered and rejected (§5).

## 1. What the public docs say

### 1.1 `ValidateLogin` exists, and what it returns

From the API reference ([ValidateLogin](https://developers.whmcs.com/api-reference/validatelogin/)):

- It takes an `email` and the password to check, `password2`. It validates them against "a registered user". In 8.x
  that is the user model (`tblusers`), not the client record. Its response field is `userid`, described as "User ID".
- On success it returns `userid`, a `passwordhash` and `twoFactorEnabled`. The `passwordhash` is not the stored
  password hash. It is a **WHMCS session token**: put into the session key `login_auth_tk`, it signs that user in to
  WHMCS. It is returned **only when two-factor authentication is not required** for the account.
- The one documented error is "Email or Password Invalid", the same answer for an unknown email and a wrong password.
- **Side effects at the source.** A successful check starts a WHMCS session and updates the client's last-login time.
  So `ValidateLogin` is not read-only, and it does not fit the importer's read-only allowlist as written
  (`migration.md` §2.1).
- **Deprecation notice.** The page says that `CreateSsoToken` supersedes it for remote sign-in, and OpenID Connect
  supersedes it for credential checks. It adds that `ValidateLogin` "may be deprecated in the future". Its version
  history lists only "1.0 Initial Version", so the docs give no date for any change, including the 8.0 move to users.
- **Session IP validation.** When that setting is on, the call must go through the local API "to receive a valid hash".
  This affects only the session token, which we discard. Whether the password check itself still answers over the
  remote API is a **verify** item (§7).

What the docs do **not** say:

- **Rate limits.** The classic API documents none. The rate-limit page belongs to the 9.x REST API
  ([REST rate limits](https://developers.whmcs.com/rest-api/rate-limits/)).
- **Bans.** WHMCS bans an IP after failed **admin** logins (default: three failures, 15 minutes) and has a whitelist
  for that ban ([Security settings](https://docs.whmcs.com/9-1/system/general-settings/general-settings-security/)).
  Whether failed client checks through the API count towards any ban is not stated.
- **2FA and a wrong password.** It is not stated whether `twoFactorEnabled` comes back for a wrong password too. We
  assume that a 2FA account answers `success` only when the password is correct, because `passwordhash` is described
  as the thing withheld. This is a **verify** item.
- **Contacts.** Contacts in 8.x have no login: WHMCS 8.0 replaced sub-accounts with users (`client-import.md` §6.11).
  So the action covers every person who can sign in to an 8.x client area: owners and invited users alike.

API access works as it does for the importer: an API credential for an admin whose role grants each action,
optionally limited by the API IP allow-list or an access key
([Authentication](https://developers.whmcs.com/api/authentication/),
[Access control](https://developers.whmcs.com/api/access-control/)).

### 1.2 How WHMCS hashes client passwords

| When | Format | Evidence | Strength |
| --- | --- | --- | --- |
| 4.2.1 to 6.2.x | MD5 with a salt (`hash:salt`) | Third-party wiki ([NDCHost](https://www.ndchost.com/wiki/software/whmcs/client-password-hash)); a staff post in the community forum ([topic 255397](https://whmcs.community/topic/255397-passwords-25-characters-after-upgrade-to-630/)) | Secondary |
| 6.3.0 on | bcrypt through PHP's `password_hash()` (`$2y$`, cost 10 in the samples); rehashed on the first login after the upgrade, and on a reset | Forum staff post citing the 6.3.0 release notes; the deprecated [GetClientPassword](https://developers.whmcs.com/api-reference/getclientpassword/) example returns a `$2y$10$` value | Primary (example) plus secondary |
| 8.0 on | Hashes sit on the user (`tblusers.password`), not the client | Public docs give the column in an SQL example ([Reset a User Password](https://docs.whmcs.com/9-1/clients/client-management-tutorials/reset-a-user-password/)); `GetClientPassword` was deprecated in 8.0 and removed from the docs as non-functional (CORE-15308, [8.0 change log](https://docs.whmcs.com/releases/8-0/8-0-change-log/)) | Primary |
| 9.0 on | New user passwords at most 100 characters (WHMCS-22505); bcrypt reads only the first 72 bytes, as Laravel's does | [9.0 change log](https://docs.whmcs.com/releases/9-0/9-0-change-log/) | Primary |
| 9.1 on | 12-character minimum with a letter and a digit; the last 4 passwords refused | [9.1 release notes](https://docs.whmcs.com/9-1/releases/9-1/9-1-release-notes/) | Primary |
| 9.x, admins | Admin passwords are rehashed "using a more secure algorithm" at first login, and that fails when the password breaks the new policy. The algorithm is not named. Users are not mentioned | [Password Rejected Errors](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-authentication/password-rejected-errors/) | Primary, about admins only |

No public page names the client hashing algorithm for 8.x or 9.x outright. "bcrypt since 6.3" rests on a forum staff
post and a deprecated API example. That is good enough to design option 3, but not to promise it. A host's export would
settle it in one look.

### 1.3 WHMCS as an OpenID Connect provider

- WHMCS can act as an **authentication provider** over OpenID Connect. Staff create a client ID and secret with the
  allowed redirect URIs under OpenID Connect in the admin area. Applications find the endpoints in a discovery
  document at `/oauth/openid-configuration.php`, or `/.well-known/openid-configuration` with a rewrite rule
  ([OpenID Connect](https://docs.whmcs.com/9-1/system/authentication/openid-connect/),
  [OpenID Connect Development](https://docs.whmcs.com/9-1/system/authentication/openid-connect-development/)).
- The flow is the authorisation-code flow with `scope=openid profile email` over HTTPS. The ID token carries `iss`,
  `sub`, `aud`, `iat` and `exp`. `sub` is a unique identifier that is never reused; the example is a UUID. Email and
  profile come from a claims request with the access token. **Not documented:** `email_verified`, PKCE, and the
  signing algorithm (the discovery document would say).
- The user signs in to WHMCS and **authorises the app** the first time, so there is a consent step. WHMCS's own
  example is cPanel's "Log in via WHMCS" button.
- OpenID Connect was already in 8.0: "Correct redirect for OpenID authorization" (CORE-15587, 8.0 change log). The
  version that introduced it was not checked.
- The OAuth credential actions (`CreateOAuthCredential` and the others) let the API create credentials too
  ([CreateOAuthCredential](https://developers.whmcs.com/api-reference/createoauthcredential/)). We would not use
  them: creating a credential is a write, and the host should create it themselves.

### 1.4 Other findings

- **Sign-In Integrations**: WHMCS clients can sign up and sign in with Facebook, Google or X
  ([Sign-In Integrations](https://docs.whmcs.com/9-1/system/authentication/sign-in-integrations/)). The links cannot be
  read through the API.
- **`CreateSsoToken`** issues a single-use, 60-second token that fully signs a client or user in to WHMCS. The docs
  warn that it skips captcha and 2FA ([CreateSsoToken](https://developers.whmcs.com/api-reference/createssotoken/)).
  It works in the opposite direction, from us into WHMCS.
- **WHMCS's own resets**: a self-service reset link is valid for two hours, and the reset may ask a security question
  ([Reset a User Password](https://docs.whmcs.com/9-1/clients/client-management-tutorials/reset-a-user-password/)).
- **2FA at WHMCS**: time-based tokens, Duo and YubiKey. Admins can turn 2FA off for a client but cannot turn it on
  ([Two-Factor Authentication](https://docs.whmcs.com/9-1/system/authentication/two-factor-authentication/)).

## 2. The options

The tests in every option use the WHMCS API simulator (ADR 0016, `billing/tools/simulators/src/Providers/Whmcs`),
because we have no WHMCS accounts. Today it serves `WhmcsDetails`, `GetClients` and `GetClientsDetails`.

### Option 1 — Forced reset (today's default)

**How it works.** Imported logins get a password nobody knows (§6.9 as built: the hash of a discarded random secret).
Clients get access in one of two ways.

| | 1a. Invitation (built, C3) | 1b. Bulk password reset |
| --- | --- | --- |
| Mail | `client.migration_invitation`: "We have moved; set your password", the host's brand, editable | Fortify's reset mail: "You asked to reset your password", which nobody asked for |
| Link lifetime | `InvitationExpiry::days()`, days | `auth.passwords.users.expire` = 60 minutes; most bulk recipients would find it expired |
| Verifies the email | Yes (bound to the address it was sent to) | Yes on use |
| Throttle | `migration.invite_per_hour` (100, owner decision 2026-10-22), about three weeks for 50,000 | The per-address limit (5 an hour) and the same mail-provider caps; no queue or progress |
| Skips used logins | Yes (the contract invites only never-used logins) | No |

- **Client experience.** One mail, one click and a new password. Clients who miss or distrust the mail contact
  support. The 2FA note in the mail waits for a source of the flag (§6.10). Option 2 now supplies that flag for the
  logins that come back.
- **Host effort.** Choose waves, watch the counts and answer "is this mail real?".
- **Security.** A bulk "set your password" mail is a phishing template: people learn to click password links. The link
  is single-use, bound to the address, and stored hashed. There is no new attack surface.
- **Licence dependency.** None.
- **Size.** Built (client-import PR 4, #255 and later).
- **Tests.** Existing criteria 35 and 36.

**Verdict.** 1a beats 1b on every row. 1b should never be used for migration. Keep 1a as the fallback after the
cut-off, and for any host that does not want option 2.

### Option 2 — Sign-in carry-over through `ValidateLogin` (lazy migration)

**How it works.**

1. The host turns on "Sign-in carry-over" on their WHMCS source. They create a **second** API credential whose role
   grants only `ValidateLogin`, and set a cut-off date (default 90 days, at most 180). "Test" sends one check for a
   reserved address (`carry-over-probe@<random>.invalid`) and expects "Email or Password Invalid". Any other answer,
   such as an unknown action, a permission error, a redirect or TLS trouble, is shown with its reason.
2. The API import (client-import PR 8) creates a `migration_login_checks` row for each WHMCS user it turns into a
   Mohar login that has no password of its own. The row holds the source, the WHMCS user id and a keyed hash of the
   email as imported.
3. A client signs in on Mohar's normal form. Mohar checks its own hash first. That check fails for an imported login,
   because nobody knows its password. Mohar then calls the one kernel hook (§4), provided all of these hold:
    - the login has an open row;
    - its email still equals the imported one;
    - the cut-off has not passed;
    - the source is healthy;
    - the remote budgets allow it.
4. The Migration module sends `ValidateLogin` over the guarded connection (ADR 0015), with the email as imported and
   the typed password.
    - **Success, `twoFactorEnabled` false, and `userid` equal to the WHMCS user id recorded at import.** In one locked
      transaction, Mohar stores its own bcrypt hash of the typed password and closes the row as `migrated`. The
      sign-in continues as a normal password sign-in.
    - **Success with `twoFactorEnabled` true.** No sign-in yet (§3.4). Mohar mails a confirmation link to the login's
      address. Opening it in the same browser stores the hash and signs the client in. The login is flagged "had 2FA",
      so the banner from §6.10 shows.
    - **"Email or Password Invalid".** An ordinary failed sign-in. It counts towards the login limiter and the remote
      budget.
    - **A `userid` mismatch, a malformed answer, a timeout or a 5xx.** The answer is treated as a failure. The source's
      health counter goes up, and repeated faults switch carry-over off with an alert to staff.
5. After the cut-off, or when staff switch it off: the open rows expire, the hook answers "not applicable", and those
   clients use "Forgot password" or a staff invitation (option 1a). Staff can send invitations to everyone not yet
   migrated with one filter.

**Client experience.** For most clients nothing changes: same email, same password, and no mail. 2FA clients get one
"confirm it's you" mail, then set up 2FA again. Clients who come back after the cut-off use "Forgot password" or their
invitation. The login page shows a notice during the window: "Moved from our old client area? Your old password works
until <date>." A per-host setting can turn it off.

**Host effort.**

- Keep the old WHMCS running and reachable over HTTPS until the cut-off, on its **own hostname**
  (`old.example.com`). The usual switch moves the old billing hostname to Mohar, so the old WHMCS needs a new one.
  Mohar refuses a base URL that resolves to its own `APP_URL` host.
- Create the one-action credential.
- Allow Mohar's egress IP. It is already on the API allow-list for the importer. The host should also whitelist it
  against the login ban if WHMCS applies one (§7).
- Leave the WHMCS client area closed, or redirect it to Mohar. It must stay open for API calls only. This is a
  runbook item in `migration.md` §10.

**Security risks and mitigations.**

| Risk | Mitigation |
| --- | --- |
| The plaintext password travels to a second system | It is typed into Mohar's form anyway; it is sent onward only once per login, only to the host's own WHMCS, only over HTTPS with TLS verification. No `--allow-insecure`; plain `http://` is refused even on a private address. The connection is pinned through `OutboundAddressGuard` (ADR 0015; staff-entered infrastructure, so the install policy), no proxy, no redirects |
| The password or WHMCS session token reaches a log | `password2` and `passwordhash` are added to the redaction keys (ADR 0023). The connector never logs request bodies. The token is dropped as soon as it is parsed and never stored. A test greps logs, failed-job payloads and activity rows for both |
| The old WHMCS is compromised or its hostname taken over during the window and answers "success" for everyone | The answer must carry the `userid` recorded at import, which an attacker on the old host would have to know for each email. The window is short and bounded (at most 180 days). Faults and mismatches switch the source off. Each carry-over is an activity entry; the admin panel shows the daily count, and a spike is visible. The one-action credential means a stolen secret checks passwords and nothing else. Optional SPKI pin (§7, Q13d) |
| The remote check becomes a guessing oracle | Remote checks run only after the local `login` limiter (5 a minute per email and IP), within a budget per login (5 an hour) and per source (5 a second, at most 4 at once). Counting is atomic, using the add-then-increment pattern of `PasswordGuesses` (#536, not `RateLimiter::increment`). Over budget, the answer is the ordinary failure without a call |
| Mohar's egress IP gets banned by WHMCS and carry-over stops for everyone | The budgets above keep failures far below any ban threshold. The host whitelists the IP. A ban shows up as a health fault, not as wrong passwords |
| Slow WHMCS holds PHP threads at sign-in, which is already the slowest page (`sizing.md`: p95 1.4 s at 40 users) | 5-second total timeout. At most 4 checks at once per source; over that, the answer is "try again in a moment". The call happens only for imported, not-yet-migrated logins, a population that shrinks every day |
| Two sign-ins at once both write a hash | The row is locked and compared-and-set; the second sign-in re-checks the new local hash |
| A weak WHMCS password is carried over (WHMCS before 9.1 had no 12-character minimum) | After a carry-over, the typed password is checked against Mohar's rules (`PasswordValidationRules`). If it fails, the client must choose a new one before using the client area (SHOULD) |
| The email changed on one side after the import | Only the email as imported is sent, and only while the Mohar login still has it. Otherwise the row closes and the client uses "Forgot password". A WHMCS-side change is picked up by a delta run (§8 of client-import) |

**Account enumeration.** Mohar's answer is the same text for an unknown email, a wrong password and a remote
"invalid". Timing still differs: only an imported, unmigrated login waits for WHMCS. What it would leak is "this email
belongs to an imported client". Two mitigations:

- while carry-over is on, every failed password sign-in waits until a fixed minimum time has passed (default 1 s,
  with jitter) before answering;
- the per-email login limiter bounds how many emails an attacker can probe.

Unknown emails are never sent to WHMCS. The "we emailed you a link" message appears only for a correct password on a
2FA account (below), never for a wrong one. That avoids both enumeration and mail bombing. The residual leak is
accepted, and the security reviewer should probe it.

**2FA users.** WHMCS withholds its session token when 2FA is required, but it still says that the password was right.
Mohar cannot check the second factor: the secret is encrypted with the source's key, which we never ask for. Signing in
on the password alone would quietly lower the account's protection. So the second proof is the mailbox:

- Mohar mails a single-use link to the login's own address (template `client.migration_confirm_signin`, always
  sent). It is valid for 30 minutes and bound to the browser that started the sign-in by a nonce cookie stored hashed
  with the token. This is the social sign-in SS-17 pattern.
- The bcrypt hash of the typed password is held in the row until the link is opened, then stored on the login. If the
  link is not used in time, the held hash is deleted.
- At most 3 links an hour per login.
- The login gets the "had 2FA" flag, which until now only the gap reader could give (§6.10), and sees "Set up two-factor
  sign-in" after signing in.
- This is a sign-in proof, not step-up, so it does not conflict with ADR 0018 §4. The ADR is still amended to say so.

**Sub-users.** Every WHMCS user becomes a Mohar login, whether owner or not (§6.11), and every login gets its own row.
The check is per user, so a user linked to three client accounts migrates once and keeps all three links and their
permissions. Contacts without a login (8.x contacts, and every pre-8.0 sub-account, Q10) have nothing to migrate.

**What "verified" means for the email.** A carry-over proves that the person knows the WHMCS password for that
address. It does not prove they control the mailbox, so it **does not** set `email_verified_at`. Verification stays as
imported from WHMCS's `email_verified` (§6.7), or comes from the mail flows. The 2FA path is the exception, because
opening the link proves the mailbox, so it verifies the email. ADR 0018's rule that "verified 30 days ago" bounds the
last email change still holds: a carry-over does not change the email.

**Cut-off and admin progress.**

- Per source: `login_carry_over_until`, a date on the company clock (ADR 0013), ending at 23:59:59 company time.
  Default: 90 days after carry-over is turned on; at most 180. It can be shortened at any time and extended only up
  to the maximum.
- Migration → Sources → <WHMCS source> → **Sign-in carry-over**:
    - a status line (on, off, or paused by faults, with the reason) and the days left;
    - progress: eligible, carried over, confirmed by email (2FA), waiting for confirmation, expired, and the percentage;
    - checks in the last 24 hours (accepted, refused, faults), and the last health probe;
    - buttons: Test, Pause or Resume, Change the cut-off, and "Invite everyone not carried over", which opens the
      invitation page with that filter.
- Switching on, the cut-off and credential changes need `migration.sources.manage` and step-up. Viewing needs
  `migration.runs.view`.

**Licence dependency.** None beyond what the importer already relies on. It uses the host's own WHMCS through its
documented API, with the host's credential, and it moves no data export. The host should know it calls one action
that writes (the last-login time). That goes in the wizard text.

**Implementation size and placement.**

- **Kernel PR** (CTO): the contract and its null default (§4), the Fortify hook in the sign-in pipeline, the failed
  sign-in time floor, the redaction keys and the ADR 0018 amendment, with tests. About 300 lines.
- **client-import PR 8b** (after PR 8, which creates the user logins with WHMCS ids): the table, the connector call
  (a second allowlist that names only `ValidateLogin`), the state machine, budgets, health, the cut-off, the plain
  path and `ValidateLogin` in the simulator. About 900 lines.
- **PR 8c**: the 2FA confirmation link and template, the admin panel, the login-page notice and the invitation
  filter. About 700 lines.
- PR 9 (gap reader) stays owner-gated and becomes less needed. `invoice-domain-import.md` is not affected: phase 3
  imports no logins.

**Tests** (simulator scenarios; the full list is in `client-import.md` §20, criteria 54–70):

- Plain carry-over: one call, then none.
- Wrong password: a failure, counted.
- 2FA account: the link, its browser binding and its expiry.
- Hostile source: a wrong `userid`, a non-JSON answer, a redirect, or a base URL on Mohar's own host.
- Faults: a timeout, a 5xx or an unknown action, each counted, and repeated ones pause the source.
- Rate and concurrency caps; the time floor.
- The cut-off moment on the company clock.
- Redaction: a log grep.
- Sub-users: one carry-over for a user with three links.
- A changed email; two parallel sign-ins.
- `MODULES=Core` (Migration absent): password sign-in unchanged.

The simulator gains `ValidateLogin` (scenario users with email, password, user id and a 2FA flag) and control actions
for an outage, slowness, a removed action, a wrong user id and a login ban.

### Option 3 — Import hashes through the §3.3 gap reader

**How it works.** As in `client-import.md` §3.3 and §6.9: the host restores their own database dump into a throwaway
database and gives Mohar read-only access to that copy. The reader takes `tblusers` password hashes and the 2FA flag,
and nothing else. Laravel's bcrypt hasher verifies `$2y$` hashes, and `rehash_on_login` raises the cost at Mohar's
expense on the next sign-in.

**Mixed and legacy hashes.** The importer sorts each hash by strict pattern:

- `$2y$` (and `$2b$`, if `password_get_info()` and Laravel's `bcrypt.verify` accept it; **verify** in the test) with
  cost 10 to 14: carried;
- the MD5-with-salt form (`<32 hex>:<salt>`): refused, and the login is invited. Such a hash means the person has not
  signed in since 6.3 (2016), because WHMCS rehashed at first login;
- empty (the forced-reset state from the WHMCS docs) or anything else: refused, and the login is invited;
- any other algorithm (in case 9.x moved users to a new algorithm, as it did for admins): refused and counted, so the
  report shows the gap.

**Client experience.** Best of all: nothing changes, even after the old WHMCS is switched off.

**Host effort.** Highest: dump, restore and give access, once per import run.

**Security.** A hash is offline-crackable material in transit and at rest in the staging database. Hashes are never
accepted from a file (§6.9). 2FA logins never get a carried hash. `migration.imported_password_max_days` limits stale
ones.

**Licence dependency.** Full: it needs a database export from the host (owner questions §8, items 1 and 2).

**Size.** client-import PR 9, about 1,000 lines with fixtures. Fixtures need a real export (Q11).

**Tests.** Criteria 51–53, plus one for an unknown algorithm.

**Verdict.** Technically sound, legally uncertain. It stays behind Q1 and Q2. If the owner later approves it, run it
**together with** option 2: hashes for those it covers, carry-over for the rest.

### Option 4 — WHMCS as an OpenID Connect provider during the transition

**How it works.**

1. The host creates OIDC credentials in WHMCS with Mohar's redirect URI.
2. Mohar's sign-in page gets "Sign in with <old client area name>", a sign-in method registered in ADR 0018's
   `SignInMethods`.
3. The client signs in at WHMCS, with WHMCS's own 2FA, and approves Mohar once.
4. Mohar validates the ID token (issuer, audience, expiry, signature from the discovery document's key set) and reads
   the email from the claims endpoint. It links `sub` to the imported login whose email matches and is unchanged.
5. The client must then set a Mohar password or passkey in that session, because the link dies with the old system.

**Client experience.** Two redirects and a consent screen worded by WHMCS, then a "set a password" prompt. No password
crosses to WHMCS from us, but the client still ends up creating a new one, so it does not truly avoid the reset. It
only moves it into the first sign-in.

**Host effort.** Create OIDC credentials, publish the discovery document (a rewrite rule on some installs) and keep
WHMCS reachable to browsers, not just to Mohar.

**Security.** Strongest at transit: Mohar never sees the WHMCS password. The risks are the usual OIDC ones: state and
nonce, `iss` and `aud` checks, an unverified email (the docs do not document `email_verified`, so matching by email
needs the imported-email rule above), and an account linked by email (social sign-in SS-17 says never silently).

**Licence dependency.** None.

**Size.** Large. It needs ADR 0018's PR 0 (`SignInMethods`, `CompleteSignIn`, `SignInGate`), which is not built.
Today `app/Actions/Fortify` has none of them. It also needs an OIDC client (Socialite's generic driver, or our own
JWT validation; a dependency decision under ADR 0007) and a WHMCS OIDC simulator with a key set. About 1,500 lines
after PR 0.

**Tests.** A simulator issuing signed ID tokens, with these cases: wrong `aud`, expired token, replayed `state`,
unverified or changed email, consent denied.

**Verdict.** Keep it as the documented plan B for when WHMCS deprecates `ValidateLogin`. The connector checks for that
case: a test call that answers "unknown action" pauses carry-over and tells staff. Build it only if that happens, or if
hosts ask for it after the social sign-in module (which brings the same kernel) ships.

### Option 5 — What else the docs reveal

- **5a. Social sign-in carry-over (cheap, later).** Clients who used Google in WHMCS (Sign-In Integrations) can sign
  in with Mohar's Google provider (`social-sign-in.md`) once it ships. Its verified-email rules (SS-17: confirm, never
  link silently) already cover an imported login. Nothing to build here. The import report can say how many logins
  are affected, if the API ever exposes the link; today it does not.
- **5b. On-demand instead of bulk mail (built, used better).** During the window, the login-page notice and the
  normal "Forgot password" give each client a link when they ask. Bulk invitations then go only to the remainder after
  the cut-off: fewer mails, fewer phishing look-alikes and less of the 100-an-hour throttle.
- **5c. `CreateSsoToken`, rejected for migration.** It lets Mohar sign a client in to the old WHMCS, the opposite
  direction. It could give a "view your old invoices" link during the transition, but the token skips WHMCS's 2FA and
  captcha, and the credential would need a write action. Not worth the risk; imported history already lives in Mohar.
- **5d. A WHMCS add-on or hook that we install on the host's WHMCS, rejected.** It could read hashes or catch
  passwords at login, but it is a database read by another route (the same licence question as option 3). It would
  also be our code running inside a product we must not inspect, and it would need our own release and support
  channel.
- **5e. Passkeys after sign-in (small follow-up).** After a carry-over, offer "Add a passkey". It lowers the cost of
  any later password reset.

## 3. Comparison

| | 1a Invitation | 2 Carry-over | 3 Hash import | 4 OIDC |
| --- | --- | --- | --- | --- |
| Avoids a reset for returning clients | No | **Yes, within the window** | Yes, always | No (deferred) |
| Mails sent | One per login | Only 2FA confirmations and the remainder | None | None |
| Needs a DB export | No | **No** | Yes | No |
| Old WHMCS reachable | No | Until cut-off (API only) | No | Until cut-off (browsers) |
| Host effort | Low | Low | High | Medium |
| Plaintext password leaves Mohar | No | Once per login, to the host's own WHMCS | No | No |
| 2FA accounts | Invitation | Mailbox confirmation, 2FA flag known | No hash, invitation | WHMCS's 2FA applies |
| Kernel change | None | One contract (§4) | One column | ADR 0018 PR 0 |
| Size | Built | ~1,900 lines in 3 PRs | ~1,000 | ~1,500 after PR 0 |
| Depends on WHMCS keeping the feature | No | Yes (deprecation notice) | No | Yes |

## 4. Kernel shape (for the CTO, ADR 0018 amendment)

The Fortify sign-in pipeline (`app/Actions/Fortify`, `FortifyServiceProvider`) cannot import the Migration module.
The smallest hook is one contract with a null default and a single binder. It is not a registry, because there is only
one implementer and nothing else would ever register:

```php
namespace App\Contracts\Auth;

interface PendingPasswordCheck
{
    // Called only after the local password check failed, inside the login limiter. Never inside a transaction.
    public function check(User $user, string $password, Request $request): PendingPasswordOutcome;

    // True while any source may answer, so the failed sign-in time floor applies.
    public function active(): bool;
}

enum PendingPasswordOutcome: string
{
    case NotApplicable = 'not_applicable'; // no open carry-over for this login
    case Verified = 'verified';            // the implementer stored the new local hash; continue the sign-in
    case ConfirmByEmail = 'confirm';       // correct, but the source had 2FA; a link was mailed, no sign-in
    case Rejected = 'rejected';            // wrong password at the source; an ordinary failure
    case Unavailable = 'unavailable';      // the source could not answer; an ordinary failure, counted as a fault
}
```

- Core binds `NullPendingPasswordCheck` with `bindIf` (always `NotApplicable`, `active()` false). Migration binds its
  implementation. With `MODULES=` excluding Migration, sign-in is exactly as today.
- The caller is the password-validation step of the sign-in pipeline (`Fortify::authenticateUsing`, or
  `RememberValidatedLogin::validateCredentials`; the builder picks one and a pipeline test pins it). A `Verified`
  outcome continues into the same 2FA and session steps as a local match. `ConfirmByEmail` answers with a status
  message and no session.
- Step-up (`confirmPasswordsUsing`) never calls it. A login in a session already has a local password.
- **Must not be added:** a second sign-in route or controller for imported logins; a "migration" sign-in method in
  `SignInMethods` (this is the password method); an event per carry-over (the activity log and the table's counts are
  enough, and an event would have no consumer); a generic "external password provider" registry; any column on
  `users` (the state lives in Migration's table).

## 5. Recommendation

1. **Default: option 2.** Sign-in carry-over through `ValidateLogin` during a cut-off window: 90 days by default,
   180 at most.
2. **Fallback: option 1a.** Invitations after the cut-off, only to logins not carried over. "Forgot password" works
   throughout.
3. **2FA accounts:** a confirmation link by email, then 2FA set up again. This also gives the importer the 2FA flag.
4. **Option 3 only if the owner later confirms the licence question.** It then runs alongside 2, not instead of it.
5. **Option 4 is the plan B** if WHMCS removes `ValidateLogin`. Option 5a comes free when social sign-in ships.
   Never use 1b, 5c or 5d.

## 6. What changes in the specs

- `client-import.md`: new §3.4 (this design), A9 in §2, §6.9 and §6.10 updated, a permission note in §11, C11 and C12
  in §16, PRs 8b and 8c in §19, criteria 54–70 in §20, Q13 in §21, and the sources in §23.
- `migration.md` §2.1: one note: a second allowlist, under its own credential, that names only `ValidateLogin`; the
  read-only allowlist is unchanged.
- ADR 0018: Amendment (#555), Proposed: the hook, the mailbox confirmation, verification semantics, and what must not
  be added.

## 7. Verify before relying on it (simulator assumptions until a real 8.x/9.x response is seen)

1. A 2FA account with a **wrong** password answers "Email or Password Invalid", not success with `twoFactorEnabled`.
2. Failed `ValidateLogin` calls do or do not count towards any WHMCS IP ban, and whether the security-settings
   whitelist exempts the caller.
3. `userid` is the 8.x user id (the id `GetUsers` returns), not a client id.
4. Number and boolean encoding (the example has `"userid": "1"` and `"twoFactorEnabled": "false"` as strings). The
   normaliser already handles this.
5. With session IP validation on, the remote API still answers the password check (only the session token is
   affected).
6. Behaviour on WHMCS Cloud (the API is the same; the OIDC developer page is self-hosted only).
7. For option 3: the 8.x/9.x client hash prefix in a real export, and whether 9.x changed the user algorithm as it did
   for admins.

Sample responses from a test install with fake data (Q11) settle items 1, 3 and 4. Items 2 and 5 need a host to try
them.

## 8. Owner questions (not legal advice)

1. **Approve option 2 as the default**, with a 90-day default window and a 180-day maximum?
2. **Sending the password onward.** Is it acceptable that Mohar forwards a returning client's typed password once,
   over TLS, to the host's own WHMCS? Hosts might want this mentioned in their privacy notice. Should the wizard say
   so?
3. **Licence.** Option 2 uses only the host's own API access, which the 2026-10-17 decision already relies on. Does
   the owner see any difference in calling an action that authenticates a user and updates the last-login time,
   rather than one that only reads?
4. **Option 3 stays parked** until the existing licence question (schema note §6, item 2) has an answer: may a host
   hand an export of their own WHMCS data to a third-party tool?
5. **2FA path.** Is a mailbox confirmation an acceptable second proof for accounts that had 2FA at WHMCS, or should
   those accounts always use the invitation?
6. **Hostile old host.** Offer an optional TLS key pin for the old WHMCS (it breaks if the host changes its
   certificate key during the window), or rely on the user-id check and the short window?

## 9. Sources (all read 2026-10-31)

- WHMCS API reference: [ValidateLogin](https://developers.whmcs.com/api-reference/validatelogin/) ·
  [GetClientPassword](https://developers.whmcs.com/api-reference/getclientpassword/) ·
  [GetUsers](https://developers.whmcs.com/api-reference/getusers/) ·
  [CreateSsoToken](https://developers.whmcs.com/api-reference/createssotoken/) ·
  [CreateOAuthCredential](https://developers.whmcs.com/api-reference/createoauthcredential/) ·
  [ListOAuthCredentials](https://developers.whmcs.com/api-reference/listoauthcredentials/) ·
  [Authentication](https://developers.whmcs.com/api/authentication/) ·
  [Access control](https://developers.whmcs.com/api/access-control/) ·
  [OAuth introduction](https://developers.whmcs.com/oauth/introduction/) ·
  [OAuth workflow](https://developers.whmcs.com/oauth/auth-workflow/) ·
  [REST rate limits](https://developers.whmcs.com/rest-api/rate-limits/) · page index
  [llms.txt](https://developers.whmcs.com/llms.txt)
- WHMCS docs 9.1: [OpenID Connect](https://docs.whmcs.com/9-1/system/authentication/openid-connect/) ·
  [OpenID Connect Development](https://docs.whmcs.com/9-1/system/authentication/openid-connect-development/) ·
  [Sign-In Integrations](https://docs.whmcs.com/9-1/system/authentication/sign-in-integrations/) ·
  [Two-Factor Authentication](https://docs.whmcs.com/9-1/system/authentication/two-factor-authentication/) ·
  [Reset a User Password](https://docs.whmcs.com/9-1/clients/client-management-tutorials/reset-a-user-password/) ·
  [Password Rejected Errors](https://docs.whmcs.com/9-1/troubleshooting/troubleshoot-authentication/password-rejected-errors/) ·
  [Security settings](https://docs.whmcs.com/9-1/system/general-settings/general-settings-security/) ·
  [9.1 release notes](https://docs.whmcs.com/9-1/releases/9-1/9-1-release-notes/) ·
  change logs [9.1](https://docs.whmcs.com/releases/9-1/9-1-change-log/),
  [9.0](https://docs.whmcs.com/releases/9-0/9-0-change-log/),
  [8.13](https://docs.whmcs.com/releases/8-13/8-13-change-log/),
  [8.1](https://docs.whmcs.com/releases/8-1/8-1-change-log/),
  [8.0](https://docs.whmcs.com/releases/8-0/8-0-change-log/)
- Secondary, for the hash history: [NDCHost wiki](https://www.ndchost.com/wiki/software/whmcs/client-password-hash) ·
  [WHMCS community topic 255397](https://whmcs.community/topic/255397-passwords-25-characters-after-upgrade-to-630/) ·
  [topic 265165](https://whmcs.community/topic/265165-change-password-hashing/)
- Our documents and code: `billing/docs/product/client-import.md` (A6, §3.3, §6.7, §6.9 to §6.11, Q1, Q2),
  `billing/docs/product/migration.md` §2, `billing/docs/product/social-sign-in.md` (SS-17), ADRs 0013, 0015, 0016,
  0018, 0019, 0022 and 0023, `docs/research/2026-10-31-whmcs-schema-sources.md` (§6, Q1b),
  `docs/research/2026-10-31-whmcs-docs-coverage.md` §6, `docs/handoff/requirements.md` (2026-10-17),
  `billing/docs/devops/sizing.md`, `billing/app/Actions/Fortify/{PasswordGuesses,RememberValidatedLogin,SignInValidatedUser}.php`,
  `billing/app/Contracts/Auth/ClientLoginInvitations.php`, `billing/tools/simulators/src/Providers/Whmcs/`.
