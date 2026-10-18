# cPanel/WHM Provisioning Driver — Product Spec (v1)

Status: draft for engineering · Owner: Services workstream (driver `cpanel`) · Roadmap 3.2, parity
`admin-and-client.md` §22.1 (P0) · Builds on ADR 0009 §2–§4 (`feature/kernel-provisioning`, PR B) and `services.md` §7–§9.
Researched 2026-10-18 from cPanel's WHM API 1 reference, cPanel's knowledge base, and the WHMCS and Blesta cPanel module
docs (§15). This is a clean-room summary in our own words. WHM parameter and field names are quoted only where the driver
has to send or parse them. Items marked **verify** are not stated clearly in the docs. The smoke command (§12) must
confirm them on the owner's WHM server before we rely on them.

## 1. Purpose and scope

Most hosts moving from WHMCS sell shared hosting on cPanel & WHM. This driver lets Mohar create and manage cPanel accounts
through WHM API 1. It covers the lifecycle (create, suspend, unsuspend, terminate), change package, change password,
disk and bandwidth usage, SSO into cPanel and WHM, server setup (test connection, package list, name servers), and
importing accounts that already exist on a server.

The driver is a stateless class registered as `cpanel` in `ProvisioningDriverRegistry` (ADR 0009 §5). It receives a
`ProvisioningContext` (DTOs, never models) and returns a `DriverResult`. It never touches Services tables. Services owns
queueing, locking, retries, the module log, the module queue and every status change (`services.md` §8).

**Capabilities implemented:** core `ProvisioningDriver`, `ChangesPackage`, `ChangesPassword`, `ReportsUsage`,
`TestsServerConnection`, `ProvidesServiceSso`, `ProvidesServerSso` and `ListsAccounts`. **Not implemented:**
`RenewsAccounts`, because a cPanel account has no expiry to extend; renewal is billing-only.

## 2. Transport and authentication

- **Endpoint.** `https://{host}:{port}/json-api/{function}?api.version=1`; `api.version=1` is mandatory. Booleans are
  `1`/`0`, and parameters are URI-encoded.
- **Port.** `2087` by default. `443` is allowed for WHM behind a service subdomain (`whm.example.com`). Plain `2086` is
  **refused**, so credentials never cross the network unencrypted.
- **Host.** Connect to `hostname` when set, so certificates are checked against a name. Use `ip` only without a
  hostname, and then require a pinned key (§9.3), because CAs rarely issue certificates for IP addresses.
- **API token (preferred, the UI default).** Header `Authorization: whm {username}:{token}`, where `username` is `root` or
  the reseller that owns the token. A token can carry an ACL subset, an expiry and an IP allow-list, and its value cannot
  be viewed again after it is created.
- **Username and password (allowed, discouraged).** HTTP Basic over TLS. It fails when WHM's two-factor policy covers API
  requests. Test connection detects this and says so, and the form recommends a token.
- **Access hash: not supported**, because cPanel deprecated it. Servers from WHMCS with only an access hash arrive as
  stubs without credentials (`migration.md` §8).
- **Root vs reseller.** Both are supported in v1 (§14 Q1). A reseller connection only sees and manages accounts it owns,
  uses packages prefixed with its username (plus global ones with `viewglobalpackages`), draws dedicated IPs from its own
  pool, and cannot unsuspend an account root locked. Test connection records the kind (§8.1).
- **Method.** Mutating calls are sent as form-encoded `POST`, so passwords stay out of `cpsrvd` access logs. The
  reference documents only GET: **verify** POST per function, and fall back to GET only where it is refused.
- **Timeouts.** Connect 10 s. Read 20 s for reads, and 50 s for `createacct`, `removeacct` and `changepackage`, below
  `services.module.job_timeout_seconds` (60). A slower `createacct` is an unknown outcome, not a failure (§6, §14 Q6).
  Redirects are not followed, and response bodies are capped at 5 MB.

### Required token ACLs

| ACL                                                 | Needed for                                             | Required     |
| --------------------------------------------------- | ------------------------------------------------------ | ------------ |
| `basic-whm-functions`, `basic-system-info`          | `version`, `applist`                                   | MUST         |
| `list-accts`, `acct-summary`                        | `listaccts`, `accountsummary` (lookups, import, usage) | MUST         |
| `create-acct`                                       | `createacct`, `verify_new_username`                    | MUST         |
| `suspend-acct`                                      | `suspendacct`, `unsuspendacct`                         | MUST         |
| `kill-acct`                                         | `removeacct`                                           | MUST         |
| `upgrade-account`                                   | `changepackage`                                        | MUST         |
| `passwd`                                            | `passwd`                                               | MUST         |
| `list-pkgs` (+ `viewglobalpackages` for global use) | `listpkgs`                                             | MUST         |
| `show-bandwidth`                                    | `showbw`                                               | MUST         |
| `ns-config`                                         | `get_nameserver_config`                                | SHOULD       |
| `create-user-session`                               | SSO (§7)                                               | only for SSO |

