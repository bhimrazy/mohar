# Research note: Nepal SMS gateways and hosting panels after cPanel

Status: research only, no contract changes made · Read: 2026-10-29 · Clean-room: public sources only, paraphrased.

Caveat on method: pages were read through a summarising fetcher, and several vendor pages were unreadable or 404.
Field names, endpoint names and prices below are leads. Re-check each against the live vendor docs (or a trial
account) before writing a driver. "Not found" means not found in public material on the read date, not "does not exist".

## Part 1. SMS providers for transactional notices

### 1.1 Findings per provider

|                 | Aakash SMS                                                                                          | Sparrow SMS                                                                                                                                                                  | Nepal Telecom (NTC)                                                                                       | Ncell                                                     |
| --------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| Public HTTP API | Yes                                                                                                 | Yes                                                                                                                                                                          | Not found (web portal only)                                                                               | Not found                                                 |
| Auth            | `auth_token` parameter                                                                              | `token` parameter plus server IP allowlist                                                                                                                                   | Portal login issued after contract                                                                        | Not found                                                 |
| Send            | `GET/POST /sms/v3/send` with `auth_token`, `to` (comma list), `text`                                | `GET/POST /v2/sms/` with `token`, `from`, `to`, `text`                                                                                                                       | Portal upload, up to 1,000 numbers per batch                                                              | Not found                                                 |
| Sender ID       | No sender parameter documented                                                                      | `from` required, issued at onboarding                                                                                                                                        | NTC assigns an alphanumeric code                                                                          | Third parties say IDs must be registered on Ncell         |
| Response        | `error` flag, `data.valid[]` and `data.invalid[]` per recipient (id, status such as queued, credit) | `{count, response_code, response}`; HTTP 403 plus numeric codes 1000-1013 on failure                                                                                         | n/a                                                                                                       | n/a                                                       |
| Delivery report | Poll only: paginated report endpoint (`/sms/v1/report/api`); no webhook found                       | No DLR for plain SMS found (Viber API has one)                                                                                                                               | Not found                                                                                                 | Not found                                                 |
| Balance         | `POST /sms/v1/credit` (credits)                                                                     | `GET /credit/` (credits)                                                                                                                                                     | n/a                                                                                                       | n/a                                                       |
| Unicode, length | Not found                                                                                           | Not found                                                                                                                                                                    | Not found                                                                                                 | Not found                                                 |
| Sandbox         | Not found                                                                                           | Not found; demo account on request                                                                                                                                           | Not found                                                                                                 | Not found                                                 |
| Pricing         | Not published, quote on request                                                                     | Tiered plans, rates not published                                                                                                                                            | Published bulk card: NPR 0.50 (2k-100k, 3 months) down to 0.20 (>1M, 1 year); page is inconsistent on tax | Not found first-party; third parties quote about NPR 0.55 |
| Account         | Self-registration; KYC not mentioned                                                                | Trial signup; KYC before activation                                                                                                                                          | Letter to vas@ntc.net.np with company and tax documents, contract, advance bank payment                   | Not found                                                 |
| SDKs            | No official. Laravel community package stale (about 6 years)                                        | No official. Community Laravel packages: `openbackend/laravel-nepali-sms` (v1.1.1, July 2025, 0 stars, covers several Nepali gateways), plus abandoned notification channels | n/a                                                                                                       | n/a                                                       |

Sources (all read 2026-10-29): https://aakashsms.com/documentation/ ; https://aakashsms.com/sms-gateway-api-service-in-nepal/ ;
https://docs.sparrowsms.com/sms/outgoing_sendsms/ ; https://docs.sparrowsms.com/sms/outgoing_credits/ ;
https://sparrowsms.com/services/sms-gateway-api/ ; https://ntc.net.np/services/sms-based-vas-services ;
https://github.com/openbackend/laravel-nepali-sms ; https://laraplugins.io/plugins/zunky44/aakashsms ;
https://laraplugins.io/plugins/aankhijhyaal/laravel-sparrow.

