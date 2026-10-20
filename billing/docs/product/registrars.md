# Registrar Drivers — Research & Driver Specs (v1)

Status: research for engineering · Owner: Domains workstream · Refines domains.md §5 and the
`App\Modules\Domains\Drivers\Registrars\Registrar` contract. Researched 2026-10-17 from official docs only
(see §9). This is a clean-room summary in our own words. Field names are quoted only where a driver has to
send or parse them. Anything marked **verify** was not stated clearly in the docs and must be checked in the
sandbox before we rely on it.

## 0. Ground rules for every driver

- **Outcome classes.** Every driver maps every response to exactly one of these:
  `completed` (RegistrarResult completed) · `pending` (RegistrarResult pending, i.e. accepted but async) ·
  `rejected` (`RegistrarException`, not retryable, nothing executed) · `retryable` (`RegistrarException(retryable: true)`,
  only when the request **provably never ran**) · `unknown` (`RegistrarTimeout` → `pending_registration`/`pending_transfer`
    - `needs_review`). If a mutating command could have run, it is `unknown`, never `retryable`.
- **Transport.** `Http::connectTimeout(10)->timeout(90)`. Namecheap's own example shows `domains.create` taking
  about 30 s. Only DNS failures and refused connections (cURL errno 6/7) are `retryable` for register/transfer/renew/restore.
  A cURL 28 timeout, a reset, a 5xx without a well-formed error body, or an unparsable body is `unknown`. Read calls may retry.
- **No idempotency keys.** None of the four registrars accepts a client idempotency key. Our protection is the
  row-lock claim (`registrar_meta.submission` / `renew_sent_for`) plus an authoritative lookup **in our own account**
  after an unknown outcome. A "domain not available" reply after an unknown outcome is ambiguous: we may be the owner.
  It must never count as a failure without an ownership check.
- **Remote ids.** RC needs `order-id` for every post-registration call. Namecheap needs `TransferID`. OpenSRS needs
  `order_id`, `queue_request_id` and the profile credentials. The ids are returned in the result and persisted to
  `registrar_meta.remote` (see contract change C1).

## 1. Comparison

|                 | ResellerClub / LogicBoxes                                                                                                                                             | Namecheap                                                                                                                                          | OpenSRS (Tucows)                                                                                                          | .np (Mercantile)                                                                            |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| API style       | HTTP query API, JSON (default) or XML by URL suffix; GET for reads, POST for writes                                                                                   | Single endpoint `xml.response`, `Command=` param, XML only, HTTP 200 even on error                                                                 | XML "OPS envelope" (XCP protocol) POSTed to port 55443                                                                    | None: web portal + email (hostmaster@)                                                      |
| Auth            | `auth-userid` (reseller id) + `api-key` on every call                                                                                                                 | `ApiUser`, `ApiKey`, `UserName`, `ClientIp` on every call                                                                                          | `X-Username` header + `X-Signature` = md5(md5(xml+key)+key)                                                               | Owner account on register.com.np                                                            |
| IP allow-list   | Required; up to 3 single IPs in the panel (no ranges); 30–60 min to apply                                                                                             | Required, IPv4 only; the `ClientIp` param must be the whitelisted IP                                                                               | Required for live, not for test                                                                                           | n/a                                                                                         |
| Sandbox         | Demo reseller account (cp.onlyfordemo.net), **wiped every 24 h**; `test.httpapi.com` allows GET for anything but **acts on whichever account's credentials you send** | `api.sandbox.namecheap.com`, separate account; nameservers must exist inside the sandbox; sandbox domains cannot be deleted                        | `horizon.opensrs.net:55443` with test credentials                                                                         | none                                                                                        |
| TLD coverage    | Very broad gTLD + ccTLD; strong `.in` + third-level `.in` (India)                                                                                                     | Everything on its price list; **API transfers only for a fixed list** (.com/.net/.org/.info/.biz/.us/.in/.co/.me/.tv/.cc/.ca/.mobi/es/pe variants) | Broad gTLD + many ccTLDs (TLD-specific `tld_data`)                                                                        | .com.np, .org.np, .edu.np, .net.np, .name.np, .info.np, .coop.np, .mil.np; .gov.np via NITC |
| Duplicate order | Second register of a taken name errors; no key                                                                                                                        | `3019166`/`4019166` "Domain not available"                                                                                                         | `485` taken, `486` processing (retry shortly), `437` another request waiting, `221` taken by a waiting async registration | Staff sees the name in the portal                                                           |
| Rate limits     | Undocumented "abuse" threshold → **24 h IP block**; extra simultaneous connections dropped                                                                            | **50/min, 700/h, 8000/day per key**                                                                                                                | `300` command rate exceeded, `310` too many connections, `350` max 100 commands per connection                            | Human SLA                                                                                   |
| Async ops       | Actions return `actionstatus` (may not be complete); transfers async                                                                                                  | `NonRealTimeDomain=true` on create; transfers async (`StatusID`)                                                                                   | `250` async registry, `queue_request_id` (queued), `handle=save` (pended), `async_reason`                                 | All of it (24 h to 3–4 business days; NS changes processed twice a day)                     |
| Pricing sync    | `products/customer-price.json` / reseller-price (cache it, very large)                                                                                                | `users.getPricing` (USD, by action/TLD/years)                                                                                                      | `get_price` per domain + period                                                                                           | Free at the registry (we charge a handling fee only)                                        |
| Webhooks        | none found                                                                                                                                                            | none                                                                                                                                               | Yes: signed POST webhooks (domain, order, transfer events)                                                                | none                                                                                        |

