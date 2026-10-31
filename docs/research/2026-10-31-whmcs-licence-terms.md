# WHMCS licence and legal terms, 2026-10-31

Research only, by the product manager agent. **This is not legal advice.** It summarises what public documents say
and leaves interpretation to a lawyer. Quotes are a few words each, with links; everything else is our wording.
All pages were read on **2026-10-31** from public URLs, with no login. Nothing was decoded, no licence was
touched, and no WHMCS install was used. Why it matters: Mohar is clean-room and offers migration from WHMCS (API
importer, a possible database "gap reader", a possible `ValidateLogin` first sign-in; see
[`developer-architecture.md`](../../billing/docs/parity/developer-architecture.md)).

## 0. Answer in short

1. **Data ownership.** The EULA does not say who owns the licensee's own data. It lists what WHMCS owns (software,
   trademarks, usage data, feedback), and licensee data is not on the list. In the Marketplace terms ("you" there is the Marketplace customer), "End User Data" is "Your data, that of Your customers", and a separate clause says "you are the data controller" of the "User Data".
2. **Export to other tools.** Nothing prohibits exporting your own database. One clause (EULA 2.5) bans
   "systematic or automated data collection activities" "on or in relation to our Software" without written consent,
   defined to include "processing" (with a UK and EU data-protection citation inside the parenthetical), "scraping, data
   mining, data extraction and data harvesting". The clause opens with WHMCS's own use of personal information, so its framing
   is data protection and its reach over a licensee's own records is unclear. This is the clause to ask a lawyer about first.
3. **API.** The EULA never mentions the API. The developer docs describe credentials, API roles and an IP
   allowlist, and state no rate limit or API-specific terms in the pages read. Nothing addresses credentials
   handed to a third party.
4. **Reverse engineering.** The EULA bars decompiling, disassembling or reducing the Software "to any human-readable
   form", and bars using the Software "to develop any application having the same primary functions". Whether
   reading your own install's database schema is covered is not stated.

## 1. Documents found

| Document | URL | Version or date shown | Read |
|---|---|---|---|
| End User License Agreement | https://www.whmcs.com/legal/#end-user-license-agreement . The old `https://www.whmcs.com/eula/` path returns 404 on the live site today; `/eula` without a slash redirects to this anchor; the `/eula/` path exists only in the Wayback Machine. I read the EULA from the combined `/legal/` page | "Last Updated: 20 June 2026" | 2026-10-31 |
| Terms of Service (website, purchases, licence sales) | embedded on https://www.whmcs.com/legal/ (no own URL: `/terms/` is 404) | "Last Updated: 25th September 2020" | 2026-10-31 |
| Privacy Policy | https://www.whmcs.com/privacy-policy/ (serves the same combined legal page) | "v.18, Updated September 29th, 2026", a WebPros group policy | 2026-10-31 |
| Technical Support Agreement, Affiliate Agreement, Imprint | https://www.whmcs.com/legal/ | Sep 2013; Oct 2016; none. Not relevant here, not analysed | 2026-10-31 |
| Marketplace Terms of Service, with a GDPR DPA as Exhibit 1 | https://marketplace.whmcs.com/policies/terms | footer "Last Updated: 13th February 2023"; notice dated 30 June 2023 | 2026-10-31 |
| Marketplace Privacy Policy | https://marketplace.whmcs.com/policies/privacy | not read; link seen only | n/a |
| Developer docs (API auth, access control, Marketplace page) | https://developers.whmcs.com/api/authentication/ , https://developers.whmcs.com/api/access-control/ , https://developers.whmcs.com/modules/marketplace/ | "Last updated 1 week ago" (no fixed date) | 2026-10-31 |

**Not found (public).**
- A separate developer or API terms document. Developer pages only link "Terms of Service" and "Privacy Policy" in
  the footer. Only the Marketplace ToS is a separate developer-facing contract.
- A trademark or brand-guidelines page. Guessed URLs (`/brand`, `/trademarks`, `/media-kit`, `/logos`) return 404 and two
  searches found nothing.
