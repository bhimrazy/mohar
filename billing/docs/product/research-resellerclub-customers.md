# Research: how billing systems map clients to ResellerClub customers

Status: research note, 2026-10-29. Feeds `registrars.md` (contact model, `customer_mode`) and `invoice-domain-import.md` §7.2
(owner question Q2, issue #372). No code. Written in our own words from public material only.

## 1. Answer in short

- WHMCS: the ResellerClub module is designed as **one RC customer per WHMCS client** (as opposed to its older
  Directi module, which put every domain under one customer). How it finds the customer and what it does on an email
  change or merge is **not documented publicly** and the module source is encrypted, so we do not know it. Treat any
  claim about WHMCS email-change behaviour as unverified.
- Blesta (open source, readable): **one RC customer per client, found by the client's email as RC username, created
  on first domain order, nothing stored locally.**
- Others (HostBill, FOSSBilling, Upmind, WISECP, BILLmanager): public docs say nothing about the mapping.
- Recommendation: keep **default customer as Mohar's default**, support **per-client as an opt-in**, store the id in
  `registrar_refs`, and fix one inconsistency between the two specs (section 5).

## 2. What each system does

### WHMCS

Evidence is thin and old:

- A 2008 WHMCS forum reply by the vendor's founder says the ResellerClub module creates a separate customer account
  on the registrar's side for each client, whereas the Directi module registers all domains under one account.
  The same thread says the Directi module needed a parent id plus a customer id in its settings, the ResellerClub
  module only the reseller (parent) id, and that the per-client accounts reuse the client's login details and a password.
- A 2008 reply in a second thread warns hosts that anything a customer does in the RC control panel does **not**
  show in WHMCS, and suggests disabling the customer's RC email and keeping customers in WHMCS only.
- Current WHMCS docs for the module cover only API credentials and IP allow-listing; they do not describe customer
  creation, matching, or where the id lives. The current module is encrypted; we did not read it.
- Not answered anywhere public: email-change behaviour, client merge, where the id is kept, how existing RC
  customers are adopted. A community thread asking how to migrate from the shared-customer module to per-client
  customers received no answer.

What can be inferred, not confirmed: WHMCS has no documented "RC customer id" field on clients. Whether it keeps one
in a per-client custom store or searches RC each time is unknown. The importer must therefore not assume a WHMCS-side
customer id exists (consistent with `invoice-domain-import.md` §3.2 and Q-list item on visible ids).

### Blesta (module-logicboxes, public on GitHub; read 2026-10-29)

On each domain registration:

1. Search RC customers by `username` = the client's email (`customers/search`, first hit wins).
2. If none, `customers/v2/signup` with username = client email, a **random 15-character password that nobody is told**,
   language, and name/company/address/phone taken from the client. Company empty becomes "Not Applicable".
3. Create fresh contacts under that customer for registrant, admin, tech and billing, then `domains/register` with the
   `customer-id`.
   Notes: no id stored in Blesta; it re-searches by email every time, so an email change on the Blesta client makes the
   next order create a **second** RC customer. Imported services lack the id, and the Blesta forum reports that domain-info
   calls fail for them (they need the customer id). Search takes the first of up to ten hits and does not check that the
   hit is an exact match.

### Others

- HostBill, FOSSBilling, Upmind, WISECP, BILLmanager: public pages describe only the reseller id and API key, IP
  allow-listing, and (WISECP) manual assignment of imported domains to a local customer. Nothing on RC customers.
  FOSSBilling's docs list ResellerClub among registrars needing fixes. Unknown, not assumed.

## 3. How ResellerClub models it (from the API docs already cited in `registrars.md` §12)

The RC KB pages returned HTTP 403 to our fetcher on 2026-10-29, so this relies on the existing spec's reading plus
Blesta's wrapper code: `customers/v2/signup` (username must be an email, unique across the reseller), `customers/search`
(filter by username), customers own contacts, an order is tied to one customer and four contacts, `domains/register`
takes `customer-id`. **Verify in the demo account:** error text on duplicate username, whether search is exact-match,
and whether an order can be moved to another customer (we have not found a public method; assume no).

## 4. Trade-offs

| Topic                                          | Per-client customer                                                                                                                                                            | One default customer                                                                         |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| RC control-panel login for the client          | Possible (username = email, reset by email). Changes made there bypass the host's billing and sync; WHMCS community advice is to disable it                                    | None                                                                                         |
| Contacts                                       | Contacts live under the client's customer; a client's contact set is reusable                                                                                                  | All contacts of all clients under one customer: a long list, harder to audit in the RC panel |
| Moving a client's domains away / to a new host | Cleaner separation, the customer is the client's                                                                                                                               | Domains cannot be told apart by customer; need order ids                                     |
| Verifying an order is ours                     | Customer id is a second proof                                                                                                                                                  | Only order id and reseller id                                                                |
| Privacy / takeover                             | Adopting a customer by typed email is dangerous if emails are unverified: one client can attach their orders to another person's RC customer and that person can then see them | Nothing to adopt; no exposure                                                                |
| Email change / merge                           | Id must follow the client, not the email; the RC username does not change by itself                                                                                            | None                                                                                         |
| Setup effort                                   | Signup, search, drift handling                                                                                                                                                 | One setting                                                                                  |
| Search limits                                  | RC search is per reseller, paged                                                                                                                                               | None                                                                                         |

## 5. Recommendation for Mohar

1. **Default mode: `default_customer`** (already the driver default). Clients are billed in Mohar and never need an
   RC login; this removes the email-adoption risk, the second-customer-on-email-change bug seen in Blesta, and the
   control-panel drift. Nepal hosts mostly register `.np` themselves at the registry, so RC volume per client is low.
2. **Support both**, as built. Per-client stays an opt-in for hosts who want RC-side separation or later hand over
   domains to the client.
3. **Store the id, never search by email at order time.** `registrar_refs` customer ref (local = client) is the only
   source. Better than Blesta/WHMCS-as-known: an email change or client merge cannot create a second customer or lose the link.
   On client merge, move the ref to the surviving client; if both clients have refs, keep the survivor's and record the other
   as an alias (ref rows are read-only history) so old orders still verify.
4. **Username policy conflict to resolve.** `registrars.md` says per-client customers get a generated username
   `client-{id}@host` and never the typed email. `invoice-domain-import.md` §7.2 step 4 requires the RC username to
   **equal the client's email**, which is how WHMCS/Blesta-created customers look. Both cannot hold. Proposed rule:
    - New customers Mohar creates: generated username (unchanged).
    - Adoption of a customer Mohar did not create: allowed **only** through the import verification flow (staff-run,
      order verified in our reseller account, order's customer id equals the claim, RC username equals the client's
      email case-insensitively). This is §7.2 step 4 as written; the generated-username rule applies to creation only.
    - Never adopt by email outside that flow, and not for clients whose email is unverified at runtime.
5. **Import (Q2).** Approve §7.2 step 4 with the clarification in item 4. In default-customer mode the claim's customer
   id is not needed. The one-customer-per-email claim is itself a doubt: if two RC customers share lookalike emails the
   order's customer id (not a fresh search) decides. If the WHMCS export has no customer id, per-client hosts cannot link
   those domains automatically; they use Link order, which then needs the "no customer recorded" mode already planned.
6. **Better than the category:** a stored ref instead of re-search; adoption proof of order plus email; random unknown
   passwords never shown to anyone, with an RC-side reset only on staff request; and an activity-log entry for each
   customer created, adopted or moved.

## 6. Contract changes implied

None new. Uses `RegistrarRefs` and the planned "order owner" capability (`invoice-domain-import.md` §13 item 6). Needs a
documented client-merge hook for registrar refs if the merge module does not already move them (to check).

## Owner to confirm

- Do your Nepal clients ever need to log in to the ResellerClub panel themselves? Assumed: no, so default customer stays the default.
- Does your current WHMCS install use per-client RC customers (the module's design) so that RC usernames are the clients' real emails? Assumed: yes, and the export carries an order id but maybe no customer id.
- On a client merge, should the survivor's RC customer keep the merged client's older domains' customer, or are all moved to one? Assumed: keep as-is, no RC-side move.

## Sources (read 2026-10-29)

- WHMCS community, Directi/ResellerClub registrar modules, https://whmcs.community/topic/48185-directi-resellerclub-registrar-modules
- WHMCS community, moving accounts from Directi to ResellerClub module, https://whmcs.community/topic/49425-moving-accounts-from-directi-module-to-reseller-club-module
- WHMCS ResellerClub docs (mirror), https://whmcsdocs.devops.cpanel.net/8-13/domains/domain-registrar-modules/resellerclub (summarised via search only)
- Blesta LogicBoxes module source, https://github.com/blesta/module-logicboxes (logicboxes.php, config/logicboxes.php); docs https://docs.blesta.com/display/user/Logicboxes; forum 12597 (import domains) found by search, page itself returned 403
- HostBill ResellerClub page https://hostbill.atlassian.net/wiki/x/UIA4N; FOSSBilling https://fossbilling.org/docs/product-types/domains; WISECP https://docs.wisecp.com/fr/resellerclub-module (search/fetch only)
- ResellerClub KB (403 on 2026-10-29, relied on `registrars.md` §12): /804 signup, /874 details, /2418 default customer
