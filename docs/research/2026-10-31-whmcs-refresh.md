# WHMCS refresh, 2026-10-31

Research only. Product manager agent. Public pages only, all wording ours. Every link carries the date it was read
(2026-10-31 unless stated). Nothing was run against a WHMCS install, and no vendor source was read.

## 0. What our docs already cover

| Doc | Newest WHMCS content | Read on |
| --- | --- | --- |
| `docs/reviews/2026-10-29-whmcs-parity.md` | 9.1 (beta notes), 9.0, 8.13; 162 API actions, 339 hooks, 63 gateways, 21 registrars | 2026-10-29 |
| `billing/docs/parity/evolution-and-pain-points.md` | 9.1 beta (called "beta", last updated 2026-09-30); 2026 price list; LowEndSpirit 2763 | 2026-10-18 |
| `billing/docs/parity/admin-and-client.md`, `developer-architecture.md` | 9.0 docs, 8.13 class reference | 2026-10-18 |
| `billing/docs/product/client-import.md` and the invoice and domain import specs | WHMCS 8.0 API behaviour | 2026-10 |
| `docs/handoff/requirements.md`, `state.md` | Decisions only: clean-room boundary, API-only importer, no CBMS for now | n/a |

Result: the 9.1 feature list is already in our docs. What is new is that 9.1 is now **general availability**, the
nine 9.0.x patches and their security content, deprecations and removals, the current price page, and a fresh look at
the competitor field.

## 1. Releases and changes

### 1.1 Versions