## 2. Contract changes needed (exact signatures)

- **C1 — persist remote ids.** Add to `RegistrarResult` a trailing constructor param
  `public array $remote = []` (`array<string, string|int>`, e.g. `['order_id' => 123, 'transfer_id' => 9]`).
  `SubmitToRegistrar::succeeded()`, `PerformRenewal::applied()` and `SyncDomain` merge it into
  `registrar_meta['remote']`. `DomainInfo` gains the same `public array $remote = []`, so a reconcile can backfill the ids.
- **C2 — authoritative reconcile.** New capability `'reconcile'` in `Registrar::CAPABILITIES` and a new method
  `public function findSubmission(Domain $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup;`
  `SubmissionLookup` is `final readonly` with `string $status` (`executed|in_progress|absent|unknown`),
  _built with the kernel `App\Enums\ReconcileStatus` instead (absent → `NotExecuted`; a name registered outside our
  account → `Conflict`, staff review), per the 2026-10-20 architecture review F2_,
  `?CarbonImmutable $expiryDate = null`, `array $remote = []`, `?string $message = null`.
  `SubmitToRegistrar::reconcile()` and `PerformRenewal::reconcile()` call it in place of `info()` when it is supported.
  `executed` → apply. `in_progress` → the accepted-asynchronously path (`pending_registration` /
  `pending_transfer`, resolved by sync; nothing is re-sent); a renewal in progress keeps its claim and goes to staff
  review. `absent` → keep `needs_review`, and staff may "re-submit" (Change status → Pending, then register; we never
  re-submit automatically). `unknown` → `needs_review`. _Built 2026-10-20._
- **C3 — issue #12 (stale claims).** In `SyncDomains`, sweep `status = pending` with
  `registrar_meta.submission` older than `SubmitToRegistrar::IN_FLIGHT_MINUTES` → set `needs_review = true`, log a
  staff task, then run C2. **Ship this before any real driver.** RC/OpenSRS orders can sit "pending" at the
  registrar, and that is exactly when a killed worker leaves a paid order invisible. _Built 2026-10-20; the sweep also
  runs when registrar sync is off, and each swept domain is listed in the sync report._
- **C4 — registrar refs store** (not on the interface). New table
  `registrar_refs(registrar_key, ref_type customer|contact|profile, local_type, local_id, remote_id, payload_hash, secret encrypted null)`
  behind a `RegistrarRefs` service that drivers receive by injection. RC customers/contacts and OpenSRS profile
  credentials (`reg_username`/`reg_password`) live here.
- **C5 — redaction gaps.** `RegistrarGateway` SECRET lists miss `api-key` (hyphen, RC), `passwd` (RC customer signup)
  and `signature` (OpenSRS). Add them. Drivers must never copy raw request params into `meta` or exception messages.
  _2026-10-20: the kernel `Redactor` already matches these keys by word (and `domsecret`, `auth-code`); the contract
  test found that a pair quoted behind a label (`Rejected: api-key=…`) was kept whole, which is fixed._
- Deferred (premium is off): `RegistrationRequest` needs `?int $premiumRegisterCost` and `?string $costCurrency`, because
  Namecheap (`PremiumPrice`) and OpenSRS (`premium_price_to_verify`) reject mismatched prices. Also,
  `AvailabilityResult` premium costs currently have no currency.

## 3. ResellerClub / LogicBoxes (`resellerclub`)

Base `https://httpapi.com/api/` (availability: `https://domaincheck.httpapi.com/api/`). Array params are sent as
**repeated keys without brackets** (`ns=a&ns=b`), and maps as numbered pairs (`attr-name1`/`attr-value1`). Laravel's
`asForm()`/`http_build_query` emit `ns[0]=`, so build the body with a custom encoder. Errors come back as HTTP 500 with
`{"status":"ERROR","message":"…"}`. There are **no numeric codes**, so classification is done on the message text.

**Capability mapping**