Not readable: the Aakash v4 docs at https://bitbucket.org/aakashsms/api/src/v4 (the only page that may cover sender, DLR and
encoding in more detail). Samaye, Hamro SMS, Mero SMS, Sakshi, NepalSMS, Sociair: no docs found.

International fallbacks: Twilio lists Nepal with pre-registered alphanumeric sender IDs (about 2 weeks), no two-way, UCS-2 supported
(https://www.twilio.com/en-us/guidelines/np/sms). Plivo and Infobip also cover Nepal with registration of 1-2 weeks
(https://www.plivo.com/sms/coverage/np/ ; https://www.infobip.com/docs/essentials/getting-started/sms-coverage-and-connectivity.md).
Price is far higher than local (Plivo page quotes about USD 0.25), so only useful for foreign recipients.

### 1.2 Regulation and operations (secondary sources, verify)

- The regulator's own text (NTA) was not found. Vendor guides report a July 2025 NTA direction that operators must not terminate
  unauthorised A2P SMS, and that Nepal has no national DND registry, so Mohar should keep its own suppression list
  (https://sent.dm/en/resources/sms-compliance/np-sms-guidance, vendor-reported).
- Vendors disagree on whether sender-ID registration applies to NTC as well as Ncell (sent.dm: both; D7: Ncell only). Ask the gateway.
- Jan 2026: protest messages went out under gateway-held sender IDs, and one gateway account was blocked after millions of sends
  (https://kathmandupost.com/national/2026/01/14/bulk-sms-calling-for-royalist-protests-sparks-privacy-concerns). Expect gateways to
  tighten content and sender checks; a billing platform sending only templated transactional text is the low-risk profile.
- Encoding: vendor guides give 160 characters per GSM-7 segment and 70 per UCS-2 segment (153 and 67 when concatenated); Nepali
  needs UCS-2. No Nepali gateway documents segment counting or how credits map to segments. This must be tested on a trial account.
- Throughput and quiet-hours numbers (about 10-20 msg/s per sender, avoid 20:00-08:00) are vendor guidance, not a rule.
  Mobile numbers: 10 digits starting 97 or 98 (`^9[78]\d{8}$`) per the same guide; landlines are not reachable.

### 1.3 Recommendation

Build Sparrow SMS first, Aakash second, behind one driver contract. Reasons:

1. Both are real HTTP APIs reachable by a self-serve account; NTC has no API, so NTC and Ncell are reached through an aggregator.
2. Sparrow documents numeric error codes and a sender field, which maps cleanly to the contract. It also states connectivity to NTC and
   Ncell (vendor claim) and requires KYC, which a Nepali host can complete. Its docs are old (2020) and its example URL is http, so
   the driver must default to https and make the base URL configurable.
3. Aakash is the easiest to start on (self-registration) and has per-recipient results, a report poll and a balance call, so it
   exercises the contract's partial-success and poll paths. Its sender handling and Unicode behaviour are unknown.
4. Neither offers a sandbox, so drivers ship with recorded fixtures plus a manual smoke run by the owner on a funded trial account.
5. A generic "custom HTTP gateway" driver (URL template, token, field mapping) is worth adding later so a host can use any local
   gateway or NTC's future API without a code change.

Do not depend on the community PHP packages (stale or unmaintained); a thin native driver per gateway is a small amount of code.

### 1.4 What the notification channel driver contract must cover

Beside `SystemMailer` (parity review §6). Kernel change, so CTO and ADR 0008/0010 apply.

- Message: recipient in E.164 (store `+97798...`, send in whatever form the gateway wants), body, optional sender ID, purpose
  (`transactional` or `otp`), a Mohar-generated message key. Result per recipient: accepted, rejected with an internal reason, or unknown.
- Capabilities flags: `supportsSenderId`, `supportsDlrPoll`, `supportsDlrWebhook`, `supportsBalance`, `supportsUnicode`, max recipients per call.
- Idempotency: no gateway documents an idempotency key. Record the attempt before the call, never blindly retry a timeout
  (a retry can double-send and double-bill), and mark the outcome `unknown` for the review queue, as ADR 0026 does for payments.
- Segments: Mohar computes encoding and segment count itself to show cost and to refuse over-long OTPs; the gateway's credit figure is
  authoritative for billing. Record both.
- Delivery: "accepted by API" is not "delivered". The contract exposes a status lookup and a poll job; webhook ingestion is optional by capability.
- Balance: `balance()` returns an amount and a unit (credits, not NPR), feeding a low-balance staff alert.
- Error map: auth, ip_blocked, account_inactive_or_expired, invalid_recipient, invalid_sender, empty_text, insufficient_credit, throttled, unknown.
  Only `throttled` and `unknown` are retryable.
- Throttle: configurable per-driver rate and queue, since no limits are published.
- Policy outside the driver: per-client channel preference and consent, a suppression list with STOP/BAND handling, quiet hours for
  non-OTP messages, template approval, Nepali templates, redaction of OTPs and tokens in the log (as email does), and per-message cost in the log.
- Config: token (encrypted), base URL, sender ID, egress-IP hint (Sparrow allowlist), SSRF guard on base URL, test-send action, redactor for the token
  because both gateways put it in the query string or body (URLs must not be logged).

### 1.5 Owner to confirm

- Which gateway does the owner already hold an account with, and is a funded trial available for fixture capture and a smoke test?
- Assumed workflow: a Nepali host buys prepaid credits from one aggregator (Sparrow or Aakash), registers an alphanumeric sender ID during
  onboarding, and sends transactional SMS to NTC and Ncell numbers through it, with no per-operator registration by the host.
- Assumed: Nepali (Devanagari) messages are sent as UCS-2 at 70 characters per segment, and credits are charged per segment.
- Assumed: no national DND registry applies to transactional billing notices, so consent is a checkbox on the client account.

## Part 2. Hosting control panels after cPanel/WHM

### 2.1 Findings

| Panel       | Licence, entry price                                                         | Auth                                                                            | API for create, suspend, unsuspend, terminate, package, usage                                                                                                                   | WHMCS (docs list / other)                                                  | Nepal evidence                        |
| ----------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------- |
| DirectAdmin | Proprietary, USD 5 (2 accounts), 15 (10), 29 (unlimited) per month           | HTTP Basic; scoped login keys; admin/reseller impersonation                     | Legacy `CMD_API_*` commands (account create, `SELECT_USERS` suspend/delete, `MODIFY_USER`, `SHOW_USER_USAGE`) with `json=yes`; newer API with OpenAPI at `/static/swagger.json` | Listed in WHMCS server-module docs                                         | Himalayan Host and Nest Nepal list it |
| Plesk       | Proprietary, about EUR 12-31 per month (VPS editions, partner plan separate) | Basic or `X-API-Key` header                                                     | REST `/api/v2` and XML-RPC; `POST /clients` confirmed; suspend, plan change, usage not confirmed                                                                                | Listed in WHMCS docs; WebPros has its own WHMCS offering                   | None found                            |
| CyberPanel  | GPL-3.0, free (LiteSpeed Enterprise optional)                                | Admin user and password in each request, port 8090, API access enabled per user | `createWebsite`, `deleteWebsite`, `issueSSL` per third parties; others unconfirmed                                                                                              | Not listed; community module stale since 2021; Blesta has a maintained one | None found                            |
| HestiaCP    | GPL-3.0, free                                                                | `ACCESS_KEY:SECRET_KEY` hash, caller IP allowlisted                             | CLI passthrough: `v-add-user`, `v-change-user-package`, `v-delete-user` confirmed; suspend/unsuspend/list by memory only                                                        | Not listed; forum addons                                                   | None found                            |
| aaPanel     | Free core, paid Pro                                                          | Not confirmed                                                                   | Documented provisioning API not found                                                                                                                                           | Not found                                                                  | None found                            |
| CloudPanel  | Free                                                                         | None                                                                            | CLI `clpctl` only, so SSH                                                                                                                                                       | Vendor blog mentions WHMCS, not read                                       | None found                            |
| ISPConfig   | Open source                                                                  | Remote user                                                                     | SOAP/JSON remote API (`client_add`, site calls fragile); suspend and package unconfirmed                                                                                        | Old 2014 community module only                                             | None found                            |
| Webuzo      | Proprietary, USD 2.5-20 per month                                            | API key                                                                         | `act=` calls on ports 2002-2005, PHP SDK; suspend confirmed, create unconfirmed                                                                                                 | Not in WHMCS docs; vendor ships WHMCS shared and reseller modules          | Himalayan Host lists it               |
| Virtualizor | Proprietary, USD 9 or 18 per node per month                                  | API key and pass                                                                | Admin API: add VPS, suspend, unsuspend, delete, manage, stats (exact act names unconfirmed)                                                                                     | Listed in WHMCS docs                                                       | None found                            |
| Proxmox VE  | AGPL; support subscription from EUR 120 per socket per year                  | Ticket plus CSRF, or API token                                                  | REST `/api2/json`; async tasks (UPID) to poll; LXC create confirmed                                                                                                             | No official module; third-party commercial                                 | None found                            |
| SolusVM 2   | Proprietary, price not found                                                 | Bearer token                                                                    | REST with OpenAPI; create, suspend, resume, terminate per integrators                                                                                                           | WHMCS docs list SolusVM (v1); vendor ships a v2 module                     | None found                            |
| VirtFusion  | Proprietary, per hypervisor, current price not found                         | Bearer token                                                                    | `createServer`, `suspendServer`, `unsuspendServer`, `deleteServer`, `changeServerPackage`, `getServerTraffic`; real HTTP codes (409 wrong state, 423 locked)                    | Vendor ships WHMCS and other billing modules                               | None found                            |

Sources (read 2026-10-29): https://www.directadmin.com/pricing.php ; https://docs.directadmin.com/developer/api/ ;
https://docs.directadmin.com/developer/api/legacy-api.html ; https://www.plesk.com/pricing/ ; https://docs.plesk.com/en-US/obsidian/api-rpc/ ;
https://github.com/usmannasir/cyberpanel ; https://github.com/blesta/module-cyberpanel ; https://hestiacp.com/docs/server-administration/rest-api.html ;
https://hestiacp.com/docs/reference/cli.html ; https://github.com/hestiacp/hestiacp ; https://github.com/aapanel/aaPanel ; https://www.cloudpanel.io/docs/v2/ ;
https://www.webuzo.com/pricing ; https://webuzo.com/docs/api/suspend-user ; https://webuzo.com/docs/billing/whmcs-shared-hosting-module ;
https://www.virtualizor.com/pricing ; https://www.virtualizor.com/docs/developers/admin-api ;
https://www.proxmox.com/en/products/proxmox-virtual-environment/pricing ; https://pve.proxmox.com/wiki/Proxmox_VE_API ;
https://docs.solusvm.com/en/solusvm2/api-reference/api/ ; https://docs.virtfusion.com/api ; https://docs.virtfusion.com/category/integrations ;
WHMCS server-module list https://docs.whmcs.com/8-13/servers/server-modules/ ; Nepal host stacks https://www.websnp.com/help/hosting-nepal-2026-guide.

Popularity: no reliable market-share survey was found for any panel. GitHub stars (CyberPanel about 2,000, HestiaCP about 4,500, aaPanel
about 3,100, CloudPanel about 1,900) only measure the open-source side. Nepal evidence is two or three host websites naming their stack;
treat it as anecdotal.

Security note: CyberPanel had actively exploited critical RCE and auth-bypass CVEs in 2.3.5-2.3.7 (CVE-2024-51567, -51568, -51378;
https://www.sonicwall.com/blog/critical-cyberpanel-vulnerability-cve-2024-51378-how-to-stay-protected). A driver would hold an admin
password for that panel, so it needs a minimum-version check and a warning.

### 2.2 Recommendation (order)

1. **DirectAdmin** next. It is the only non-cPanel shared panel with Nepali hosts on record, a paid mainstream product so customers pay for support,
   scoped login keys, a published OpenAPI spec for fixtures, and a WHMCS-listed module showing the lifecycle is stable.
2. **Webuzo** (or Plesk) third. Webuzo has one Nepali host on record and a simple key-based API, but half the lifecycle is unconfirmed. Plesk has the
   best REST API but no Nepali evidence; build it only when a customer asks. Order between them should follow what the owner's hosts run.
3. **HestiaCP / CyberPanel** only on demand: free panels used by small hosts, Hestia's API is a CLI passthrough needing IP allowlisting and
   driver-built idempotency, CyberPanel stores an admin password and has the CVE history. Skip aaPanel and CloudPanel (no documented API, or SSH-only)
   and ISPConfig (fragile SOAP, no Nepal evidence).
4. **One VPS panel: VirtFusion**, with Virtualizor as the pragmatic alternative. VirtFusion has the cleanest API (scoped bearer tokens, explicit
   409/423 state conflicts that map to rejected/retryable) and its vendor publishes billing integrations. Virtualizor is the more common
   panel for budget VPS hosts and is WHMCS-listed, but its act names need verification and it is licensed per node. Proxmox has no
   tenant model, so a driver must own users and quotas; worth it only for hosts running bare Proxmox. SolusVM 2 is a later option.
   No Nepali VPS-panel evidence was found for any of them, so this choice is by API quality, not local demand.

### 2.3 What the driver contract must cover beyond cPanel

(Observations from the cPanel spec's shape: stateless driver, context in, result out, outcome vocabulary, reconcile, username policy, SSO.)

- A fourth outcome, "accepted, in progress" with a poll handle, for Proxmox and SolusVM tasks. The current vocabulary (completed, retryable, rejected, unknown) lacks it.
- A reconcile-first default: a lookup before create and after unknown outcomes, since hosting panels return free text or numeric codes rather than state conflicts.
- Connection data beyond API token: username plus secret, port, optional `id:secret` pair (Hestia, Proxmox), IP allowlist hint, TLS pinning option.
- VPS panels need remote option lists (plans, templates, locations, hypervisor groups), not one package name.
- Redaction of `hash`, admin passwords, `X-API-Key`, bearer and `PVEAPIToken` values; SSRF guard on server addresses; minimum-panel-version check on test-connection.
- Fixtures: only DirectAdmin, Proxmox and SolusVM publish machine-readable specs; others need hand-written fixtures and an owner smoke run, as with cPanel.

### 2.4 Owner to confirm

- Which panels do the owner's Nepali hosting customers actually run besides cPanel (DirectAdmin, Webuzo, Plesk, other)? This overrides the order above.
- Assumed workflow: a Nepali host runs shared hosting on cPanel or DirectAdmin on a VPS or dedicated server abroad or in a Kathmandu data centre, resells VPS through a panel on its own hardware, and gives clients panel SSO from the client area.
- Which VPS panel do Nepali hosts use, if any (VirtFusion, Virtualizor, Proxmox, none)?

### 2.5 Open items (not resolved by this note)

DirectAdmin swagger paths; Plesk suspend, plan change and usage calls; Virtualizor act names; SolusVM 2 paths; Webuzo create-user act; whether aaPanel has an API;
CyberPanel's official API page; Aakash v4 docs (sender, encoding, DLR); gateway Unicode and segment rules; any first-party Ncell A2P programme.

## Contract changes implied

- New notification channel contract (SMS first) in `app/Contracts`, as in Part 1.4.
- Provisioning driver contract: an "in progress" outcome with a poll handle and a connection DTO that is not token-only. Needed only when the first VPS driver
  or a non-token hosting panel is built; DirectAdmin alone needs neither.