The ACL names come from cPanel's `myprivs` reference and WHMCS's token list. **Verify** the exact ACL each function
needs with the smoke command (§12). cPanel warns that `create-user-session` lets the token's holder sign in as any
account the token's owner owns, which gets around the token's other limits. The server form says this in plain words
beside the SSO toggle. WHMCS asks for a wider list (`cpanel-api`, `edit-mx`, `ssl-gencrt`, `manage-api-tokens`, and
"everything" for resellers) because it covers features that are out of scope here (§16). **Better:** asking for the
smallest ACL set, and testing it (§8.1), means a leaked token can do less harm.

## 3. Lifecycle mapping to WHM API 1

| Contract method                          | WHM call(s)                                                                       | Notes                                                                                                                                                    |
| ---------------------------------------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `create`                                 | `verify_new_username` → `createacct` → `accountsummary`                           | §3.1                                                                                                                                                     |
| `suspend`                                | `suspendacct user, reason, disallowun=0`                                          | §3.2                                                                                                                                                     |
| `unsuspend`                              | `unsuspendacct user`                                                              | Refused for a root-locked account on a reseller connection (`is_locked=1`): rejected, with the message "suspended by the server administrator".          |
| `terminate`                              | `removeacct username, keepdns`                                                    | §3.3                                                                                                                                                     |
| `ChangesPackage`                         | `changepackage user, pkg`                                                         | Skipped as completed when `accountsummary.plan` already equals the target. Unknown package → rejected before the call (§4.2).                            |
| `ChangesPassword`                        | `passwd user, password, db_pass_update=1`                                         | Also changes the MySQL password, so database logins match. A strength rejection is shown to the client as "the server rejected this password: too weak". |
| `ReportsUsage`                           | `listaccts want=user,diskused,disklimit` + `showbw` (current month)               | Two calls per server, not two per account (§3.4).                                                                                                        |
| `ListsAccounts`                          | `listaccts` (paginated with `api.chunk.*`)                                        | Import and reconcile (§10).                                                                                                                              |
| `TestsServerConnection`                  | `version`, `api_token_get_details`/`myprivs`, `listpkgs`, `get_nameserver_config` | §8.1                                                                                                                                                     |
| `ProvidesServiceSso`/`ProvidesServerSso` | `create_user_session`                                                             | §7                                                                                                                                                       |

### 3.1 Create

1. Services assigns and persists `username` and `password` before the first attempt, so retries reuse them. A username
   that breaks the cPanel policy (§4.1) is rejected without a remote call.
2. **Pre-check (read-only):** `verify_new_username`. On a conflict, run the existing-account check (§6.2). If the account
   is not ours, pick the next free name (§4.1, at most 5 tries, each logged) and return it in `DriverResult::$data['username']`
   (ADR 0009 §2).
3. `createacct` with:
    - `username`, `domain`, `plan` = product `driver_config.package` (§4.2), `password`
    - `contactemail` = `ClientSummary.email`, unless the product setting `send_contact_email` is off
    - `ip` = `y` when the product setting `dedicated_ip` is on, or the config option with module key `dedicated_ip` is set
    - `showpass=n`, so the password is not echoed back in `metadata.output.raw`
    - `forcedns=0`, so an existing DNS zone is never overwritten
    - `cpmod` and `language` only when the product sets them
    - never the quota or limit parameters, which conflict with `plan` and need `edit-account` (§16)
4. On `metadata.result=1`, read back `accountsummary` for the assigned `ip`, `plan`, `owner` and `domain`. Return
   completed with `dedicated_ip` (only if requested) and `driver_data = {owner, plan, ip, created_at}`. A failed read-back
   still returns completed, and the next usage sync fills `driver_data.ip`.
5. **Domain.** Without a domain, WHM would make a temporary one. We reject with "a domain is required" unless the product
   setting `allow_temporary_domain` (default off) allows it. A domain already on the server is rejected with "domain
   already on server". The usual cause is a DNS zone kept by an earlier termination (§3.3).

### 3.2 Suspend

`reason` comes from Services: "overdue" becomes "Overdue invoice", `staff:<text>` becomes the text. Control characters
are stripped and it is cut to 100 characters. It never holds client names or emails, because every WHM operator sees it.
`disallowun=0` keeps reseller connections able to unsuspend. If WHM returns `result=0` but `accountsummary` already shows
`suspended=1`, the outcome is completed. Unsuspend mirrors this.

### 3.3 Terminate

`removeacct` deletes the home directory, mail, databases and, by default, the DNS zone. **Nothing is kept on the
server** except WHM's own scheduled backups, if the host runs them, and Mohar takes none; the terminate dialog says so.
"Keep DNS zone" (`keepdns=1`) is a dialog checkbox and a server setting for automated terminations, both off by default,
with a warning that a kept zone blocks re-creating that domain. An account already absent (§6.2) completes, with
"account was not on the server" logged. The service keeps `username`, `domain` and `server_id` for history.

### 3.4 Usage

`listaccts` returns `diskused` as a string in MiB with an `M` suffix ("14M") and `disklimit` as MiB or "unlimited".
`showbw` returns `totalbytes` and `limit` in bytes (`limit` 0 = unlimited). These map to `UsageRow`
(`diskUsedMb`, `diskLimitMb` with null for unlimited, `bandwidthUsedMb` = bytes ÷ 1,048,576 rounded half-up,
`bandwidthLimitMb`), keyed by username. Accounts the connection does not own never appear. The daily `services:usage-sync`
step (`services.md` §10) is the only caller.