| Contract method               | Call                                                                                                                                                                                                             | Notes                                                                                                                                                                                                                                                                               |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `checkAvailability`           | GET `domains/available.json` `domain-name[]`, `tlds[]` (no dot)                                                                                                                                                  | Per-domain `status`: `available`; `regthroughus` → registered (ours); `regthroughothers` → registered; `unknown` → unknown (registry down). A `costHash` means premium. Donuts groups: max 5 names per call; `.ca`: max 15 (more → all unknown)                                     |
| `suggest`                     | GET `domains/v5/suggest-names.json` `keyword`, `tld-only`, `exact-match`                                                                                                                                         | Not supported for most ccTLDs                                                                                                                                                                                                                                                       |
| `register`                    | POST `domains/register.json` `domain-name`, `years`, `ns[]`, `customer-id`, `reg/admin/tech/billing-contact-id`, `invoice-option=NoInvoice`, `auto-renew=false`, `purchase-privacy`, `protect-privacy`, `attr-*` | Response: `entityid` (order id → `remote.order_id`), `actionstatus`, `actionstatusdesc`, `eaqid`. Some TLDs need `-1` for roles they lack (.uk/.eu/.ru admin/tech; .ca/.nl/.nz billing…). `actionstatus` other than success → `pending` (**verify** exact values in demo)           |
| `transfer`                    | POST `domains/transfer.json` (same contact params, `auth-code`, optional `ns[]`)                                                                                                                                 | The auth code is mandatory up front for .au/.ca/.de/.eu/.nz. A code submitted later uses `domains/transfer/submit-auth-code` (`order-id`, `auth-code`). Pre-check: GET `domains/validate-transfer.json` → `true`/`false`                                                            |
| `renew`                       | POST `domains/renew.json` `order-id`, `years`, `exp-date` (**current expiry as epoch**), `invoice-option`, `auto-renew`                                                                                          | Read `endtime` via details first. A stale `exp-date` is rejected, which also stops double renewals                                                                                                                                                                                  |
| `renew(restore: true)`        | POST `domains/restore.json` `order-id`, `invoice-option`                                                                                                                                                         | Restore only. Follow with a renew if the TLD needs one (**verify** per TLD)                                                                                                                                                                                                         |
| `info` / `sync`               | GET `domains/details-by-name.json` `domain-name`, `options=OrderDetails&options=NsDetails&options=DomainStatus`                                                                                                  | `currentstatus` (InActive, Active, Suspended, Pending Delete Restorable, Deleted, Archived), `endtime`/`creationtime` (epoch), `ns1..nsN`, `orderstatus[]` (contains `transferlock`), `domainstatus[]` (`sixtydaylock`, `renewhold`), `isprivacyprotected`, `raaVerificationStatus` |
| `setNameservers`              | POST `domains/modify-ns.json` `order-id`, `ns[]`                                                                                                                                                                 | Accounts with 2FA may need `otp` + `2fa-type`                                                                                                                                                                                                                                       |
| `setLock`                     | POST `domains/enable-theft-protection.json` / `disable-theft-protection.json` `order-id`                                                                                                                         | Read back via `domains/locks.json` (`transferlock`)                                                                                                                                                                                                                                 |
| `setIdProtection`             | POST `domains/modify-privacy-protection.json` `order-id`, `protect-privacy`, `reason`                                                                                                                            | Not offered for **.in, .us**, .ca, .de, .eu, .uk, .au and others. Hide the add-on for those TLDs                                                                                                                                                                                    |
| `getContacts` / `setContacts` | details `options=ContactIds` + `contacts/details` → create contacts, then POST `domains/modify-contact.json` with the four ids                                                                                   | Registrant changes on gTLDs trigger the 60-day lock (`sixty-day-lock-optout` exists; reseller only)                                                                                                                                                                                 |
| `getEppCode`                  | details `options=OrderDetails` → `domsecret`                                                                                                                                                                     | Returns `EppCodeResult(code)`. `domains/modify-auth-code.json` can reset it                                                                                                                                                                                                         |
| `transferSync`                | details-by-name: order `Active` with `endtime` → completed                                                                                                                                                       | Failure states are not clearly documented → after `transfer_stale_days`, `failed` with the reason "verify at ResellerClub" (**verify**)                                                                                                                                             |
| `findSubmission`              | GET `domains/orderid.json?domain-name=` then details; for proof of absence, `domains/search.json` (`domain-name`, `customer-id`, `creation-date-start`)                                                          | Order in our reseller tree: `Active` → executed; `InActive` → in_progress. Search empty since `submittedAt` and availability `available` → absent                                                                                                                                   |
| `testConnection`              | GET `domains/available.json` for one name                                                                                                                                                                        | "Access Denied…" means the IP is not whitelisted yet                                                                                                                                                                                                                                |

**Contact model.** RC uses customer → contacts → order. A _customer_ (`customers/v2/signup.json`: `username`=email,
`passwd`, `name`, `company`, address, `phone-cc`/`phone`, `lang-pref`; returns the id) owns _contacts_
(`contacts/add.json`: `name`, `company`, `email`, `address-line-1`, `city`, `country`, `zipcode`, `phone-cc`,
`phone`, `customer-id`, `type` = `Contact` or a TLD type such as `CaContact`, `EuContact`, `UkContact`), and an order
references four contact ids. Mapping: one RC customer per Mohar client (`registrar_refs` customer). Alternatively use
the reseller's _default customer_ for every domain (simpler; clients never get an RC login). That choice is the
`customer_mode` setting. Our `first_name + last_name` → `name`. `+1.5555550100` → `phone-cc=1`, `phone=5555550100`.
An empty `company` → "N A" (RC rejects single-word placeholders for some contact types). `state` is required on customers
("Not Applicable"). The zip is validated against the country. Contacts are deduplicated by `payload_hash`. **Never edit a
shared contact in place** (`contacts/modify` changes every domain using it). Create a new contact and re-associate with
`modify-contact`.

**Error → outcome.** Validation messages (invalid contact, domain not available, missing auth code) → rejected.
"Access Denied" → rejected + admin alert. Insufficient funds → rejected. HTML/empty 5xx, or messages about
internal/timeout/"try again" on mutating calls → **unknown**. A 200 whose `actionstatus` is not success → pending,
resolved by sync. Use `invoice-option=NoInvoice`: Mohar bills the client, and `PayInvoice`/`OnlyAdd` can leave orders
**silently pending** on low balance.

**Settings.** `reseller_id`, `api_key` (**secret**), `demo_reseller_id`, `demo_api_key` (**secret**), `customer_mode`
(select: per_client|default_customer), `default_customer_id`, `http_proxy` (**secret**, may hold credentials).
`test_mode` selects the demo credentials **and** the demo account. Never send live keys to `test.httpapi.com`.

## 4. Namecheap (`namecheap`)

Endpoint `https://api.namecheap.com/xml.response` (sandbox `https://api.sandbox.namecheap.com/xml.response`). Use POST
(recommended for create). The response root is `<ApiResponse Status="OK|ERROR">` (namespaced) with
`<Errors><Error Number="…">`. Dates are `MM/DD/YYYY`. IDNs must be sent as punycode with `IdnCode`. Production API access
requires ≥20 domains, ≥$50 balance or ≥$50 spent in 2 years.