| Version | Date | One line | Source |
| --- | --- | --- | --- |
| 9.1.0 | Beta 2026-09-30; GA page last edited 2026-10-20 (exact GA day not stated) | Credit and debit notes with an invoice-immutability setting, unattended maintenance updates, Stripe Dynamic Payments migration, mandatory MarketConnect account, PHP 8.4, Iris admin preview, password history, REST v2 payment endpoints. End of life expected 2027-09-30 | [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/), [9.1 change log](https://docs.whmcs.com/releases/9-1/9-1-change-log/) (2026-10-31) |
| 9.0.0 | RC 2025-12-16, GA 2026-01-20 | Nexus cart, immutable non-draft invoices, VAT format checks for 29 countries, Peppol addon (Belgium, invite only), PHP 8.2 minimum. LTS, end of life 2026-12-31 | [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [index](https://docs.whmcs.com/releases/) (2026-10-31) |
| 9.0.1 | not stated | Cart and error fixes; billing notes left out of income reports | [9.0 change log](https://docs.whmcs.com/releases/9-0/9-0-change-log/) (2026-10-31) |
| 9.0.2 | not stated | Cancelled services renewed in error, fixed; late-fee and OAuth fixes; one undisclosed security fix | same |
| 9.0.3 | not stated | Setup tasks, sessions, PHP health checks; Stripe Dynamic Payments gateway module added | same |
| 9.0.4 | not stated | Single security patch, one CVE listed (id as printed on the page) | same |
| 9.0.5 | not stated | 16 undisclosed security fixes; stronger encryption of stored sensitive data; Asiapay, Gate2shop, Inpay and Paypoint gateways dropped | same |
| 9.0.6 | not stated | Password reset and logout hardening; viewing client files now needs the "View Clients Summary" permission | same |
| 9.0.7 | not stated | Cron over HTTP limited by IP allow list; 5 undisclosed security fixes; GoCardless callback encryption changed; Hipchat module removed | same |
| 9.0.8 | not stated | Plesk suspend, unsuspend and password change; two CVEs listed (ids as printed) | same |
| 9.0.9 | 2026-09-24 | MarketConnect caching; Plesk XML protocol 1.6.9.1 | [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [feed](https://installatron.com/updatefeed/whmcs_9_0_9) (2026-10-31) |
| 8.13 | 2025-04-07 | LTS; still listed, end date not shown | [index](https://docs.whmcs.com/releases/) |
| 8.12 | 2024-12-04 | End of life 2026-01-31 | [index](https://docs.whmcs.com/releases/) |

The 9.1 change log lists 81 items across beta and GA (12 features, 42 fixes, 22 module items, 5 API), including three
undisclosed security fixes in the beta and hardening of install, setup wizard and Nexus knowledge-base search at GA
([9.1 change log](https://docs.whmcs.com/releases/9-1/9-1-change-log/), 2026-10-31). No 9.1.x patch is listed yet.
WHMCS publishes no security advisory index that I could find on the docs site; fixes appear as "undisclosed" lines in
change logs. Two advisory lines name CVE ids (9.0.4, 9.0.8); the ids look unusual, so treat them as unconfirmed until
checked on a CVE database.

### 1.2 Licensing, pricing, PHP

- **Price (from 2026-01-01).** Plus $34.95 (250 clients), Professional $54.95 (500), Business $84.95 (1,000), then
  2,500, 5,000 and 10,000 tiers, with upper tiers of $1,249.95 to $1,999.95 reported by the trade press.
  [WHMCS pricing](https://www.whmcs.com/pricing/), [webhosting.today](https://webhosting.today/2025/10/17/whmcs-2026-price-increase-stability-comes-at-a-higher-cost/) (2026-10-31).
  Already covered in `evolution-and-pain-points.md` section 3, row 1.
- **WHMCS Cloud (managed).** Starter $9.99 (25 clients), Growth $25 (100), Expansion $75 (300), with per-client
  overage of $0.25 to $0.40, five data-centre locations, no custom modules or theme upload, ImportAssist included in
  the upper two. This is new to our docs. [pricing](https://www.whmcs.com/pricing/) (2026-10-31).
- **Active client definition.** A client counts if it has any active product, service, addon or domain. Plans
  upgrade automatically unless the admin opts out. Same page.
- **PHP.** 9.0 needs PHP 8.2 (8.3 advised) and ionCube 13.0.2; it dropped 7.2, 7.3, 7.4 and 8.1. 9.1 needs PHP 8.2
  (8.4 advised, supported from 9.1) and ionCube 15.0.0. Order matters: upgrade WHMCS first, then PHP.
  [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/) (2026-10-31).
- **Module-author impact in 9.0.** Illuminate libraries move from 7.x to 9.0, Smarty to 4.x, legacy Smarty tags and
  PHP-in-template stop working. [community 353314](https://whmcs.community/topic/353314-whmcs-90-%E2%80%93-upcoming-changes-to-module-requirements/) (2026-10-31, first 100,000 characters read).

### 1.3 Deprecated and removed

| Item | Status | Source |
| --- | --- | --- |
| PayPal Basic and PayPal Checkout | Removed from new 9.1 installs; existing installs keep them; PayPal support ends 2027, then transactions fail. PayPal Payments is the replacement | [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/) |
| Stripe, Stripe ACH, Stripe SEPA | Deprecated for Stripe Dynamic Payments | same |
| `allow_adminarea_invoice_mutation`, `allow_api_invoice_mutation` config flags | Deprecated; replaced by the admin setting | same |
| TrustCommerce, CashU (9.0); Asiapay, Gate2shop, Inpay, Paypoint (9.0.5); Hipchat (9.0.7) | Removed | [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [9.0 change log](https://docs.whmcs.com/releases/9-0/9-0-change-log/) |
| Domain grace and redemption fee billing setting | Removed in 9.0; renewal invoices are cancelled and reissued with the fees | [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/) |
| Google password auth for mail import | Removed; OAuth2 needed | same |
| Smarty `{php}`, `{fetch}`, `{include_php}` tags | Removed | same |

### 1.4 New features by theme

- **AI.** Namespinning (AI domain suggestions inside the Nexus cart) in 9.0 and a MarketConnect "Nova" AI site
  builder on the pricing page. No AI in 9.1 notes. Blesta sells an AI addon at $5.95 and $14.95 a month. Sources:
  [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [pricing](https://www.whmcs.com/pricing/), [Blesta pricing](https://www.blesta.com/pricing/).
- **Client theme and cart.** Nexus theme and cart (9.0); Nexus now shows service and addon upgrades (9.1). The Iris
  admin theme is a preview in 9.1 and is meant to become the default later, no date.
  [9.1 highlights](https://docs.whmcs.com/releases/9-1/9-1-release-highlights/).
- **MarketConnect.** In 9.1 an upgrade tries to create a Marketplace account and a new install cannot reach the admin
  area before connecting one. Offerings became backward compatible in 9.0. [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/).
- **Payments.** Stripe Dynamic Payments (9.0, migration in 9.1), PayPal removal path, headless payment sessions for
  Stripe Dynamic Payments, PayPal Payments and PayPal Card Payments, BitPay token change, WorldPay and Skrill now
  require a secret. Same notes.
- **Domains and registrars.** Few changes: URL validation for 101Domain and IPMirror, OpenSRS PEAR removal, more
  frequent SSL expiry checks, and the grace-fee removal above. WHMCS did not add a registrar module in these notes.
- **Tax and e-invoicing.** 9.0 added format checks for VAT numbers of 29 countries, optional authority checks, daily
  automatic VAT rule updates, and a limited Peppol BIS 3.0 addon for Belgium only, by invitation, which auto-sends a
  credit note when a submitted invoice is cancelled. 9.1 adds nothing on tax. I found no official page listing more
  countries; a third-party Peppol addon for WHMCS exists ([ArkHost](https://arkhost.com/knowledgebase/5547/Peppol-Billit-for-WHMCS.html)).
  Nothing for Nepal or India.
- **Operations.** Unattended maintenance updates with backup, restore and email (9.1); cron HTTP calls blocked by
  default (9.0); login rate limit of 10 failures in 10 minutes (9.0); health check for unencrypted 2FA (9.0).

## 2. Developer surface

| Area | Finding | Source |
| --- | --- | --- |
| Legacy API | Still 162 actions in 16 categories, same count as our 2026-10-29 read. No billing-note or credit-note action exists; credits are the account-credit actions (`AddCredit`, `ApplyCredit`, `GetCredits`). `includes/api.php` no longer sets a cookie, and versioned requests ignore session cookies (9.1) | [API index](https://developers.whmcs.com/api/api-index/), [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/) |
| REST API v2 | Needs 9.0 or later. Covers system, store, cart, client-facing user (services, domains, invoices, payment links and sessions, stored methods) and admin (client search, masquerade). 9.1 adds invoice payment sessions, payment links and stored-method endpoints; `GET /store/billing/gateways` returns a flag saying whether a gateway supports headless payment. 9.0 called its documentation beta | [REST intro](https://developers.whmcs.com/rest-api/introduction), [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/) |
| Hooks | Still 339 in 19 categories; same as 2026-10-29. `PayMethodMigration` exists. Release notes name no hook additions. 9.0.7 changed invoice-creation hook behaviour when invoices merge, and 9.0.6 tightened invoice-processing hooks | [hook index](https://developers.whmcs.com/hooks/hook-index/), [9.0 change log](https://docs.whmcs.com/releases/9-0/9-0-change-log/) |
| Module interfaces | No gateway or registrar interface change is announced. A gateway can now advertise headless payment support. Gateway modules must handle migration of stored methods from Stripe modules | [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/) |
| Importing | ImportAssist (CSV client import, a cron task, included in Cloud Growth and Expansion) is WHMCS's own migration path into Cloud. It is not in the API index | [9.0 notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/), [pricing](https://www.whmcs.com/pricing/) |

Not checked: the 9.1 and 9.0 template diffs on GitHub, and the published sample modules for new interfaces. The
developer changelog URL I guessed returned 404.

## 3. Market signals for Nepal and South Asia hosts

| Signal | Source | In `evolution-and-pain-points.md`? |
| --- | --- | --- |
| 2026 price rise of about 17 to 22 percent on the three entry tiers; new tiers to about $2,000 a month | [webhosting.today](https://webhosting.today/2025/10/17/whmcs-2026-price-increase-stability-comes-at-a-higher-cost/) | Yes, section 3 row 1 |
| Forum reaction: most established hosts say they will absorb or pass on the cost; newer hosts see little reason to start on WHMCS; Blesta is named most, HostBill has a higher entry cost and weak client UI | [LowEndSpirit 10075](https://lowendspirit.com/discussion/10075/2026-whmcs-pricing-increase) (Oct 2025) | Partly (thread 2763 only). The 2025 thread is new |
| Hosts feel migrating thousands of clients hurts more than paying; an importer to Blesta was called glitchy | [LowEndSpirit 6888](https://lowendspirit.com/discussion/6888/whmcs-replacement-which-billing-panel), [LowEndTalk 170856](https://www.lowendtalk.com/discussion/170856/blesta-migration-from-whmcs) | No. Supports making our importer dry-run and reversible |
| Mandatory MarketConnect and upsell | [9.1 notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/), [community 291673](https://whmcs.community/topic/291673-buyer-beware-with-marketconnect) | Yes (row "Vendor upsell"). I found no forum thread reacting to the 9.1 change yet |
| Upgrade breakage and encoded third-party modules at 9.0 | [ModulesGarden](https://www.modulesgarden.com/blog/?p=13357), [SolusVM](https://support.solusvm.com/hc/en-us/articles/29115181845655-Is-the-WHMCS-module-for-SolsuVM-compatible-with-PHP-8-2-on-WHMCS-server) | Yes (row "Upgrades break add-ons") |
| Nepal gateways: Khalti publishes an official WHMCS module; eSewa has only a community module last touched July 2023 | [Khalti docs](https://docs.khalti.com/plugins/whmcs), [GitHub topic](https://github.com/topics/esewa-gateway) | No. A concrete switching argument: first-party eSewa and Khalti drivers |
| Competitors ship security releases monthly: FOSSBilling 0.8.7 to 0.8.9 (Sep to Oct 2026), Paymenter 1.5.7 to 1.5.9 (Jul to Oct 2026), Blesta owned licence still $350 to $1,495 | [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling/releases), [Paymenter](https://github.com/paymenter/paymenter/releases), [Blesta](https://www.blesta.com/pricing/) | Blesta price: yes. Others: no |
| WHMCS Cloud at $9.99 for 25 clients lowers the entry bar for tiny hosts but forbids custom modules | [pricing](https://www.whmcs.com/pricing/) | No |

No public Reddit page surfaced in search, so Reddit is not represented. WebHostingTalk threads could not be located
by search either. I found no Nepal-specific page on WHMCS licensing or on Nepali hosts leaving WHMCS.

## 4. Impact on Mohar

| Change | Impact |
| --- | --- |
| 9.1 is GA, not beta | Parity doc `evolution-and-pain-points.md`: relabel (done in this PR) |
| Credit and debit notes plus immutability setting | None; already built (ADR 0024). The parity review row stays "Better" but the lead is smaller |
| PayPal Basic and Checkout removed from new installs, PayPal support ends 2027 | `client-import.md`: imported gateway names `paypal`, `paypalcheckout` must map to our PayPal driver or be flagged "unsupported". Parity review gateway row: note it |
| Legacy Stripe modules deprecated for Dynamic Payments | `client-import.md`: map `stripe`, `stripeach`, `stripesepa` and `stripe_dynamic` (name unconfirmed) to the one Stripe driver; stored-card tokens do not move without Stripe-side migration, so import them only if the owner's Stripe account is the same |
| Removed gateways (CashU, TrustCommerce, Asiapay, Gate2shop, Inpay, Paypoint) | Importer: treat as unsupported with a clear message |
| Domain grace and redemption fee setting removed; renewals reissued with fees | Domain import spec: expect cancelled-and-reissued renewal invoices in 9.0+ data; do not read them as errors |
| REST v2 payment sessions and `supports_headless_payment_session` | Parity review row "REST API v2 with headless cart": still Missing. Suggest adding a spec note to `api.md` and `payments` on payment sessions and payment links (we have invoice payment links to check) |
| Legacy API unchanged (162 actions, no billing-note action) | `client-import.md` read-only action list stays valid. Credit notes cannot be read through the API, so imported history has only the invoice and transaction rows. Document this limit |
| 9.0.5 stronger encryption of stored sensitive data; `EncryptPassword` and `DecryptPassword` actions remain | Importer must never request decrypted card or server passwords; say so in the spec |
| Password history (4) and 12-character minimum | Parity row stays Worse (we have length rules only). Suggest a spec line in the auth spec |
| Unattended maintenance updates | Parity row "Upgrade command with backup and rollback" stays Worse |
| Cloud tier ($9.99 to $75) and active-client definition | Pricing decision (owner). Our commercial model is still open; worth citing in the next CEO review |
| Peppol addon Belgium only; no Nepal or India items | None. E-invoicing parity row stays "Same" |
| Nova AI builder, Namespinning | Parity row "Domain suggestions": unchanged, still Worse. The AI module spec should list name suggestions as a candidate |
| Iris admin theme preview | None; parity review item 12 already mentions it |
| Competitor release cadence | None; but note for the CEO review that FOSSBilling and Paymenter ship security releases about monthly |

### Suggested spec changes (not made)

1. `client-import.md`: a gateway-name mapping table, including PayPal and Stripe legacy names and removed gateways.
2. `client-import.md`: state that credit and debit notes are not available through the legacy API and that encrypted
   fields are never requested.
3. Domain import spec: 9.0+ grace and redemption fees appear as reissued renewal invoices.
4. Auth spec: password history and a letter-plus-number rule.
5. Payments or API spec: payment sessions and a gateway capability flag for headless payment, modelled on the WHMCS
   idea but in our own terms.
6. A Khalti and eSewa driver note that the first-party WHMCS offering is thin (eSewa) or manual (Khalti).

## 5. Owner to confirm

- Do you want the importer to carry over stored card tokens from WHMCS at all, given that tokens belong to the
  gateway account and WHMCS 9.0.5 changed how it stores sensitive data?
- Is a managed, hosted Mohar tier (like WHMCS Cloud at $9.99 for 25 clients) in scope for small Nepali hosts?

## 6. Could not confirm from an official source

- Exact GA day of 9.1.0, and the release dates of 9.0.1 to 9.0.8 (the docs list none; 9.0.9 is dated 2026-09-24).
- Whether any 9.1.x patch exists; none is listed.
- The CVE ids on the 9.0.4 and 9.0.8 lines were not checked against a CVE database.
- Which countries the Peppol addon supports beyond Belgium; no official page found.
- Any WHMCS public roadmap; WHMCS states it publishes none. The roadmap thread is from October 2023.
- Reddit and WebHostingTalk reactions to 9.1 or the 2026 prices.
- Whether the name of the Stripe Dynamic Payments gateway module matches our importer's mapping.
- Sample modules on WHMCS's public GitHub: not re-read in this pass.