## 4. Usernames and packages

### 4.1 Username rules

cPanel accepts up to 16 lowercase letters and digits. A username cannot start with a digit or `test`, cannot end in
`assword`, and cannot be a reserved system name or mail alias (`admin`, `root`, `mail`, `www`, `support`, `postmaster` and
others; §15). With MySQL or PostgreSQL, the **first 8 characters must be unique on the server**, because they prefix
database names.

Our rule keeps `services.md` §8 (lowercase letters and digits, starts with a letter, at most 8 characters including a
numeric suffix, unique per server), which makes the first-8 rule automatic. It adds the cPanel checks: skip a base that
starts with `test`, ends with `assword`, or is on the reserved list (shipped as driver data, extendable in server
settings). Collisions try the next suffix locally, then remotely with `verify_new_username` (§3.1). Usernames staff type
are checked against the same policy. **Better:** WHMCS learns that a name is reserved or taken only when `createacct`
fails. Checking first turns a failed order into a silent retry with the next name.

### 4.2 Packages

`driver_config.package` must match a WHM package name exactly, case included. Root packages are named `root_<name>`,
reseller packages `<reseller>_<name>`, and names without an underscore are global. The product's select (§8.2) stores
names exactly as `listpkgs want=creatable` returns them, so no prefix is ever worked out. Before `createacct` and
`changepackage` the driver checks the package against `listpkgs` (cached 10 minutes per server). A missing package is
**rejected** with "package <name> is not available to <user> on <server>". The usual causes are a typo, a missing ACL,
or a reseller whose limits rule the package out. v1 neither creates packages nor sends custom quotas (§16).

## 5. Errors and the outcome vocabulary

WHM returns HTTP 200 with `metadata.result` (`1` success, `0` failure) and a free-text `metadata.reason`. There are no
error codes, so classification uses HTTP status and transport state first, and the reason text only as a last step.
Reason matching is case-insensitive substring matching against a table in the driver. Every match is covered by a fixture.

| Signal                                                                                                                                 | ADR 0009 outcome | Shown to staff as | Notes                                                                                                                                   |
| -------------------------------------------------------------------------------------------------------------------------------------- | ---------------- | ----------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `result=1`                                                                                                                             | completed        | success           |                                                                                                                                         |
| DNS failure or connection refused (cURL 6/7)                                                                                           | retryable        | failed (retrying) | The request provably never ran.                                                                                                         |
| TLS verification or pin failure (cURL 35/60/90)                                                                                        | rejected         | needs attention   | Nothing was sent, but retrying cannot fix a changed certificate (§9.3).                                                                 |
| HTTP 401/403, "Access denied", "Permission denied", "Login failed"                                                                     | rejected         | needs attention   | Bad credentials, a missing ACL, or a cPHulk lockout. Services pauses the server (§9.4).                                                 |
| `result=0` with a known validation reason (reserved or taken name, domain exists, package not allowed, weak password, invalid `cpmod`) | rejected         | needs attention   | Nothing ran. The message is ours, and the raw reason goes to the module log.                                                            |
| `result=0`, reason not recognised                                                                                                      | rejected         | needs attention   | WHM functions validate before acting, so `result=0` means the change was refused. **Verify** this for `createacct` on the smoke server. |
| Read timeout, connection reset after send, HTTP 5xx, or a body that does not parse as WHM JSON, on a mutating call                     | unknown          | unknown (verify)  | `DriverResult::$outcomeUnknown = true`. Never retried automatically (§6).                                                               |
| The same on a read call                                                                                                                | retryable        | failed (retrying) | Reads are safe to repeat.                                                                                                               |
| "license" in the reason, or the server profile is not Standard                                                                         | rejected         | needs attention   | The server cannot host accounts. The message says to fix the cPanel licence.                                                            |

`DriverResult::$message` is always our own sentence. Raw `reason` and `metadata.output.raw` are kept only in the
redacted module log response, and `output.raw` is cut to 4 KB because it can hold HTML.

## 6. Idempotency and unknown outcomes

WHM has no idempotency keys. `createacct` keeps running on the server after the client disconnects. A timeout therefore
says nothing about whether the account exists, and "absent" straight after a timeout proves nothing either.

### 6.1 Rules

- The username, password and server are fixed before the first attempt (§3.1), so every retry targets the same account.
- A mutating call that may have run returns `unknown`. Services opens a **verify** module-queue item and blocks any further
  `create` until the outcome is settled (`ExecuteModuleCommand` already does this).
- Suspend, unsuspend, change package and change password are naturally idempotent: they set a state. Services may let
  staff retry them after an unknown outcome without a lookup.

### 6.2 Existing-account check (used by create and by verify items)

`listaccts searchtype=user searchmethod=exact search=<username>`, plus `searchtype=domain` for the domain. `listaccts`
is used instead of `accountsummary` because "no such account" then comes back as an empty list with `result=1`, which
is unambiguous. The account is **ours** when the username matches, its `domain` equals the service domain, and its
`owner` is the connection user (or, for root, `root`). The result is one of four:

- `executed`: ours. Create is completed with the read-back data (§3.1 step 4).
- `conflict`: the username or domain exists but is not ours. Create is rejected and needs attention, with the conflicting
  owner and domain shown.
- `absent`: neither exists. If at least `services.cpanel.unknown_settle_minutes` (default 10) have passed since the
  unknown attempt, staff get a one-click "Create again". It is never automatic.
- `unknown`: the lookup itself failed. The item stays open.

**Better:** WHMCS leaves the operator to check WHM by hand and click Create again. That risks a second account under a new
username. Mohar runs the lookup for the operator: a verify item with an `executed` result resolves itself on the next
sweep (every 5 minutes for 1 hour, then on demand). This needs contract change K3 (§13).

## 7. SSO

- **Client into cPanel** (`ProvidesServiceSso`): `create_user_session` with `user=<username>`, `service=cpaneld`,
  `preferred_domain=<server hostname>`, and an optional `app` for shortcuts (SHOULD: `Email_Accounts`, `FileManager_Home`,
  `Database_phpMyAdmin`, `Backups_Home`; K7). Returns `SsoLink(url, expiresAt)` with `expiresAt` from `data.expires`.
  cPanel also ends idle sessions after 15 minutes.
- **Staff into WHM** (`ProvidesServerSso`): `create_user_session user=<connection username> service=whostmgrd`. With a
  root token this is a full root session, so it needs the new sensitive permission `services.servers.sso` and is audited.
  Staff entering a client's cPanel use the client flow with sensitive `services.services.sso`, also audited.
- **Handling.** The link is created on click: a POST to our endpoint answers 302 with `Referrer-Policy: no-referrer`. The
  URL is a bearer credential and never appears in HTML, mail or any log. The log says only "session created, expires at …".
  Clients get links for `active` services only, at most 10 per hour per service. Without `create-user-session` on the
  token, the buttons are hidden and the server page says why.

## 8. Server setup UX

### 8.1 Test connection (`TestsServerConnection` → `ConnectionCheck`)

One button runs these steps and shows a checklist; each line is pass, warn or fail with a plain sentence.

1. Address guard (§9.2), then the TLS handshake and pin check. On failure, show the certificate's subject, issuer, expiry
   and SPKI SHA-256, with "Trust this certificate" (§9.3).
2. `version`: credentials work. Shows the WHM version. If it is older than 11.110, warn that it is untested
   (`verify_new_username` needs 11.58+ and `getdomainowner` needs 11.78+).
3. `api_token_get_details` (token auth) reads the token's ACLs, expiry and IP allow-list. When the token cannot read
   itself, fall back to `myprivs` (**verify** which ACL `api_token_get_details` needs). Missing MUST ACLs fail, missing
   SSO or name-server ACLs warn, and an expiry within 14 days warns.
4. Root or reseller (`myprivs` `all=1` means root). Resellers also see `acctcounts` (active, suspended, limit).
5. `listpkgs want=creatable`: the count. Zero warns "no packages this user can assign".
6. `get_nameserver_config`: offers to copy the name servers into the server record (§8.3).

The result is stored on the server (`last_checked_at`, summary, token expiry) and feeds the server list's health column
and the dashboard. A failed test does not block saving.

### 8.2 Package sync for the product editor

The `package` field is a select filled from `listpkgs want=creatable` for the product's server, or for every active
server in its group, cached for 10 minutes, with a Refresh button. Each package shows its disk and bandwidth quota. For a
group, a package missing on some servers shows a warning that names them, because provisioning on those servers would
be rejected (§4.2). If no server is reachable, the field falls back to free text with a warning. Contract changes K2
and K10 cover this.

### 8.3 Name servers shown to clients

`servers.nameservers` (up to 5) stays staff-owned, as today. The server form has "Fetch from WHM", which fills it from
`get_nameserver_config` for staff to confirm. It is never synced silently, because a reseller's name servers often differ
from WHM's defaults. Name servers reach clients through the welcome email and the service page, as merge fields
`server.nameservers` and `service.cpanel_url` (`https://<hostname>:2083`), registered in `MergeFieldRegistry`.

## 9. Security

### 9.1 Secrets

- The token and password live in `servers.api_token` and `servers.password`, encrypted at rest and write-only in the UI
  ("set · last 4"). Changing them needs the sensitive `services.servers.manage` (password step-up). The SPKI pin is
  public and stored as a plain setting.