| Contract method               | Command                                                                                                                                                                                                                                                                                                                                    | Notes                                                                                                                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `checkAvailability`           | `namecheap.domains.check` `DomainList` (comma, **max 50**)                                                                                                                                                                                                                                                                                 | `DomainCheckResult@Available`, `IsPremiumName`, `PremiumRegistrationPrice`/`PremiumRenewalPrice` (USD decimals → minor units), `ErrorNo≠0` → unknown                           |
| `suggest`                     | —                                                                                                                                                                                                                                                                                                                                          | Not offered → omit the capability (we generate suggestions locally)                                                                                                            |
| `register`                    | `namecheap.domains.create` `DomainName`, `Years`, `Registrant*`/`Tech*`/`Admin*`/`AuxBilling*` (FirstName, LastName, Address1, City, StateProvince, PostalCode, Country, Phone `+NNN.NNNN`, EmailAddress, OrganizationName), `Nameservers` (comma), `AddFreeWhoisguard`+`WGEnabled`, extended attributes (.us/.ca/.eu/.uk/.au/.es/.de/.fr) | `DomainCreateResult@Registered=true` → completed (expiry not returned: compute it, then sync). `NonRealTimeDomain=true` → pending. Store `DomainID`, `OrderID`                 |
| `transfer`                    | `namecheap.domains.transfer.create` `DomainName`, `Years=1`, `EPPCode` (send `base64:<b64>` if it has special characters)                                                                                                                                                                                                                  | `TransferID` → remote. `StatusID`. Only the TLD list in §1                                                                                                                     |
| `renew`                       | `namecheap.domains.renew` `DomainName`, `Years`                                                                                                                                                                                                                                                                                            | `DomainRenewResult@Renew`, `DomainDetails/ExpiredDate`. `2020166` (expired) → use reactivate                                                                                   |
| `renew(restore: true)`        | `namecheap.domains.reactivate` `DomainName`, `YearsToAdd`                                                                                                                                                                                                                                                                                  | `IsSuccess`                                                                                                                                                                    |
| `info` / `sync`               | `domains.getInfo` (+ `domains.dns.getList` for NS, `domains.getRegistrarLock`)                                                                                                                                                                                                                                                             | `Status` OK/Locked/Expired, `DomainDetails/ExpiredDate`, `Whoisguard@Enabled` + `ID`, `IsOwner`. 3 calls per info: budget them                                                 |
| `setNameservers`              | `namecheap.domains.dns.setCustom` `SLD`, `TLD`, `Nameservers`                                                                                                                                                                                                                                                                              | Split SLD/TLD with **our** `tld` (multi-label TLDs)                                                                                                                            |
| `setLock`                     | `domains.setRegistrarLock` `LockAction=LOCK                                                                                                                                                                                                                                                                                                | UNLOCK`                                                                                                                                                                        | `IsSuccess` |
| `setIdProtection`             | `namecheap.whoisguard.enable` (`WhoisguardID`, `ForwardedToEmail`) / `.disable`                                                                                                                                                                                                                                                            | The id comes from getInfo                                                                                                                                                      |
| `getContacts` / `setContacts` | `domains.getContacts` / `domains.setContacts`                                                                                                                                                                                                                                                                                              | setContacts needs **all four roles**. Our `billing` ↔ `AuxBilling`                                                                                                             |
| `getEppCode`                  | —                                                                                                                                                                                                                                                                                                                                          | **No API method.** Omit `epp`; the UI tells the client to ask support (staff gets the code from the panel)                                                                     |
| `transferSync`                | `domains.transfer.getStatus` `TransferID`                                                                                                                                                                                                                                                                                                  | `StatusID 5` → completed. Cancelled: 2, 4, 8, 15–27, 30–37, 45, 48–51, −4. Everything else (incl. −1, −2, −5, −22 waiting for EPP) → pending. Keep `Status` text as the reason |
| `findSubmission`              | register: `domains.getList` `SearchTerm=<name>` → present with `IsOwner` → executed (expiry from `Expires`). transfer: `domains.transfer.getList` `SearchTerm`                                                                                                                                                                             | Not listed and `domains.check` says available → absent                                                                                                                         |
| `testConnection`              | `domains.getTldList`                                                                                                                                                                                                                                                                                                                       | Global `1011150`/`1017150`/`1017105` → IP not whitelisted                                                                                                                      |

**Error → outcome.** Rejected: global auth `10101xx/10171xx/10161xx/1019103`, validation `2011xxx`, `2015xxx`,
`2010xxx`, `2030280`, `2016166` (not ours), `2019166` (not found), `3019166/4019166` (not available, but see §0),
`2033409` (no chargeable order/funds). **Unknown on mutating calls:** `3011511`, `3031900`, `3050900`, `5050900`
("unknown response/error from provider/Enom"), `3028166`/`3031510` (upstream error count, may have half-run), `4023166`,
`4024167`, `4022337`/`4026312` (refund errors mean money moved). `2528166` "order creation failed" → rejected (**verify**).

**Contacts/NS/lock/EPP.** There are no contact ids: contacts are sent inline per domain. A registrant change starts
the gTLD 60-day transfer lock (no opt-out param). Lock is a simple flag. No EPP retrieval.

