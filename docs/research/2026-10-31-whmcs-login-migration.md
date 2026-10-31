# Bringing WHMCS client logins across without a forced reset, 2026-10-31

Research and decision note. Principal architect. Public WHMCS pages only, all read on 2026-10-31 (§9). All wording is
ours. Nothing was run against a WHMCS install; we have none. Not legal advice: the licence points are owner questions
(§8).

The owner asked us to "analyze and get the best options, also look if we can avoid resetting passwords too". This note
compares five ways to carry client sign-in from WHMCS to Mohar, recommends one, and proposes the spec and ADR changes.
They are in the same PR: `client-import.md` §3.4, and the ADR 0018, 0012 and 0015 Amendments (#555).

## 0. Answer in short

- **Recommended: sign-in carry-over through the WHMCS API (option 2, "lazy migration").** WHMCS documents an API
  action, `ValidateLogin`, that checks an email address and password against a WHMCS user. It also says whether that
  user has two-factor sign-in turned on. A client signs in to Mohar with their old email and password. Mohar has no
  usable password for them yet, so it asks the old WHMCS once. If WHMCS accepts the password, Mohar stores its own bcrypt
  hash and never asks again. No password database moves, no export is needed, and it runs on the API the host already
  opened for the importer, in line with the 2026-10-17 API-first decision. Whether the licence terms reach this live
  call is still an open question for the owner or a lawyer (§2, option 2). The #555 design review's requirements are in
  `client-import.md` §3.4.
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

The binding requirements are in `client-import.md` §3.4, with criteria 54–82. They take in every finding of the #555
design review. This section explains the design and the reasons behind it.

**How it works.**

1. **Setup.** The host turns on "Sign-in carry-over" on their WHMCS source. They add a **second** API credential
   whose role grants only `ValidateLogin`, on the source's one base URL, and set a cut-off date (default 90 days, at
   most 180). "Test" does three things:
    - vets the host;
    - records the TLS key (trust on first use);
    - sends one check for a reserved `.invalid` address and expects "Email or Password Invalid".

   It can also prove the endpoint is the same install, by comparing a `GetUsers` sample with the id map.
2. **Rows.** The API import (client-import PR 8) creates a `migration_login_checks` row for each WHMCS user it turns
   into a Mohar login with no password of its own. The row records the source, the WHMCS user id, a keyed hash of the
   email, and **the placeholder hash the login got at import**.
3. **Sign-in.** A client signs in on Mohar's normal form, and Mohar checks its own hash first. The kernel asks
   Migration only when all of these hold:
    - the login is not staff;
    - `SignInGate` has no refusal;
    - Migration says the login is `pending()`: its password is still the placeholder, its email is unchanged, the
      cut-off has not passed, and the source and the install switch are on;
    - the guess budget allows it.
4. **The call.** Migration sends `ValidateLogin` with the email as imported and the typed password, to a pre-resolved
   and pinned address, within a 5-second deadline. Then:
    - **Success, `twoFactorEnabled` false, and `userid` exactly the recorded id:** Mohar writes its own hash by
      compare-and-set against the placeholder. The sign-in continues normally.
    - **Success with the flag true, or with no recognisable flag:** no sign-in. The client is mailed a link: a GET
      shows a confirm page, and a POST from the same browser completes the sign-in through `CompleteSignIn`.
    - **"Email or Password Invalid":** an ordinary failure that counts towards the lockout.
    - **Anything else** (a mismatch, a malformed answer, a timeout, a 5xx, a changed TLS key): the ordinary failure,
      not counted towards the lockout, and counted as a fault. Faults, spikes in Rejected answers and changes to the
      destination pause the source.
5. **After the cut-off**, or when staff pause carry-over: nothing is sent, and clients use "Forgot password" or an
   invitation (option 1a).

**Client experience.** For most clients nothing changes: same email, same password, and no mail. 2FA clients get one
"confirm it's you" mail, then set up 2FA again. Clients who come back after the cut-off use "Forgot password" or their
invitation. During the window, the login page says the old password works until the cut-off, and every failure
message carries a Forgot password hint.

**Host effort.**

- Keep the old WHMCS licensed, running and reachable over HTTPS until the cut-off, on its **own hostname**
  (`old.example.com`). The usual switch moves the old billing hostname to Mohar. Mohar refuses any base URL whose
  normalised hostname is one it serves.