- A standalone WHMCS DPA. The only DPA is Exhibit 1 of the Marketplace terms, and it covers Marketplace products.
  The privacy policy says WebPros acts as processor under "the applicable data processing agreement" where it
  processes customer environments for a partner, which we could not locate for WHMCS.
- A "data ownership" or "export your data" statement. WHMCS has no such page in what we read.
- Not checked: any login-gated or client-area terms (ignored by design), docs.whmcs.com help pages.

## 2. Did the documents change after the WebPros ownership, and are old versions archived?

- **Archive.** Yes. The Wayback Machine holds about 120 snapshots with HTTP 200 of `/eula/` from 2017-07-05 to 2026-06-06,
  and similar series for `/legal/`, `/privacy-policy/`, `/license/` (the older EULA URL, from 2009) and the
  Marketplace terms (from 2019-05). Listing: `https://web.archive.org/cdx/search/cdx?url=www.whmcs.com/eula/`.
  Some snapshots are stub pages, so not all were comparable.
- **What we compared** (text pulled from snapshots dated 2017-07-05, 2021-08-18, 2023-02-14, 2025-10-14, 2026-02-10 and
  2026-04-08, plus the live page):
  - Clause 2.3.4 (reverse engineering, "same primary functions") is word for word the same in the 2017, 2021, 2023
    and 2026-02 snapshots and live. It is not a post-acquisition addition.
  - Clause 2.5 differs. The 2017-07-05 snapshot (header "Last Updated: October 2013") has a 2.5 headed "Data Protection Act
    1998", an undertaking by WHMCS Limited to comply with that Act. The "systematic or automated data collection" text
    is absent there. It is present in the 2021-08-18 snapshot (header "Last Updated: 26th March 2018"), under the heading
    "Data Protection", still citing the 1998 Act. The live text cites UK DPA 2018, UK GDPR and GDPR. So the banned-activity
    wording was added between 2017-07 and 2021-08; we did not pin the date.
  - The live "20 June 2026" version is the first we saw that names the "WebPros group", and it adds AI features
    ("WHMCS Nova", section 10), "Active Clients" tier limits, a "Territory" and a "Subscription Term". The
    October 2025 and April 2026 snapshots contain none of those words.
  - EULA governing law (England and Wales, English courts) is unchanged in every snapshot compared.