**Settings.** `api_user`, `api_key` (**secret**), `username` (usually = api_user), `client_ip` (the whitelisted egress IPv4),
`sandbox_api_user`, `sandbox_api_key` (**secret**), `sandbox_username`, `http_proxy` (**secret**). Local rate budget:
40/min, 600/h, 7000/day via `RateLimiter`, with a reserve kept for writes. When the budget is exhausted the driver
throws `retryable` _before_ sending. Public lookups never spend the write reserve.

## 5. OpenSRS (`opensrs`) — chosen over Enom

Enom's developer hub (api.enom.com, ReadMe) currently publishes only a thin command index plus a test-account page
(set up via cp.enom.com reseller test account). Namecheap's own errors show it proxies Enom. OpenSRS's guide is
complete and current (updated 2025–26), with full response codes and webhooks. Enom is parked unless a customer
asks for it.

Live `https://rr-n1-tor.opensrs.net:55443`, test `https://horizon.opensrs.net:55443`. Body: XML envelope
(`OPS_envelope/body/data_block/dt_assoc`, items `protocol=XCP`, `action`, `object`, `attributes`). Headers:
`Content-Type: text/xml`, `X-Username`, `X-Signature`. Reply: `is_success`, `response_code`, `response_text`,
`attributes`. **Lookups return `is_success=1` even when the name is taken (`211`)**, so always branch on `response_code`.

| Contract method               | Action                                                                                                                                                                                                                                                                                           | Notes                                                                                                                                                                                                       |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `checkAvailability`           | `LOOKUP DOMAIN` `domain` (`no_cache=1` before checkout)                                                                                                                                                                                                                                          | `210` available, `211` taken, `221` taken/waiting. Premium via `get_price`                                                                                                                                  |
| `suggest`                     | `NAME_SUGGEST`                                                                                                                                                                                                                                                                                   | gTLD-centric                                                                                                                                                                                                |
| `register`                    | `SW_REGISTER DOMAIN` `reg_type=new`, `domain`, `period`, `contact_set{owner,admin,billing,tech}`, `custom_nameservers=1` + `nameserver_list`, `custom_tech_contact=1`, `reg_username`/`reg_password` (profile), `handle=process`, `f_lock_domain`, `f_whois_privacy`, `auto_renew=0`, `tld_data` | `200` + `id`, `registration_code` → completed. `250` / `async_reason` / `queue_request_id` → pending (store `queue_request_id`). `forced_pending` → order parked (e.g. funds `440`) → rejected + store `id` |
| `transfer`                    | `SW_REGISTER` `reg_type=transfer`, `auth_info`, `change_contact`, `owner_confirm_address` (.de/.be/.eu/.it)                                                                                                                                                                                      | `transfer_id`. Pre-check with `CHECK_TRANSFER` (`transferrable`, `reason`)                                                                                                                                  |
| `renew`                       | `RENEW DOMAIN` `domain`, `period`, `currentexpirationyear` (YYYY, must match the registry), `handle=process`, `auto_renew`                                                                                                                                                                       | `order_id`, `registration expiration date`. `541` year mismatch → rejected (resync). `555`/`465` "already renewed" → reconcile, since it is evidence of our own earlier success                             |
| `renew(restore: true)`        | `REDEEM DOMAIN`                                                                                                                                                                                                                                                                                  | Only .com/.net/.ca/.it/.nl per docs                                                                                                                                                                         |
| `info` / `sync`               | `GET DOMAIN` `type=all_info` (expiry, `registry_expiredate`, `nameserver_list`) + `type=status` (`lock_state`, `can_modify`, `transfer_away_in_progress`)                                                                                                                                        | No end-user cookie needed for these types. Not returned after deletion → `not_found`                                                                                                                        |
| `setNameservers`              | `ADVANCED_UPDATE_NAMESERVERS` (`op_type=assign`, `assign_ns`)                                                                                                                                                                                                                                    | Nameservers must already exist. `.it` applies them only after registry tests                                                                                                                                |
| `setLock`                     | `MODIFY DOMAIN` `data=status`, `lock_state`                                                                                                                                                                                                                                                      | Not applied for .de/.uk/.ch/.nl/.fr/.it/.be/.at                                                                                                                                                             |
| `setIdProtection`             | `MODIFY DOMAIN` `data=whois_privacy_state`, `affect_domains=0`                                                                                                                                                                                                                                   | States `enabling`/`disabling` → pending                                                                                                                                                                     |
| `getContacts` / `setContacts` | `GET DOMAIN` type owner/admin/tech/billing · `UPDATE_CONTACTS` `types[]`, `contact_set`, `affect_domains=0`                                                                                                                                                                                      | Whole contact objects are replaced                                                                                                                                                                          |
| `getEppCode`                  | `GET DOMAIN type=domain_auth_info` (**needs the profile cookie**, verify) or `SEND_AUTHCODE` → `EppCodeResult(emailed: true)`                                                                                                                                                                    | Prefer `send_authcode`: we then never hold the code                                                                                                                                                         |
| `transferSync`                | `CHECK_TRANSFER` `check_status=1`                                                                                                                                                                                                                                                                | `completed` → completed. `cancelled` → failed (`reason`). `pending_owner`/`pending_admin`/`pending_registry` → pending. `undef` after submission → unknown                                                  |
| `findSubmission`              | `GET_ORDERS_BY_DOMAIN` `domain`, `order_from` → `GET_ORDER_INFO` `order_id`; queued → `QUERY_QUEUED_REQUEST`                                                                                                                                                                                     | Order `completed` → executed. `pending`/`waiting` → in_progress. `declined`/`cancelled`, or none since `submittedAt` → absent                                                                               |
| `testConnection`              | `GET_BALANCE`                                                                                                                                                                                                                                                                                    | `410` auth, `400` "Access denied: invalid IP"                                                                                                                                                               |