- The `Authorization` header is never logged. The module log stores request parameters only, and only after
  `App\Support\Integrations\Redactor`. The current key pattern already covers `password`, `passwd`, `token`, `hash` and
  `authorization`. Contract change K5 adds `pass` (createacct's alias), `session`, `cp_security_token` and `url` on SSO
  responses, plus a value pattern for `cpsess\d+`. Credential values are masked wherever they appear, as today.
- `showpass=n` on create (§3.1). `output.raw` is redacted and cut to 4 KB (§5).
- Exceptions and `DriverResult::$message` never include request parameters. Test fixtures use fake tokens that the
  redaction tests then look for.

### 9.2 Staff-entered hostnames (SSRF)

Decision: **allow private addresses** (RFC 1918, IPv6 ULA) by default. A panel on the billing server's LAN or VPC is
normal, blocking it would break real installs, and only staff with the sensitive `services.servers.manage` can set a
host. The guard always refuses loopback (`127.0.0.0/8`, `::1`), unspecified, link-local (`169.254.0.0/16`, `fe80::/10`,
which covers cloud metadata endpoints), multicast and broadcast addresses, and ports other than 2087, 443 or 1024–65535.
The hostname is resolved once per request, checked, and the connection pinned to the checked IP (no DNS rebinding).
Redirects are not followed, and failed-call bodies reach staff only as our message plus a capped, redacted snippet, so
the test button cannot read internal services. `services.cpanel.allow_private_addresses` (default `true`) lets a SaaS
deployment turn private ranges off. Loopback is allowed only in `APP_ENV=local`. The guard belongs in the kernel, because
registrar and gateway drivers need it too (K11).

### 9.3 Certificate verification and pinning

- **Default:** full verification (CA chain and hostname).
- **Pinned:** the server stores an SPKI SHA-256 pin. The handshake must present that public key, and CA and hostname
  checks are skipped. This supports self-signed certificates and IP-only servers. The pin is set by "Trust this
  certificate" in test connection, after staff compare the fingerprint, and that action is audited.
- There is **no "disable verification" option**. Many category setups (and cPanel's own examples) turn verification off,
  which lets anyone on the path steal a root token. **Better:** trust-on-first-use pinning keeps self-signed servers
  working without that risk.
- When the pin no longer matches (for example, AutoSSL issued a new key), calls are rejected as needs attention with
  "server certificate changed". Staff re-trust it after checking.

### 9.4 Lockout protection (SHOULD)

cPHulk blocks an IP after repeated failed logins. After an auth failure (§5), Services marks the server
`credentials_failing` and holds queued commands for it, instead of failing each one. A successful test connection clears
the flag, and the dashboard shows the paused count. **Better:** one bad token does not turn into a cPHulk block on the
billing server's IP.

## 10. Importing existing accounts (`ListsAccounts`)

This is the onboarding path for hosts who bring accounts that already exist on WHM. It complements `import:whmcs`
(`migration.md`), which brings services from WHMCS with `module=cpanel` mapped to driver `cpanel`, and
`driver_config.package` taken from the WHMCS product's WHM package setting (**verify** the field in the importer fixtures).

**Flow.** The page is Admin → Servers → server → Import accounts, with the new permission `services.servers.import`.

1. Fetch: `listaccts`, in pages of 500 (`api.chunk.enable=1`), mapped to `RemoteAccount` (K4). The fetch runs as a queued
   job, and the result is kept for 24 hours as an import session.
2. Classify each remote account:
    - **Linked:** a service here already has the username. Differences (domain, plan, suspended, IP) are shown, and staff
      may accept the domain, IP or status from the server per row. Products are never changed here.
    - **Proposed:** exactly one client has the contact email (case-insensitive), and exactly one `cpanel` product uses the
      account's `plan` on this server or its group.
    - **Unmatched:** staff pick a client and product, Skip, or Ignore (remembered per server and username).
    - **Reseller-owned** (root connection, `owner` ≠ `root`): listed separately, unselected by default.
    - **Missing on server:** local pending, active or suspended services with no remote account. Flagged only.
3. Preview: staff set cycle and next due date per row or in bulk. The recurring amount comes from `PriceBook` in the
   client's currency, with totals shown.
4. Apply, one transaction per row: the service is created `active`, or `suspended` with the reason
   "staff:imported (suspended on server: …)", with `registration_date` from `unix_startdate`, `driver_data`, and no
   password (the client sets one). **No** invoice, welcome mail or module command. `ServiceImported` (K8) is emitted.
   A unique active `(server_id, username)` prevents a double import. A per-row report follows.

**Better:** WHMCS's sync creates a new client for every unmatched email, using a placeholder address when there is none.
It also marks local services terminated when they are missing on the server. Mohar never creates a client or ends a
service from an import. An import adds services that staff approved, and nothing else.

## 11. Events and module integration

- **Emitted by the driver:** none, because drivers are stateless (ADR 0009 §2). Services dispatches
  `ModuleCommandSucceeded`, `ModuleCommandFailed` (with `outcomeUnknown`), `ServicePackageChanged` (`remoteApplied: true`
  after `changepackage`), `ServicePasswordChanged`, `ServiceSuspended`/`Unsuspended`/`Terminated` and `ServiceActivated`
  (`services.md` §13, ADR 0009 §6).
- **New (K8):** `Services\ServiceImported` (`serviceId`, `clientId`, `serverId`, `driver`, `source: server_import`), after
  commit, ids and scalars only (ADR 0011). Notifications sends no welcome mail from it.
- **Consumed:** none directly. The driver runs only through `ExecuteModuleCommand`, which is triggered by `InvoicePaid`,
  `OrderPaid`, staff actions and the daily pipeline.
- **Contracts (ADR 0008/0009):** Catalog reads `productConfigFields` through `Services\ProvisioningDrivers`, with dynamic
  package options (K2). Orders provisions through `ServiceRegistry::provision`. Notifications gets the §8.3 merge fields.
  Import uses `PriceBook` for amounts and product lookup (K10), and finds clients by email through Core.
- **Driver settings.** `serverFields`: `auth_type` (`token`/`password`), `spki_pin` (String), `keepdns_on_auto_terminate`,
  `sso_enabled` (Boolean), `reserved_usernames` (Text). `productConfigFields`: `package` (Select, dynamic), `dedicated_ip`,
  `send_contact_email` (default on), `allow_temporary_domain` (default off) (Boolean), `cpmod`, `language` (String).

## 12. Testing without a real server

- **Fixtures** (`tests/Fixtures/Cpanel/*.json`) are written by hand from the documented response shapes. A real server's
  response is never copied in without redaction. They cover:
    - `version`, `myprivs` (root and reseller), `api_token_get_details`
    - `createacct` ok, and failing for: name taken, reserved name, domain exists, package not allowed, weak password
    - `accountsummary`; `suspendacct` ok and already suspended; `unsuspendacct` and a locked account
    - `removeacct`, `changepackage`, `passwd`
    - `listaccts`: empty, and 3 accounts (suspended, reseller-owned, temporary domain) over two pages
    - `listpkgs` with `root_`, reseller-prefixed and global names; `showbw`; `get_nameserver_config`; `create_user_session`
    - an HTML 401, a 500, a truncated body and a read timeout
- **Tests** use `Http::fake` sequences. The driver passes the shared `ProvisioningDriverContract` dataset (ADR 0009 PR B),
  plus cPanel cases for every §5 row, the §6.2 outcomes, the §4.1 rules, redaction (§9.1) and the address guard (§9.2).
- **`php artisan provisioning:cpanel-smoke {server} [--lifecycle --domain=<test domain> [--package2=<pkg>]] [--record=<dir>]`**
  runs against the owner's WHM, never in CI.
    - Without `--lifecycle` it is read-only: the §8.1 steps, a `listaccts` count, and `showbw`.
    - `--lifecycle` creates one throwaway account (`mhsmk` + 3 random characters) on the given test domain. It then runs:
      suspend → check → unsuspend → passwd → changepackage (if given) → SSO (prints host and expiry, never the URL) →
      removeacct → check absent.
    - It never removes an account it did not create in that run. It asks for confirmation when the server holds other
      accounts. It prints each step's outcome and timing.
    - It settles every **verify** item: POST support, the ACL that `api_token_get_details` needs, `result=0` having no
      side effects, and the read-back fields. `--record` writes redacted responses for refreshing fixtures.

## 13. Contract changes this spec implies (for the CTO and principal architect)

| Id  | Change                                                                                                                                                                                                                                                                                                           | Why                                                                                  |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| K1  | `ServerConnection` gains `?apiToken` (a sensitive parameter, included in `secrets()`).                                                                                                                                                                                                                           | ADR 0009 §2 lists password and access hash but no token; `servers.api_token` exists. |
| K2  | Dynamic options for a product config field: `SettingField` gets an `optionsSource` (for example `'remote:packages'`). New capability `ListsPackages::packages(ServerConnection): list<RemotePackage(name, diskMb?, bandwidthMb?)>`. `ProvisioningDrivers` gains `remoteOptions(driverKey, fieldKey, serverIds)`. | The package select (§8.2).                                                           |
| K3  | Capability `FindsAccounts::findAccount(ServerConnection, string $username, ?string $domain): AccountLookup(status executed\|conflict\|absent\|unknown, ?RemoteAccount)`. Services' verify sweep calls it.                                                                                                        | Auto-resolving unknown creates (§6.2); mirrors registrars C2.                        |
| K4  | `RemoteAccount` fields: `username`, `domain`, `plan`, `owner`, `?email`, `?ip`, `suspended`, `?suspendReason`, `?createdAt`, `?diskUsedMb`, `?diskLimitMb`.                                                                                                                                                      | Import (§10). ADR 0009 names the DTO without fields.                                 |
| K5  | `Redactor` adds keys `pass`, `session`, `cp_security_token`, and `url` in SSO responses, plus the value pattern `cpsess\d+`.                                                                                                                                                                                     | §9.1.                                                                                |
| K6  | `DriverMetadata::usernamePolicy` (max length, pattern, reserved prefixes, suffixes and names). `UsernameGenerator` and the service edit form apply it.                                                                                                                                                           | §4.1, generation in Services but rules owned by the driver.                          |
| K7  | `ProvidesServiceSso::serviceSso(ProvisioningContext, ?string $target = null)` plus `ssoTargets(): list<string>`.                                                                                                                                                                                                 | cPanel shortcuts (parity P0 "SSO and quick shortcuts"). SHOULD.                      |
| K8  | Event `Services\ServiceImported`.                                                                                                                                                                                                                                                                                | §10, §11.                                                                            |
| K9  | Per-driver command timeout (`DriverMetadata::commandTimeoutSeconds`), or higher `provisioning` queue timeouts (ADR 0014).                                                                                                                                                                                        | `createacct` can exceed 50 s (§2).                                                   |
| K10 | `PriceBook::productsForDriver(string $key): list<{id, name, driverConfig, serverGroupId}>`.                                                                                                                                                                                                                      | Import product matching and package warnings (§8.2, §10).                            |
| K11 | Kernel `App\Support\Integrations\OutboundAddressGuard` (resolve, check, pin IP).                                                                                                                                                                                                                                 | §9.2; shared with registrars and gateways.                                           |

## 14. Open questions for the owner

1. **Root vs reseller connections in v1.** Recommendation: support both. Reseller connections are a common setup for
   small hosts, and the limits are documented (§2).
2. **Selling reseller hosting** (creating WHM resellers with ACL lists and limits, which needs a root "everything" token).
   Recommendation: later (§16).
3. **Private addresses** allowed by default (§9.2)? Recommendation: yes for self-hosted installs; SaaS turns them off.
4. **Custom quotas per product or config option**, like WHMCS advanced mode (needs `edit-account`, which cPanel warns lets
   holders exceed limits). Recommendation: no in v1; existing WHM packages only.
5. **Import of unmatched accounts:** allow "create client from this email" per row (a COULD), or require an existing
   client? Recommendation: require an existing client in v1.
6. **Queue timeouts:** raise the provisioning job timeout to about 180 s (K9), or accept more unknown outcomes on slow
   servers?
7. **Smoke server:** which test domain, and is `--lifecycle` allowed on a production WHM, or only on a dedicated test
   server?
8. **Username length:** keep 8 characters (safe for the database prefix) or allow up to 16 when the server has no
   database prefixing?

## 15. Sources (all read 2026-10-18)

**WHM API 1 functions used.** Each page is `https://api.docs.cpanel.net/openapi/whm/operation/<function>/`, and all of
them were read through cPanel's published OpenAPI document `https://api.docs.cpanel.net/_spec/specifications/whm.openapi.yaml`:
`createacct`, `verify_new_username`, `suspendacct`, `unsuspendacct`, `removeacct`, `changepackage`, `passwd`,
`accountsummary`, `listaccts`, `showbw`, `listpkgs`, `create_user_session`, `version`, `myprivs` (ACL names),
`api_token_get_details`, `acctcounts` and `get_nameserver_config`. `getdomainowner` was read for its version note only.

**cPanel guides and knowledge base.**

- WHM API 1 introduction (URL format, ports, `api.version`) — https://api.docs.cpanel.net/whm/introduction/ ·
  pagination — https://api.docs.cpanel.net/whm/paginate-output/
- API authentication: tokens in WHM (header, ports 2087/443), single sign-on (session lifetime), username and password,
  two-factor auth, access hash (deprecated) — https://api.docs.cpanel.net/guides/guide-to-api-authentication/ and its
  sub-pages
- Reserved, invalid and misconfigured usernames —
  https://docs.cpanel.net/knowledge-base/accounts/reserved-invalid-and-misconfigured-username/
- Guide to reseller accounts (package prefixes, `viewglobalpackages`) —
  https://docs.cpanel.net/knowledge-base/accounts/guide-to-reseller-accounts/

**Category (behaviour parity only).**

- WHMCS: cPanel module (features, token ACLs, keep DNS, 2FA, error table) — https://docs.whmcs.com/9-0/servers/server-modules/cpanel/ ·
  create a cPanel server — https://docs.whmcs.com/9-0/servers/server-tutorials/create-a-cpanel-server/ · servers and
  port overrides — https://docs.whmcs.com/9-0/servers/servers · Sync Accounts — https://docs.whmcs.com/9-0/servers/sync-accounts ·
  migrate cPanel services — https://docs.whmcs.com/9-0/servers/server-tutorials/migrate-cpanel-services/ · WHMCS Connect —
  https://docs.whmcs.com/9-0/servers/whmcs-connect · cPanel error articles (group exists, missing packages, package not
  allowed, username reserved, login failed, access denied) — https://docs.whmcs.com/9-0/troubleshooting/troubleshoot-servers/cpanel-servers/
- Blesta cPanel module (token or remote key, reseller ACL, dedicated IP) — https://docs.blesta.com/integrations/modules/cpanel/

## 16. Out of scope (v1)

DNS zone editing, email accounts and forwarders, databases, SSL install (AutoSSL is the server's job), WP Toolkit and
Sitejet add-on features, and other cPanel UAPI features. Webmail SSO (`webmaild`). Selling WHM reseller accounts (ACL
lists, reseller limits, per-account metric billing). Creating packages, or custom per-account quotas through config
options (`modifyacct`, `editpkg`). Changing an account's domain, username, contact email or dedicated IP after creation.
Moving accounts between servers (WHM transfers). Status monitoring of servers. WHMCS Connect–style framed multi-server
console. Remote access keys.

## 17. Acceptance criteria

1. **MUST** Given a server with token auth, when any call is made, then it goes to `https://{host}:2087/json-api/{fn}`
   with `api.version=1` and header `Authorization: whm {user}:{token}`, and port 2086 cannot be saved.
2. **MUST** Given a product with package `root_basic` and a paid order for `example-shop.com`, when create runs, then
   `createacct` is sent with `username`, `domain`, `plan=root_basic`, `password`, `contactemail`, `showpass=n` and
   `forcedns=0`, and the service becomes active with `driver_data.ip` taken from the `accountsummary` read-back.
3. **MUST** Given `verify_new_username` reports a conflict for `examples` and the account is not ours, when create runs,
   then the driver tries `example1`, creates it, and returns `username=example1` in the result data.
4. **MUST** Given a domain whose label is `testsite` or `password`, when a username is generated, then it neither starts
   with `test` nor ends with `assword`, and it is at most 8 characters.
5. **MUST** Given `createacct` times out after the request was sent, then the result is unknown (`outcomeUnknown=true`),
   no automatic retry happens, and a verify queue item is open.
6. **MUST** Given that verify item and a `listaccts` exact match with the same domain and owner, when the sweep runs,
   then the create is completed, the service is active, and the item is resolved.
7. **MUST** Given a verify item whose lookup finds the username with a different domain or owner, then create is
   rejected as needs attention naming the conflict, and no `createacct` is sent.
8. **MUST** Given the lookup finds nothing less than 10 minutes after the unknown attempt, then "Create again" is not
   offered. After 10 minutes it is offered to staff, and it is never run automatically.
9. **MUST** Given a connection refused or DNS failure on create, then the result is retryable. Given HTTP 401, then it is
   rejected as needs attention, and the message names credentials or ACLs.
10. **MUST** Given `result=0` with "Sorry, that username is reserved", then the result is rejected with our message, and
    the raw reason appears only in the module log.
11. **MUST** Given suspend for overdue, then `suspendacct` is sent with `reason="Overdue invoice"` and `disallowun=0`.
    Given it returns `result=0` but `accountsummary` shows `suspended=1`, then suspend is completed.
12. **MUST** Given a reseller connection and an account with `is_locked=1`, when unsuspend runs, then it is rejected
    with "suspended by the server administrator".
13. **MUST** Given terminate with default settings, then `removeacct keepdns=0` is sent, and `keepdns=1` when staff tick
    "Keep DNS zone". Given the account is already absent, then terminate is completed, and the log says so.
14. **MUST** Given change package to `root_pro` where the account plan is already `root_pro`, then no `changepackage` is
    sent, and the result is completed.
15. **MUST** Given a package not in `listpkgs want=creatable`, when create or change package runs, then it is rejected
    before any mutating call, with the package and server named.
16. **MUST** Given change password, then `passwd` is sent with `db_pass_update=1`, and a strength rejection is shown to
    the client as a readable message.
17. **MUST** Given `listaccts` returns `diskused="14M"`, `disklimit="unlimited"` and `showbw` returns `totalbytes`
    10485760 and `limit` 0, then the usage row is disk 14 / null and bandwidth 10 / null.
18. **MUST** Given any module log entry for create, change password or SSO, then it contains no token, password,
    `session` value or `cpsess` string, and `output.raw` is at most 4 KB.
19. **MUST** Given a hostname resolving to `127.0.0.1`, `169.254.169.254` or `::1`, then test connection and every
    command refuse it before connecting (except loopback in `APP_ENV=local`). Given `10.0.0.5`, then it is allowed while
    `allow_private_addresses` is true. Given DNS changes between the check and the connection, then the checked IP is used.
20. **MUST** Given a self-signed certificate and no pin, then calls fail with a TLS error, and there is no option to
    disable verification. Given staff trust its SPKI pin, then calls succeed. Given the key later changes, then calls are
    rejected with "server certificate changed".
21. **MUST** Given test connection on a token missing `kill-acct`, then the checklist fails that line by name. Given a
    token missing only `create-user-session`, then it warns, and the SSO buttons are hidden.
22. **MUST** Given the product editor with a server group of two servers where `root_pro` exists on one, then the
    package select lists it with a warning naming the other server.
23. **MUST** Given a client clicks "Log in to cPanel" on an active service, then a POST creates the session and the
    client is redirected. The URL never appears in HTML, logs or mail, and an 11th click within an hour is refused.
24. **MUST** Given staff use "Log in to WHM" or log in to a client's cPanel, then the matching sensitive permission is
    required, and an audit entry is written.
25. **MUST** Given an import where one remote account's email matches exactly one client and its plan matches exactly
    one product, then it is proposed with both. Given no client match, then it is unmatched, and no client is created.
26. **MUST** Given a local active service whose account is missing on the server, then the import flags it, and its
    status is unchanged.
27. **MUST** Given import apply for a suspended remote account, then the service is created suspended, with no invoice,
    welcome mail or module command, and `ServiceImported` is dispatched once. Applying again creates nothing.
28. **MUST** Given a root connection, then reseller-owned accounts are listed separately and unselected by default.
29. **MUST** The driver passes the shared `ProvisioningDriverContract` dataset, and every row of §5 has a fixture test.
30. **SHOULD** Given an auth failure on a server, then queued commands for that server are held until a test connection
    succeeds, and the dashboard shows the held count.
31. **SHOULD** Given a token expiring within 14 days, then the server list and dashboard warn. Given "Fetch from WHM",
    then the name servers from `get_nameserver_config` are offered for confirmation, never saved silently.
32. **SHOULD** Given cPanel shortcuts are enabled, then the client service page offers Email, File Manager, phpMyAdmin
    and Backups links, each through the SSO flow with `app` set.
33. **SHOULD** Given `provisioning:cpanel-smoke --lifecycle` on the owner's server, then every step reports pass or fail
    with timings, the smoke account is removed, and no other account is touched.