- **Ownership timeline.** The WHMCS community has a thread
  ([WHMCS is joining WebPros](https://whmcs.community/topic/294823-whmcs-is-joining-webpros/)); the page
  shows a first post "By WHMCS John", dated "June 3, 2019" (a listing date; replies run later, into 2021). We did not
  verify the date against another source, and we did not date the corporate transaction itself. The EULA operator is still "WHMCS Limited", and the live EULA says the
  software may be sold or supported by other WebPros companies while WHMCS Limited stays "solely responsible" for the licence.
  The docs mirror at `whmcsdocs.devops.cpanel.net` suggests cPanel infrastructure hosts WHMCS docs.
- **Limit.** Wayback covers public pages only. Versions shipped inside the download package, "included in the WHMCS
  download package" per the ToS, are not public and were not seen.

## 3. Summary table

Confidence: H = clear text; M = clear text, scope ambiguous; L = absence of evidence or partial reading.

| # | Question | What the text says | Confidence | Question for a lawyer |
|---|---|---|---|---|
| 1 | Data ownership; export right or duty | The EULA [§3.1](https://www.whmcs.com/legal/#end-user-license-agreement) gives WHMCS the Software, Trademarks, "Anonymous Usage Data" and Submissions. Client, invoice and service data are not in the list. No clause grants or denies the licensee ownership, and no export right or duty appears. The [Marketplace ToS](https://marketplace.whmcs.com/policies/terms) §1.4 defines "End User Data" as "Your data, that of Your customers" and §9.1.4 says "you are the data controller". The [privacy policy](https://www.whmcs.com/privacy-policy/) calls a hosting partner "an independent controller". | M (EULA silent; inference from related terms) | Does silence leave the host as owner and controller of its client records under English and Nepali law, with no WHMCS right over them? |
| 2 | Export own DB or use API to move data | No clause forbids exporting your own data. [EULA §2.5](https://www.whmcs.com/legal/#end-user-license-agreement) says no "systematic or automated data collection activities" including "scraping, data mining, data extraction and data harvesting" "on or in relation to our Software" "without our express written consent". It sits under the heading "Data Protection" and its opening words cite UK and EU data-protection law. | M | Does §2.5 reach a licensee extracting its own records from its own install through the licensee's own API credentials or database? Does it reach us, a non-party, building the tool? |
| 3 | API terms: competitors, rate limits, third-party credentials | The EULA does not mention the API. [Developer docs](https://developers.whmcs.com/api/authentication/) say API use needs credentials plus the "API Access" permission for the admin role; [access control](https://developers.whmcs.com/api/access-control/) says access is "restricted by IP" by default, with an optional access key. We found no rate limit and no API-specific terms. The licence is "non-sub-licensable" ([§2.1](https://www.whmcs.com/legal/#end-user-license-agreement)) and licensed "only to You" (§2.2), with no word on delegating credentials. The text does use "Third Party Users" and "sublicensee" in §5.2 and §2.4.2, and §8 refers to "Section 2.2 (Sublicensing)" (the heading of §2.2 is "License Transfers"); a licensee's duty to indemnify and its exposure for a sublicensee's breach are tied to those terms. | L (absence of evidence) | Is a host who gives a migration tool scoped API credentials "sub-licensing" or "transferring" the Software? Does the tool operator need any WHMCS permission? |
| 4 | Reverse engineering and decompiling | [EULA §2.3.4](https://www.whmcs.com/legal/#end-user-license-agreement): no "decompile, reverse engineer, disassemble, or otherwise reduce the Software to any human-readable form". "Software" ([§1](https://www.whmcs.com/legal/#end-user-license-agreement)) includes "documentation, source code, object code" and UIs, excluding third-party components. The database schema is not mentioned either way. The wording has been the same since at least 2017. | M | Is reading table and column layout from a host's own live database "reducing the Software to human-readable form"? Is a schema derived from public docs and public code samples safe? Is the answer different under English law, which has statutory interoperability exceptions? |
| 5 | Competitors; benchmarking; publishing comparisons | No benchmarking clause and no clause on publishing comparisons found in the EULA or ToS. The competitor-related clause is the second half of §2.3.4: no using "the Software to develop any application having the same primary functions as the Software". It binds a licensee. The ToS also bars commercial use of website content "without obtaining a licence" and bars linking so as to suggest "approval or endorsement". | M | Does §2.3.4 stop any Mohar staff or contractor who holds a WHMCS licence (including trial) from testing the importer against a live install? Do the website-content clauses limit quoting WHMCS docs or comparing features? |
| 6 | Trademarks: "migrate from WHMCS" | The [EULA §3.2](https://www.whmcs.com/legal/#end-user-license-agreement) says it "does not authorize You to use the Trademarks" without a written licence, and bars adopting "confusingly similar" marks or domain names. The [ToS](https://www.whmcs.com/legal/) says "WHMCS and the WHMCS logo are trademarks of WHMCS", registered in Great Britain and Northern Ireland. No brand guidelines exist publicly, and nothing speaks to descriptive or nominative use. | M for the clauses, L for guidelines | Is a plain-text "import from WHMCS" a fair descriptive reference in the UK, EU, US, India and Nepal? Does §3.2 bind a non-licensee? Are any wording or logo limits wise (no logo, no "official", disclaimer)? |
| 7 | Termination: data and licence | [EULA §5.2](https://www.whmcs.com/legal/#end-user-license-agreement): WHMCS may terminate on 30 days' notice for an uncured breach, or immediately for a material breach; breaches of §2.3 (including §2.3.4), §2.4 and §3 are deemed material, and the text extends to a "sublicensee's" or "Third Party User's" breach. [EULA §5.3](https://www.whmcs.com/legal/#end-user-license-agreement): on expiry or termination "You must destroy all copies of the Software" and uninstall it, and WHMCS may "terminate, suspend or disable access". The software "will not operate unless WHMCS ... verifies" it (§2.4.2). No post-termination export window. For Marketplace products, [§5.3](https://marketplace.whmcs.com/policies/terms) says you "will have no access to the data" stored in the product. | H | Does the host's own database stay theirs after the licence lapses? Is reading it, without running the software, allowed? Is a lapse-and-export sequence a risk? |
| 8 | Governing law and jurisdiction | EULA §9.3 and ToS: EULA §9.3: "laws of England and Wales" and exclusive English courts. ToS: "governed by English law" and "courts of England and Wales" with exclusive jurisdiction. The Marketplace ToS §10.3: Texas law, Harris County courts. The operator named differs (WHMCS Limited, London, versus "WHMCS, LLC", Houston, in the current Marketplace text, where §1.5 points to the EULA at the older `/license/` URL). | H | Which forum applies to Mohar, which is not a party to either? Enforcement against a Nepal-based entity? |

## 4. Precedent: how other products handle importing from WHMCS

Precedent only; none of this shows a right to do the same.

| Product | How it imports | Mentions WHMCS licence, permission or a disclaimer? | Source (read 2026-10-31) |
|---|---|---|---|
| Blesta | Import Manager plugin reads the WHMCS database (host, name, user, password) plus the CC encryption hash from `configuration.php`; says it works with 5.2 to 7.2; advises importing from a cloned database (these details are snippet-level only: the page renders with JavaScript and a static fetch showed just the navigation) | None in the part read | [Blesta docs](https://docs.blesta.com/x/BYCzBg) |
| HostBill | CLI module run over SSH with WHMCS database credentials and an optional encryption hash; paid managed migration offered | None in the pages read | [HostBill guide](https://hostbill.atlassian.net/wiki/x/lwDtgw) (only snippet-level reading: the page needs JavaScript), [blog](https://blog.hostbillapp.com/?p=8109) |
| Paymenter (open source) | Restore a WHMCS `mysqldump` into a temporary database, then an artisan command | None in the page read | [Paymenter guide](https://paymenter.org/docs/guides/whmcs-importer) |
| Upmind | Uses the WHMCS API with an API role, identifier and secret, and asks the host to allowlist Upmind IPs; imports staff, products, clients, tickets, invoices, payment methods, affiliates | None found | [Upmind guide](https://docs.upmind.com/setup-and-configuration/importing-data/importing-from-whmcs) |

- **WHMCS itself ships an inbound importer** for other billing systems (ImportAssist, per
  [WHMCS docs](https://whmcsdocs.devops.cpanel.net/8-13/addon-modules/importassist), seen in search results only).
  This shows moving between billing systems is a normal part of the category.
- **Public objection by WHMCS.** Two searches (cease and desist, legal action, EULA with migration tools) found
  none. That is weak evidence: we did not search the WHMCS forum archive or news in depth, and private letters
  would not show.
- Both Blesta and HostBill read the host's own database, so a database path has category precedent. Upmind's API path
  matches our importer. Neither fact is a permission.

## 5. What this means for Mohar's options (risk notes, not conclusions)

**API importer** (the host gives Mohar scoped API credentials and pulls its own data).
- The EULA text does not mention the API, and nothing in the pages read forbids a licensee from using it.
- The open points: §2.5 ("extraction", "automated"), and whether giving credentials to a third party counts as
  transfer or sub-licensing under §2.1 and §2.2.
- It sits closest to Upmind's approach. The host is the actor and Mohar is the destination.
- Lower exposure signals if kept: read-only API role, host-initiated, runs once per migration, no access by Mohar
  staff to the host's WHMCS beyond the credentials the host grants.

**Database-export gap reader** (read selected tables from a dump the host provides).
- Not mentioned in any clause. The nearest are §2.3.4 and §2.5. Our schema knowledge comes from public docs and
  published samples, not from decoded code (see [`2026-10-31-whmcs-schema-sources.md`](2026-10-31-whmcs-schema-sources.md)).
- Reading the layout of a host's live database is where the "human-readable form" wording is least clear.
- A dump the host exports and hands over is different from Mohar connecting to the live database; the text does not draw this
  line and a lawyer should say if it matters.
- Handling the WHMCS credit-card encryption hash (as Blesta and HostBill ask for) is a data-protection and payments
  concern separate from licence terms.

**`ValidateLogin` first sign-in** (verify a client's old password against the host's WHMCS at the first Mohar sign-in).
- This is a live, ongoing API call per client, run against the host's install, so it falls under the same §2.5
  and credential-delegation questions as the importer, plus the licence needing to remain active.
- It also depends on the host keeping WHMCS licensed and running. The EULA says the software stops once the licence
  ends (§2.4.2, §5.3). The point is a product risk as well as a legal one.
- It has a separate security cost: Mohar would hold or relay plaintext passwords. We did not read the `ValidateLogin` reference for this note. (The auth docs mention an MD5 form, but that is the admin-login method for API calls, not a client password check.) Treat as outside this note.
- Lower-risk alternative to weigh with the CTO: password-reset links, or importing existing hashes only if the host
  exports them.

**Marketing wording.**
- Named references to WHMCS are in EULA §3.2 territory for licensees. No public guideline allows or forbids a
  descriptive reference, so use is untested here.
- Habits that reduce risk and cost nothing: plain text only, no WHMCS logo or trade dress, an "independent product,
  not affiliated with or endorsed by WHMCS" line, no claims of official compatibility, "import from WHMCS data you
  own" rather than "WHMCS-compatible". The product name never contains WHMCS.
- Do not copy WHMCS docs wording into marketing; the ToS limits reuse of website content for commercial purposes.

**Team practice.**
- Anyone at Mohar who holds a WHMCS licence, trial included, is bound by §2.3.4 (including "same primary functions")
  and §2.4.4 (trial licences are for "review and evaluate" only). Keep import testing on hosts' own installs or public
  fixtures, and let counsel say whether this matters.
- Keep a record of what sources the schema notes rest on (already the approach in the schema note).

## 6. Questions for a lawyer

1. Does EULA §2.5 (no automated "data extraction" or "scraping" "on or in relation to our Software") reach a
   licensee exporting its own records through its own API credentials or database, or only third parties' data
   collection?
2. Does a host giving Mohar scoped API credentials amount to sub-licensing or transfer of the Software (§2.1, §2.2), and how do the "Third Party User" and "sublicensee" wording in §2.4.2, §5.2 and §8 apply (breach by such a person is deemed material, and §8 adds an indemnity)?
3. Is reading table and column layout from a host's own database "reducing the Software to human-readable form"
   (§2.3.4)? Does it change if the host provides a dump instead of a live connection? Are English and Nepali law
   interoperability exceptions relevant, and is there a contract override?
4. Does §2.3.4 ("same primary functions") bind Mohar people who hold a WHMCS licence or trial, and is a test
   install a risk? Can Mohar rely on hosts' installs only?
5. Is plain-text "import from WHMCS" a fair descriptive use of a registered UK mark in the UK, EU, US, India and
   Nepal? Does EULA §3.2 reach a non-licensee? Which disclaimer wording do you recommend?
6. Who owns client, invoice and service data stored in WHMCS: confirm the host is owner and controller, and that
   the licence gives WHMCS no claim over it, including after the licence lapses.
7. Are there limits on running `ValidateLogin` against a host's install for first sign-in, in particular during or
   after a licence lapse?
8. Which court and law would apply to a dispute with WHMCS Limited or a WebPros entity, and what is the practical
   exposure for a Nepal-based company with no WHMCS licence?
9. Should Mohar ask WHMCS for written consent or a statement of position on migration tools, or does asking raise
   risk?
10. Do the website-content terms limit quoting or paraphrasing WHMCS public docs in Mohar's own docs and
    comparison pages?

## 7. Limits of this note

- We read the live EULA, ToS, privacy policy and Marketplace terms in full or by searching their text; the
  privacy policy was read for controller and processor statements only.
- The "Pricing and Term Agreement" the EULA mentions is not public and was not read; it may add terms.
- No legal reading, and no check of Nepal or India law (consumer, contract, IP, data protection).
- Absence claims (no API terms, no brand page, no public objection) rest on listed URLs and a few searches.
- Scratch copies of the pages were kept outside the repository and are not committed.