**Error → outcome.** Rejected: `410`, `415`, `420`, `430`, `435`, `440`, `445`, `460`, `465`, `470`, `480`, `485`,
`487`, `541`, `552`, `599`, `701`, and `400` with "Access denied". Retryable (not executed): `310`, `350`, `703`, `720`.
**Unknown** for mutating calls: `300` (deferred), `400`/`404` internal error, `436` (OpenSRS could not interpret the registry reply),
`437`/`486` (something is in flight on this name), `702`/`704`/`705` (registry comms / "timed out, resubmit": never
resubmit register blindly), plus any transport failure. Reads: these retry.

**Contact/profile model.** Domains live in an end-user _profile_ identified by `reg_username`/`reg_password`
(3–20 alnum / 10–20 chars). Generate both per Mohar client, store them in `registrar_refs.secret`, and pass `reg_domain`
to link a client's later domains to the same profile. `contact_set` roles map 1:1 (`owner` ↔ registrant).

**Recovery bonus.** `handle=save` pends an order at no charge and returns `id`, and `PROCESS_PENDING order_id` then
executes it. Using two phases gives us a remote order id _before_ money moves. If `process_pending` then times out,
`GET_ORDER_INFO` answers authoritatively. Recommended for OpenSRS register and transfer.

**Webhooks.** Optional later. Payloads are signed with a shared secret that rotates whenever the URL changes. As of
2026-07 the panel setup order matters. Polling sync stays the source of truth.

**Settings.** `reseller_username`, `api_key` (**secret**), `test_reseller_username`, `test_api_key` (**secret**),
`http_proxy` (**secret**), `webhook_secret` (**secret**, later).

## 6. Sync strategy (all API drivers)

- `domains:sync` already batches by `last_synced_at`. Each driver's `sync()` is **one** read where possible (RC
  details-by-name, OpenSRS all_info). Namecheap uses `getList` pages (100 per call; `Expires`, `IsExpired`, `IsLocked`,
  `WhoisGuard`) instead of per-domain getInfo to save quota.
- Status mapping: RC Active → active; Pending Delete Restorable → expired/redemption; Deleted/Archived → cancelled.
  RC availability `regthroughothers` on a domain we hold → transferredAway. Namecheap `2016166` + check
  "registered" → transferredAway; `IsExpired` → expired. OpenSRS `transfer_away_in_progress` → keep active + flag;
  not found + taken → transferredAway.
- Expiry: always take the registry's date (`endtime`, `ExpiredDate`, `registry_expiredate`). The existing
  "never move back > 1 year without review" rule stays. Renew calls first refresh expiry from `info()`, because RC
  `exp-date` and OpenSRS `currentexpirationyear` must match the registry.
- Pending registrations (`pending_registration`) sync every run, not every 4 h, until resolved.

## 7. Test strategy

- One shared abstract `RegistrarDriverContractTest` (Pest dataset per driver) covers: availability mapping (incl. premium,
  unknown), register completed/pending/rejected/unknown, connect-refused → retryable, read-timeout → `RegistrarTimeout`,
  renew with expiry refresh, restore, info/sync status matrix, transfer status matrix, `findSubmission` executed/absent,
  `testConnection` failure, and **redaction** (command log contains no api key, `passwd`, `domsecret`, EPP).
- `Http::preventStrayRequests()`. Fixtures live in `tests/Fixtures/Registrars/{resellerclub,namecheap,opensrs}/<command>-<case>.{json,xml}`,
  written by hand from the documented shapes (never copied from live accounts). Fake with
  `Http::fake(['httpapi.com/api/domains/register.json*' => Http::response($json, 500)])`. Use `Http::sequence()` for
  submit→timeout→reconcile. Simulate a connect failure with `fn () => throw new ConnectionException('cURL error 7')`.
  Simulate a read timeout with `cURL error 28`.
- Assert the wire format with `Http::assertSent`: RC repeated `ns=` keys and `exp-date` epoch; Namecheap `ClientIp` +
  `AuxBilling*`; OpenSRS `X-Signature` recomputed in the test from the fixture body.
- Live sandbox suite `->group('registrar-sandbox')`. It is skipped unless `REGISTRAR_SANDBOX_<KEY>` env vars exist and
  never runs in CI by default. It uses unique random SLDs (RC demo resets daily; Namecheap sandbox names cannot be deleted).

## 8. Security notes

- Credentials sit only in `registrar_accounts.settings` (`encrypted:array`, hidden). Every key in the schema of type
  `password` is write-only in the admin (the UI shows "set" and never the value). Test and live credentials are
  separate fields so that a test_mode flip can never send live keys to a sandbox URL, or the reverse.
- **Static egress IP is a prerequisite**: all three APIs allow-list source IPs, RC allows only 3 and needs up to 1 h to
  apply them, and Namecheap also wants the IP in `ClientIp`. Our hosting (Codespaces / Laravel Cloud) must provide
  a fixed outbound IP. If it can't, route registrar traffic through a fixed-IP egress proxy (`http_proxy` setting). Show the
  detected egress IP on the registrar settings page.
  _Decision 2026-10-20: drivers do not assume the host has a fixed IP. Every API driver ships the `http_proxy` setting
  (secret) and the registrar page shows the detected egress IP; both land with the first API driver (ResellerClub).
  Before its first live sandbox run the owner provides a fixed IPv4: the hosting's static egress or a fixed-IP proxy.
  `np_manual` sends nothing and needs neither._