- Create the one-action credential.
- Allow Mohar's egress IP, which is already on the API allow-list for the importer. Also whitelist it against the
  login ban, if WHMCS applies one (§7).
- Keep certificate keys stable during the window, or expect a pause on renewal (Q13(d)).
- Close the WHMCS client area, or redirect it to Mohar; only the API stays open. This is a runbook item in
  `migration.md` §10.

**Security risks and mitigations** (the review finding in brackets).

| Risk | Mitigation |
| --- | --- |
| An old WHMCS password overwrites a newer Mohar password (B1) | Eligibility is the password itself: the login must still hold its import placeholder. The write is a compare-and-set against it. No list of password writers to keep in step |
| The hook becomes a harvest point for every wrong password (B2) | Never for staff; only after `SignInGate`; the secret crosses only after `pending()` says yes. One first-party implementer, enforced by an Arch test and a boot check (ADR 0012 Amendment (#555)). `#[\SensitiveParameter]` everywhere |
| Whoever changes the URL or credential decides where passwords go (B3) | One base URL per source; any change pauses carry-over; the fields are read-only while it is on; Resume needs a fresh passing Test and step-up; an audit row and `SecurityNotice` to the owner, every `*` holder and the actor |
| Sign-in stalls the small host (B4) | No DNS in the request (a cached, vetted address set from the health probe); a 2 s connect and 5 s total deadline; concurrency `max(1, threads/4)`, which is 1 on 4 threads (the default when unset); a bounded failure floor taken from the measured p95, with the slot taken before any call; an address probe every minute; a load-test scenario (ADR 0015 Amendment (#555)) |
| A second sign-in path (B5) | The 2FA link signs in only through `CompleteSignIn`. PR 8c waits for ADR 0018 PR 0 |
| The old host is compromised or its hostname taken over (M1) | The recorded TLS key is enforced per call and aborts before the request is sent; an issuer change pauses; the `userid` must match exactly; faults and spikes pause; the window is at most 180 days; the credential checks passwords and nothing else |
| A malformed or changed answer skips the mailbox step (M2) | Fail-closed parsing. A missing or unknown 2FA flag means ConfirmByEmail; inconsistent fields are faults |
| An outage locks clients out, or the cap leaks "imported" (M3) | Unavailable, over-budget, over-cap and no-floor-slot answers never fire `Failed`. The budget is counted before the call, under its own key prefix, and refunded unless the password was wrong. Every refusal uses the ordinary text |
| An incident needs a fast stop (M4) | Pause without step-up, effective on the next request; an install-wide environment switch; automatic pause on faults, TLS changes and Rejected spikes; metrics |
| Mail scanners, or a link opened in the wrong browser (M5) | GET shows, POST consumes; a `__Host-` Lax cookie; a mismatch does not burn the token; everything is checked again on POST |
| The password reaches a log or trace (M6) | `RedactionScope` with a `RedactionSet`; no HTTP-client events; Guzzle exceptions never reported; the email hash keyed with `InstallSecrets`; tested with `zend.exception_ignore_args` both on and off |
| A weak WHMCS password is carried over (WHMCS before 9.1 had no 12-character minimum) | Checked against Mohar's rules after a carry-over; the client must change it first (SHOULD) |

**Accepted residuals** (for the security reviewer to probe):

- A source slower than the floor reveals that the probed email is imported.
- Twenty wrong guesses an hour can keep a victim's carry-over from being checked. The victim still has "Forgot
  password".
- Unavailable is not counted towards the lockout, so over many attempts the lockout behaves differently for
  imported logins during an outage.

**2FA users.** WHMCS withholds its session token when 2FA is required, but it still says whether the password was
right. We cannot check the second factor: the secret is encrypted with the source's key, which we never ask for. So
the second proof is the mailbox, which is stronger than "Forgot password", where the mailbox alone is enough.
Opening the link also gives the importer the "had 2FA" flag, which until now only the gap reader could provide.

**Sub-users.** Every WHMCS user becomes a Mohar login, owner or not, and each has its own row. A user linked to three
client accounts migrates once and keeps all three links.

**What "verified" means for the email.** A carry-over does not set `email_verified_at`, because knowing a password
does not prove the mailbox. The 2FA path does set it.

**Licence dependency: an open question, not a conclusion (M8).**

- `docs/research/2026-10-31-whmcs-licence-terms.md` §5 puts a live `ValidateLogin` call under the same open points
  as the importer:
    - EULA §2.5, on automated extraction;
    - handing credentials to a third party (that note's lawyer question 2);
    - its own lawyer question 7, on first sign-in during or after a licence lapse.
- It needs no database export, which is what makes it better placed than option 3. But it is not settled.
- Product risk: the software stops when the licence ends (EULA §2.4.2, §5.3). A lapse mid-window shows up as
  Unavailable, pauses carry-over and leaves clients to invitations. The wizard says so.

**Implementation size and placement.**

- **PR 8a, kernel (CTO), about 450 lines.** It goes after ADR 0018 PR 0, or brings `SignInGate` and `CompleteSignIn`
  forward. It contains:
    - the contract and its null default;
    - the `authenticateUsing` hook for both pipeline shapes;
    - the Arch test and the boot check;
    - the failure floor and the shared guess counter;
    - `kernel-api.txt` and a kernel-changelog fragment.
- **PR 8b, about 1,300 lines.** It contains:
    - the table, eligibility, the call, parsing and the compare-and-set;
    - budgets, the cap, the address cache and the TLS record;
    - the change guards, kill switches and auto-pause;
    - the simulator's `ValidateLogin` and its new faults;
    - the load test.
- **PR 8c, about 800 lines, after ADR 0018 PR 0.** It contains the 2FA link, the admin panel, the notice and the
  invitation filter.
- PR 9 (gap reader) stays owner-gated. `invoice-domain-import.md` is not affected.

**Tests.** `client-import.md` §20, criteria 54–82, on the WHMCS simulator (ADR 0016):

- The simulator gains `ValidateLogin`, with scenario users that have an email, a password, an id and a 2FA flag.
- It gains two faults: a 3xx with `Location`, and a 200 with a non-JSON body.
- The scenario CA is trusted only in `testing`, failing closed as `Endpoints` does.
- Guard cases use a fake resolver, and timing uses `Sleep::fake()`.

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

**Licence dependency.** Full: it needs a database export from the host (§8, items 3 and 4; licence note §6, lawyer
questions 1 to 3).

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

**Licence dependency.** No export, but it is a live use of the host's install, so the same open points apply as for
option 2 (licence note §5). A question for the owner or a lawyer.

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
| Needs a DB export | No | **No** (licence point still open) | Yes | No |
| Old WHMCS reachable | No | Until cut-off (API only) | No | Until cut-off (browsers) |
| Host effort | Low | Low | High | Medium |
| Plaintext password leaves Mohar | No | Once per login, to the host's own WHMCS | No | No |
| 2FA accounts | Invitation | Mailbox confirmation, 2FA flag known | No hash, invitation | WHMCS's 2FA applies |
| Kernel change | None | One closed contract (§4), after ADR 0018 PR 0 | One column | ADR 0018 PR 0 |
| Size | Built | ~2,550 lines in 3 PRs | ~1,000 | ~1,500 after PR 0 |
| Depends on WHMCS keeping the feature | No | Yes (deprecation notice) | No | Yes |

## 4. Kernel shape (for the CTO; ADR 0018, 0012 and 0015 Amendments (#555))

The Fortify sign-in pipeline (`app/Actions/Fortify`, `FortifyServiceProvider`) cannot import the Migration module.
So the hook is one contract with a null default and one first-party implementer. It is not a registry, and after the
review it is explicitly closed to packages (B2).

```php
namespace App\Contracts\Auth;

/** Not an extension point: one first-party implementation (ADR 0012 Amendment (#555)). */
interface PendingPasswordCheck
{
    /** True while any source may answer, so the failure floor applies. Cached; never remote; never throws. */
    public function active(): bool;

    /** Whether this login has an open carry-over. Receives no secret. Cached; never remote; never throws. */
    public function pending(User $user): bool;

    /** Only for a non-staff login, after SignInGate, when pending() is true. Never inside a transaction. */
    public function check(User $user, #[\SensitiveParameter] string $password, string $ip): PendingPasswordOutcome;
}

/** Closed: a new case needs an ADR amendment (ADR 0010). */
enum PendingPasswordOutcome: string
{
    case NotApplicable = 'not_applicable';
    case Verified = 'verified';        // the new hash was written by compare-and-set against the placeholder
    case ConfirmByEmail = 'confirm';   // the source had 2FA, or its flag was missing; a link was mailed
    case Rejected = 'rejected';        // wrong password at the source; fires Failed
    case Unavailable = 'unavailable';  // no usable answer; no Failed, not a lockout failure
}
```

- **Binding.** Core binds the null implementation with `bindIf`. Migration binds the real one. An Arch test and a
  boot check allow nothing else.
- **The caller** is `Fortify::authenticateUsing`, which covers the pipeline with 2FA on and off (M7). The kernel
  maps any `Throwable` from `check()` to Unavailable.
- **Not step-up.** Step-up never calls it.
- **Must not be added:**
    - a second implementer;
    - any sign-in route other than the one confirmation pair, which signs in through `CompleteSignIn`;
    - a "migration" entry in `SignInMethods`;
    - an event per carry-over;
    - a generic external-password registry;
    - any column on `users`.

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

- `client-import.md`:
    - new §3.4, the binding requirements, with every #555 review finding;
    - A9 in §2;
    - §6.9 and §6.10 updated;
    - a permission note in §11;
    - C11 and C12 in §16;
    - PRs 8a to 8c in §19;
    - criteria 54–82 in §20;
    - Q13 in §21;
    - the sources in §23.
- `migration.md` §2.1: a note on the second allowlist, which names only `ValidateLogin`. It has its own credential,
  which is a separate `EncryptedStorage` entry and shares the base URL. The read-only allowlist is unchanged.
- ADR 0018 Amendment (#555), Proposed: the hook, outcomes and lockout, the confirmation route, verification and
  secrets.
- ADR 0012 Amendment (#555), Proposed: kernel contracts that receive secrets are closed to packages.
- ADR 0015 Amendment (#555), Proposed: outbound calls made inside a user request.

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
8. Whether WHMCS writes `ValidateLogin` parameters, including `password2`, to any log on the old host (the API log or
   the activity log). That would leave passwords there, and the wizard would have to tell hosts to turn that logging
   off or purge it (L2).
9. Whether a successful `ValidateLogin` makes WHMCS send the client a sign-in notice or alert, which clients would
   receive from the old system (L2).

Sample responses from a test install with fake data (Q11) settle items 1, 3 and 4. Items 2, 5, 8 and 9 need a host to
try them.

## 8. Owner questions (not legal advice)

These are `client-import.md` Q13.

1. **Approve option 2 as the default**, with a 90-day default window and a 180-day maximum?
2. **Sending the password onward.** Is it acceptable that Mohar forwards a returning client's typed password once,
   over TLS, to the host's own WHMCS? Should the wizard tell hosts to mention it in their privacy notice?
3. **Licence, for the owner or a lawyer.** A live `ValidateLogin` call sits under the open points of
   `docs/research/2026-10-31-whmcs-licence-terms.md` §5: EULA §2.5, and handing credentials to a third party (its
   lawyer question 2). It also has its own question: limits to a first-sign-in check, including during or after a
   licence lapse (its lawyer question 7). The call also updates the last-login time at the source.
4. **Option 3 stays parked** until the licence note's export question has an answer: may a host hand an export of
   their own WHMCS data to a third-party tool?
5. **2FA path.** The mailbox plus the password is stronger than "Forgot password". Recommendation: yes, with 2FA
   enrolment prompted at the next sign-in.
6. **TLS key changes.** The key is recorded on first use and enforced on every call, so a renewal that changes the key
   pauses carry-over until staff re-run Test. Is that acceptable? The wizard would advise reusing the key during the
   window.

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
  `docs/research/2026-10-31-whmcs-docs-coverage.md` §6, `docs/research/2026-10-31-whmcs-licence-terms.md` (§5, §6), `docs/handoff/requirements.md` (2026-10-17),
  `billing/docs/devops/sizing.md`, `billing/app/Actions/Fortify/{PasswordGuesses,RememberValidatedLogin,SignInValidatedUser}.php`,
  `billing/app/Contracts/Auth/ClientLoginInvitations.php`, `billing/tools/simulators/src/Providers/Whmcs/`.