- Rotating keys: RC and Namecheap invalidate the old key **immediately**, so rotation is "regenerate → paste → test
  connection" in one sitting.
- TLS verification always on. OpenSRS signs with MD5, which is acceptable only over TLS. Webhook signatures are verified
  with a constant-time compare.
- EPP codes: prefer `emailed` flows (OpenSRS `send_authcode`). When a code is returned (RC `domsecret`) it is shown
  once and never stored. The transfer-in `epp_code` stays encrypted and is wiped on completion (already implemented).
- .np documents (citizenship/passport scans) are high-sensitivity PII: private disk, encrypted at rest, staff-only
  signed URLs, deleted 30 days after approval/rejection (`domains.np_document_retention_days`).

## 9. Nepal `.np` — manual-workflow driver (`np_manual`)

**How .np actually works (register.com.np, Mercantile Communications).** Registration is **free**. Names are first-come-first-served,
and the name must match the applicant: for a person, their name as it appears on citizenship/passport/driving licence/voter card
(NRN card or resident visa for foreigners); for an organisation, its registered company, brand, product or service name.
Bare last names and caste names are reserved for organisations. Organisations upload their registration certificate (foreign
entities: branch/liaison registration or Social Welfare Council affiliation; non-incorporated: PAN certificate; trademark
certificate or filing receipt as an alternative) **plus a request letter on letterhead with stamp and signature**. Uploads must be
jpg/png/jpeg/gif/svg, max 800 KB. Nameservers must be FQDNs (an IP is rejected). The admin contact must be the **actual owner, not
the hosting provider**. The owner holds the register.com.np account (email = username), and every domain sits under it. Approval
takes ≥24 h, typically 3–4 business days. Rejected requests are kept only 3–4 business days. NS edits are processed twice a day
and only through the portal (requests by email are ignored). Account-to-account "transfer" means a formal letter + ID emailed to
hostmaster@. There is no EPP code, lock or privacy. .com.np has never expired, but since Dec 2024 the registry runs **reverification**
(non-responders are parked/released) and has started recording renewal dates, emailing the admin **15 days before expiry**.
.gov.np goes through NITC (set `allow_register=false`). IDNs are not offered (min 3 chars).

**Driver.** `NpManualRegistrar` with capabilities `['register', 'renew', 'nameservers', 'contacts', 'info', 'sync']`
(no availability: lookup reports `unknown` with a "check at register.com.np" hint; staff confirm during review).
Calls never leave the app. `register()` creates the workflow and returns `RegistrarResult('pending')`, so the domain moves to
`pending_registration` through the existing path. Settings: `handling_fee_note`, `review_cycle_years` (default 1),
`client_reminder_days` (`[1,3,7]`), `staff_sla_business_days` (4), `hostmaster_email`.

**Workflow** (`registrar_meta.np.stage` + timestamps; each change writes activity log + staff todo):
_Built 2026-10-20 with its own `np_registrations` table (stage, applicant type, dates, rejection cause) instead of
`registrar_meta.np`, so the staff to-dos query indexed columns, and `np_documents` for the files. SVG uploads are
refused (they can carry script and we have no sanitiser). The driver also declares `reconcile`, so the sync sweep
resolves an interrupted submission without opening a second request. Not yet built: client document reminders
(`client_reminder_days`, these need an email template) and nameserver/contact changes after approval (staff make
them in the portal). The pre-filled request letter also waits, with the screens PR._

_Closed and abandoned requests (CTO decision, 2026-10-20): a request whose domain is cancelled, transferred away,
marked fraud or deleted is closed. No step can be recorded on it, it leaves the to-dos and takes no uploads, and its
documents are deleted 30 days later (`domains.np_document_retention_days`), whoever closed it. The system never
cancels a paid order on its own (revised 2026-10-20): a request still waiting for the client's documents, on a domain
that is `pending` or `pending_registration`, with no client upload and no staff step for 90 days, is listed on the
"Abandoned .np requests" staff to-do (`domains.np.review`) with the domain, the client and the amount paid on its
order invoice. Staff decide: cancel the domain and refund through the existing domain and billing actions, or keep
waiting ("Keep waiting" restarts the 90 days). The client sees a notice asking for the documents. Requests waiting on
staff (`documents_review`, `ready_to_submit`) are never listed or touched. Each document deletion is independent, so
one failure is logged and never stops the rest. Dates and business days use the company calendar (`system.timezone`). Known limits: image
metadata (EXIF, such as phone GPS) is kept as uploaded, and a hard delete of a domain record (none exists today)
would leave its encrypted files on disk._

| Stage                | Who                                                                                                                                                                 | Exit                                                                                                                                                                                               |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `awaiting_documents` | Client uploads ID / company certificate + letter (letter template provided, pre-filled with name, purpose, NS)                                                      | All required docs present → `documents_review`                                                                                                                                                     |
| `documents_review`   | Staff checklist: name matches doc · correct extension for the category · docs readable · letterhead+stamp+signature · NS are FQDN and resolve · admin email = owner | Pass → `ready_to_submit`; fail → back to `awaiting_documents` with reasons (rejection-cause codes 1–14)                                                                                            |
| `ready_to_submit`    | Client creates or uses their register.com.np account (or staff assists with the client's email). Staff submit in the portal                                         | Staff click "Submitted" (records submission date) → `submitted`                                                                                                                                    |
| `submitted`          | Waiting for the registry                                                                                                                                            | "Approved" (staff enter the registry renewal date if one is shown) → domain `active`. "Rejected" (reason code) → domain `pending`, stage `awaiting_documents`, **resubmit within 2 business days** |
| `active` follow-ups  | NS change → `setNameservers()` returns `pending` + staff todo "apply in portal" (twice-daily processing). Contact change → staff todo with fresh documents          | Staff confirm → cached values updated                                                                                                                                                              |

**Reminders** (scheduler, idempotent like `domain_reminders`): client document nudges on `client_reminder_days`.
Staff SLA alert when `submitted` exceeds `staff_sla_business_days`. Urgent alert when `rejected` is older than 2 business
days (the registry drops it after 3–4). On each review cycle, `renew()` returns `completed` locally, extends the review date by
`review_cycle_years` and opens a staff todo "confirm status / reverification in the portal". `sync()` returns the cached state
(nothing remote). It flags `needs_review` if the review date has passed with no staff confirmation. Billing: since the registry
is free, the TLD price is our handling/management fee, and renewal invoices cover our service, not a registry charge.

## 10. Recommended implementation order

1. **Prereqs PR** (blocks all real drivers): C3 stale-claim sweep (issue #12), C2 `findSubmission` + `SubmissionLookup`,
   C1 remote ids, C5 redaction, the shared contract test harness, and the egress-IP decision.
2. **`np_manual`** — no external dependency, unblocks the Nepal market, and exercises the async/pending paths end to end.
3. **ResellerClub** — the main wholesale source for India/Nepal hosts and the best `.in` coverage. Needs C4 (`registrar_refs`).
4. **Namecheap** — the US market. Simple inline contacts and a good sandbox. Ships without `epp`/`suggestions`.
5. **OpenSRS** — on demand. Use the save→process two-phase register. Webhooks later. Enom is skipped.

## 11. Pitfalls (cross-cutting)

- Treating "not available" or "already exists" as failure after an unknown outcome → a paid domain that sits unprovisioned,
  or a double charge if someone retries elsewhere. Always check ownership first.
- RC `test.httpapi.com` with live credentials acts on production. RC demo data disappears every 24 h, so do not keep fixtures that depend on it.
- RC array encoding (`ns=` repeated), required `-1` contact ids per TLD, and `exp-date` epoch drift.
- RC privacy unsupported for .in/.us. Namecheap API transfers are limited to a TLD list. OpenSRS lock is ignored for many ccTLDs.
  Gate the UI per TLD.
- Namecheap 50/min across the whole key: public lookup traffic can starve renewals without the local budget.
- Namecheap returns HTTP 200 with `Status="ERROR"`; the XML namespace breaks naive SimpleXML xpath; dates are US-format.
- OpenSRS `is_success=1` on taken lookups; `705` says "resubmit", which is unsafe for register; 100 commands per connection.
- Registrant changes start 60-day transfer locks (RC exposes `sixtydaylock`). Warn before saving (domains.md §11).
- Premium prices are in the registrar's currency (USD for Namecheap). Never compare them to our multi-currency price without conversion.
- .np: an application under the hosting company's email or name is rejected. Rejected requests vanish after 3–4 business days.

## 12. Sources

- ResellerClub HTTP API: access & auth https://manage.resellerclub.com/kb/answer/753 · response formats /754 · data types /755 ·
  availability /764 · register /752 · transfer /758 · renew /746 · restore /760 · modify NS /776 · modify contacts /777 ·
  modify auth code /779 · privacy /778 · theft lock /902, /903 · locks /897 · details by name /1755 · order id /763 ·
  search /771 · add contact /790 · default contacts /794 · customer signup /804 · customer details /874 ·
  validate transfer /1150 · submit authcode /2447 · suggest /1085 · customer pricing /864 · default customer /2418 ·
  API abuse https://manage.resellerclub.com/kb/servlet/KBServlet/faq682.html · demo account …/faq415.html
- Namecheap API: https://www.namecheap.com/support/api/intro/ · /global-parameters/ · /error-codes/ · /transfer-statuses/ ·
  methods: /methods/domains/{check,create,renew,reactivate,get-info,get-list,get-contacts,set-contacts,get-registrar-lock,set-registrar-lock,get-tld-list}/ ·
  /methods/domains-dns/{set-custom,get-list}/ · /methods/domains-transfer/{create,get-status,get-list}/ · /methods/users/get-pricing/ ·
  /methods/domainprivacy/enable/ · FAQ (limits, requirements, sandbox) https://www.namecheap.com/support/knowledgebase/article.aspx/9739/63/api-faq/
- OpenSRS: https://domains.opensrs.guide/docs/quickstart · /codes · /protocol-message-structure · /sw_register-domain-or-trust_service- ·
  /get-domain · /modify-domain · /renew-domain · /redeem-domain · /lookup-domain · /check_transfer · /get_transfers_in ·
  /get_orders_by_domain · /get_order_info · /process_pending · /query_queued_request · /advanced_update_nameservers ·
  /update_contacts · /send_authcode · /get_balance · /get_price · /webhooks-overview · index https://domains.opensrs.guide/llms.txt
- Enom: https://api.enom.com/docs/api-test-account · index https://api.enom.com/llms.txt
- .np: https://register.com.np · /registry-policy · /how-to-register-np-domain · /how-to-modify-np-domain ·
  /how-to-transfer-domain · /domain-reverification · /how-to-apply-dns · /rejection-cause · /FAQs · /terms-and-conditions · /np-ccTLDs
