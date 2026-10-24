# Issue triage, 2026-10-24

Source: `gh issue list --state open` (108 at start, 107 after this triage, #443 arrived during it), 223 merged PRs into
`develop`, `state.md` from `feature/handoff-1024` (#437), `operating-model.md`. Evidence checked with `git grep` on
`origin/develop` (445a08b3).

## 1. Closed

| Issue | Closed with |
| --- | --- |
| #309 | Fixed in #395. The Redactor treats a byte >= 0x80 after `<` as a name start; the `#309` test is in `tests/Unit/Support/Integrations/RedactorKernelRulesTest.php`. |

Only one issue had clear evidence. Most "PR references issue" hits are PRs that *filed* the issue as a follow-up
(#38, #53, #115, #156, #173, #286), not fixes.

### Likely done, needs a check

| Issue | Evidence | What to check |
| --- | --- | --- |
| #121 | `pay-panel.tsx` says "You'll be taken to {provider}" and `ClientPaymentOptions` passes `$driver->name()` ("Stripe"), from commit 07282fcd (#58). The issue was filed a day after that. | Whether the owner's preview ran an old build. Also the sub-item: does a provider refusing to start a hosted payment show the client a clear message? |
| #31 | #424 rebuilt the client dashboard (banner, services, invoices, support and domains panels) and closed #417, which repeats #31. | Left from #424's own list: auto-renew toggle and Renew now on the Domains panel, New ticket and Help buttons on Support, usage bars. Close #31 and move those 3 to P8b, or keep it open for them. |
| #430 | Covered by open PR #432 (VAT 3a). | Close when #432 merges. |
| #402, #439 | #402 is fixed by open PR #438; #439 is the decision split out of it. | Close #402 when #438 merges. |
| #418 | Fixed by open PR #440. | Close when #440 merges. |
| #225 | #412 did items 2 to 4. Items 1, 5 and 6 are owner-deferred (`requirements.md` lines 58 to 60). | The owner can close it as decided, or keep it for the deferred items. |

Partly done (keep open; the remaining work is in the package):
- #254: item 1 done in #264. Items 2 and 3 are left (central log processor, ActivityLogger and `fraud_checks.raw`).
- #383: items 1 to 3 done in #395, item 5 in #386. Item 4 is left: `GatewayConfig::secrets()` has no caller in Billing.
- #267: first-writer-wins, the null default and the inactive-unconfigured rule are done in #408. Left: the client-merge
  participant and the double account read.
- #216: the time-zone search and the marker were done in #413. Left: a lock around the first install (for the VAT rule)
  and fiscal-year numbering (owner).
- #217: N5 done in #362. Left: N4 (more real eSewa captures).
- #354: the shared lookup was done in #428. Left: step-up for a queued export.

Confirmed still open (spot checks): #13 (`SyncDomain` never clears `renew_sent_for`), #18 (only the merge sources get a
new key), #36 and #71 (`services/show.tsx:697` still shows `#${link.invoice_id}`), #44 (no reveal in the client
controller), #132 (`inProgress()` still takes the lock), #155 (the `sandbox_card` row is still skipped), #244 (Node is
still 24.21.0), #366 (`date(coalesce(...))` in `ServicesDashboard.php:112`), #436 (four `todayDate()` callers left), #29,
#68, #146, #147, #51, #52, #311, #399.

## 2. Obsolete or duplicate (not closed)

| Issue | Reason | Duplicates |
| --- | --- | --- |
| #418 | One row of #34's table (`core.default_country`); PR #440 fixes it | #34 |
| #71 (bullet 1) | Same file and fix as #36's second bullet (invoice ids on `services/show.tsx`) | #36 |
| #83 (snapshot part) | "Build kernel:api-snapshot" | #67 item 1 |
| #70 (bullet 1) | ADR 0010 amendment on deprecating keys in array shapes | #68 (second paragraph) |
| #136 | Same root cause and fix as #103: every read decrypts the whole stored account list; one small unencrypted index fixes both | #103 |
| #176 | ADR 0013 steps 1 and 2 are built (#337, #361, #363, #364, #398, #410, #425). Only the BS fiscal-numbering question is left, and it is already an open owner question in `state.md` | #216 item 4 / `state.md` |
| #177 | A planning tracker. S5 is done (#208 closed). The rest is connectIPS, which is #27's first item and the #128 spec | #27, #128 |
| #42 (settings-controls part) | The issue itself says "fold in #34" | #34 |
| #216 item 4 | Fiscal-year numbering | #176 / owner question |

## 3. Work packages

Size: S < 400 changed lines, M 400 to 1,000, L 1,000 to 1,500. Agent `module-engineer` runs on Sonnet; the
"model" note says where the brief should ask for Opus or a reviewer gate. GL = blocks go-live (Nepal launch).
Hot areas follow the one-owner rule (Redactor, KYC images, a registrar driver, server import).

### 3a. Buildable now (no owner decision or account)

| ID | Issues | Area / files | Size | Depends on | GL | Agent |
| --- | --- | --- | --- | --- | --- | --- |
| P1a | #311, #399 | Redactor internals: `Redactor.php`, probe-file fold (ADR 0023 step 2) | M | none (Redactor hot area) | no | module-engineer (Opus, security) |
| P1b | #254, #383 | Redaction step 4 and the host steps: `RedactingProcessor` on every channel, ActivityLogger, `fraud_checks.raw`, Billing passes `GatewayConfig::secrets()` | M | P1a | **yes** (secrets in logs) | module-engineer (Opus) + principal-architect gate (kernel) |
| P2a | #13, #15, #55 | Domains renewals and pricing: `SyncDomain`, `PerformRenewal`, Renew now idempotency, TLD price save | S/M | none | **yes** (renewals stall for staff; Renew now double-click gives two invoices) | module-engineer |
| P2b | #346, #347, #348, #349 | Domains staff edits during in-flight orders, TLD details editing, shared `SettingFieldRules` (kernel), spec and simulator drift; from #449 re-review: sync on a Cancelled domain holding a renewal claim, confirm Grace/Redemption renewals without a staff Sync, assert the cart/AddDomainToCart client-id passthrough | L | P2a (same module); #348 is shared kernel (CTO) | no | module-engineer (Opus) |
| P2c | #203, #267 | `.np` rules in one place plus staff orders, per-TLD how-to link; `registrar_refs` merge participant | M | none | **yes** (Nepal `.np`: staff orders skip the rule) | module-engineer |
| P3a | #103, #136, #134, #135, #149 | Server import storage: unencrypted index, unique report lines, keep expired reports, L1 to L7 | M | none (server-import hot area) | **yes** (cPanel switch path, 20k accounts break the page) | module-engineer (database-reviewer on the PR) |
| P3b | #139, #140, #143, #141 | Server import features (spec §10.2): accept server values, Skip/Ignore, cycles with a price; imported-suspended wording; from #453 review: prune capped by rows not lists, batch `link()` updates, a PostgreSQL test of the 400750 dedupe migration, stream Match again, a staff-visible message for a corrupt stored list | M | P3a | **yes** (switch path) | module-engineer |
| P4 | #97, #443, #171 | Logins and invitations: hash fingerprint, Invited badge, mail-down 500 on invite/resend (#438 pattern); client import 64 KB warning and company-name bound | M | #438 merged | no (#443 is a 500 but rare) | module-engineer |
| P5a | #90, #142, #145 | Stripe: install-wide idempotency prefix, Pending-refund 409 loop, `mohar_*` metadata filter, refund-before-payment E2E | M | none | no (Stripe is not the Nepal path); #142 gates the first live recording | module-engineer (Opus, money) |
| P5b | #155, #154, #121 (check) | Billing payments: sandbox driver currency check, staff refund of late-payment credit, hosted-start refusal message | M | none | no | module-engineer (Opus, money) |
| P5c | #27 (code items), #217 | Nepal gateways: daily re-lookup of settled payments for portal refunds (Needs-attention), staff note on Khalti bank-funded payments | M | none | **yes** (refunds made in the portal go unseen) | module-engineer (Opus) |
| P6a | #33, #36, #71 | Invoice display: From / Bill to and line period on the client (and admin) invoice; invoice and order numbers instead of ids (`Invoicer::number`, kernel); fraud provider label; servers summary cards | M | coordinate with VAT PR 4 (tax-invoice wording on the same page); kernel method via CTO | **yes** (Nepal tax invoices must show seller and buyer PAN) | module-engineer |
| P6b | #29, #23 | `InvoiceDocuments` contract (kernel) and PDF attachments in invoice and quote mail; quote fallback note | M | VAT PR 4 (the PDF shows the tax-invoice state) | no | module-engineer |
| P6c | #16, #127, #247, #118 | Catalog and storefront pricing: one rounding rule for converted fees, hide or explain unpriced products, tax market per currency as a setting, ConvertPrices event, driver-change secret reset | M | #432 for #118 (tax markets next to the VAT mode) | **yes** (#247: a fresh NPR install lists products that can't be bought) | module-engineer |
| P6d | #45 | `exchange_rate_to_default` on payments, refunds, credit; backfill | M/L | VAT 3b (same `transactions` paths) | no | module-engineer (Opus, money + migration) |
| P7a | #34, #442, #407, #414 | Settings controls: select, multiselect, money, country sources in `SettingDefinition`; Domains section split; tax-rule country select; minor-unit fields; server secret on-blur check; searchable-select flip, clip, label; from #457 review: drop `tax_inclusive` entries for a currency with a blank market, a hint under "Priced for", the default market no longer reads as a change in the publish diff | L | #440 merged; #432 merged (tax-rule page) | **yes** (product standard: owner-blocking) | module-engineer, principal-designer review |
| P8a | #44, #147, #146 (after decision) | Services client area: Show password (step-up, audit, 60 s, rate limit); unknown-outcome staff notice; cancellation email | M | the #146 decision | **yes** (#44: clients can't see a password, and the welcome mail no longer has one) | module-engineer (Opus, auth) |
| P8b | #37, #31 (rest) | Store inside the client area, one domain search page, slug URLs; the dashboard follow-ups from #424 | L | none | **yes** (signed-in customers leave the client area) | principal-designer |
| P8c | #32, #441 | Admin client summary (balances, counts, recent items); admin dashboard profiling under 200 ms | M | none | no (the owner marked #32 "owner-blocking") | module-engineer (database-reviewer on #441) |
| P9 | #18, #30, #51 | Support: rotate the target key on cross-requester merges; department and assignee contract; `first_unanswered_at` | M | none | **yes** (#18 is a security issue, "before public launch") | module-engineer (Opus, security) |
| P10a | #49, #298 | Retention: per-table settings, housekeeping tasks, queue and cache prune, `simplePaginate` on log pages, KYC orphan sweep | L | none | **yes** (unbounded personal-data tables) | module-engineer (database-reviewer) |
| P10b | #50, #52, #84 | Integrity: PostgreSQL CHECK constraints (NOT VALID, then VALIDATE), `integrity:check` registry, verify-sweep index; from #460 review: restore the two Pr460ProbeTest probes (future `--date`, plain refusal), KYC orphan file names over 100 characters handled without logging the path | L | CTO picks which columns are invariant (#50) | no | module-engineer (Opus) + database-reviewer |
| P11 | #157, #307, #317, #389 | `deploy:check-migrations`: indirect calls, raw ALTER, one statement per tag, LockTimeout, duplicate timestamps, tightened FKs | M | none | yes before the first upgrade release (blue-green safety) | devops-engineer |
| P12a | #67, #68, #70, #83 | Kernel API: `kernel:api-snapshot`, API_VERSION, kernel changelog, `config_fields` removal, ADR 0010 amendment, ADR 0015 decision, DNS deadline | M/L | none (CTO session: shared kernel) | no | module-engineer (Opus) + principal-architect |
| P12b | #85 | Module settings and permission ownership: modules register their own permissions and settings | M | P12a | no | module-engineer (Opus) + principal-architect |
| P13 | #354, #367, #431 | Reports: step-up for a queued export, PDF header cap, query budget and shipped conformance kit (ADR 0020 amendment) | M | none | no | module-engineer |
| P14 | #343, #366, #436 | Company-clock leftovers: MailDates `report:false`, dashboard bucketing, the last Domains `todayDate()` callers | S | P2a/P2b not in the same wave (Domains files) | no | module-engineer (quick win) |
| P15 | #305, #107, #60, #216, #132 | Core hardening: step-up download flag, structural step-up test, install follow-ups (one-time password expiry, role key), install lock, automation lock check | M | none | **yes** (#60 one-time admin password never expires) | module-engineer (Opus, auth) |
| P16a | #303, #316, #313 (85 vs 90 after the photo check) | KYC images: non-interleaved JPEG cap, hint, rotated browser resize and its test | M | KYC hot area; #313 needs real ID photos | no (KYC is off by default) | module-engineer |
| P16b | #292, #302 | Image memory: CI job with bundled GD (or a documented manual check), `GLIBC_TUNABLES` on x-app with a load-test before and after | S/M | CTO choice on #292 | no | devops-engineer |
| P17a | #54, #110, #21 | Test hygiene: tracked `.gitignore` deleted, order-dependent Notifications failure, suite memory growth; from #459 re-review: the step-up scan fails on a route that names `RequirePasswordUnlessExpired` directly or has no name, a delete-account test for an expired one-time password, the PostgreSQL install race test (1 failure in 5 runs) | M | none | no (quick win, fewer flakes) | module-engineer |
| P17b | #24, #281 | CI speed and merge-gate parity | M | Actions minutes (owner constraint) | no | devops-engineer |
| P17c | #98, #150 | Simulator framework: fail closed, pid reuse, permissions, e2e guard deadline, `DB_URL`, prune scope, ADR 0016 decision 5 | M | none | no | devops-engineer |
| P18 | #406, #244, #158 | Deploy images: re-run the cooldown check on or after 10-28, Node 24.22.1 and Debian bump, resolve the 3 unverifiable digests, prune old app images | S/M | date gate 2026-10-28 (owner decision, already made) | **yes** (a push to `main` fails the cooldown check until then) | devops-engineer |

### 3b. Needs an owner decision or an external account

| ID / issue | What is needed | Who |
| --- | --- | --- |
| #27 (connectIPS, Khalti refund unit, brand kits) | connectIPS merchant enrolment through a bank (weeks of lead time); a Khalti sandbox to check paisa or rupees for refunds; brand kits from the Khalti and eSewa merchant teams. P5c builds the code items without them | Owner (external) |
| #177 | Start connectIPS enrolment (same as #27). Otherwise a tracker, see section 2 | Owner |
| #217 N4 | 2 or 3 more real eSewa UAT cancel captures, and what the API returns after the ~50-minute expiry | Owner or a session with eSewa UAT access (REA) |
| #176, #216 item 4 | BS fiscal-year invoice numbering and CBMS rules | Accountant (owner) |
| #225 items 1, 5, 6 | Provenance action approval, a heartbeat setting, GHCR pushes from `develop`, the first real release run | Owner |
| #284 | Wait until #283 has shipped in one release (no release cut yet) | Release gate |
| #169 | ADR 0019 accepted, and the WHMCS connector proved the shape | CTO / principal-architect |
| #439 | Should imported logins be passwordless? Record it in ADR 0018 §4; the code is S and goes in P4 | Owner or CTO |
| #146 | Should a "service cancelled" email exist? The code goes in P8a | Owner or product-manager |
| #372 | ResellerClub per-client mode: the importer records the RC customer, or staff record it by hand after checking. A ResellerClub test account would confirm it | Owner (decision; RC account optional) |
| #42 | Company profile page, missing settings (language, privacy URL, maintenance mode), and the confirmations audit. A product-manager spec first (inventory table), then owner sign-off, then 2 builds (L each) | product-manager, then owner |
| #313 | Readability of ID numbers at q85 vs q90 on real card photos | Owner (real photos) |
| #303 L7 | Per-IP KYC limit 20 to 60, only if support reports it | Owner (later) |
| #292 | An extra CI job (Actions minutes) or a manual measurement | CTO / owner (minutes) |
| #90 item 3, #142 gate | A Stripe test account for the live smoke run and the first live recording (the code fix in P5a does not need it) | Owner (external) |
| Not tied to an open issue | A cPanel trial VPS (P3 is verified on the simulator, by owner decision); a WHMCS dev licence for importer fixtures; a Namecheap sandbox before enabling it | Owner (external) |

## 4. Labels added (existing labels only)

#83 docs · #97 security, migration · #247 billing · #281 tests · #292 tests · #298 security · #346, #347, #348, #436
domains · #349 domains, docs · #372 domains, migration · #389 migration · #402 security · #406 dependencies, deploy · #407
security · #414 accessibility · #430 billing · #439 migration · #442 billing.

Left unlabelled because no existing label fits: Support (#30), UI and client area (#31, #34, #37, #42, #44, #71), Kernel
(#68, #70, #84, #85), Services (#146, #147, #366), Reports (#354, #367, #431), #343, #418, #441 and the new #443. The
`ci`, `e2e`, `load-test` and `deploy-smoke` labels are workflow triggers, so they were not used as area labels.

## 5. Order

Rules: at most 5 packages per wave; no two packages in a wave share a hot area or module files; go-live blockers and
quick wins first. Before wave 1, merge #432, #438 and #440 and close #430, #402 and #418 (3 issues).

| Wave | Packages | Closes (about) | Open after |
| --- | --- | --- | --- |
| 0 | merges #432, #438, #440; owner answers #146, #439, #372; product-manager starts the #42 spec | 3 | 104 |
| 1 | P1a Redactor internals · P2a Domains renewals · P3a Server import storage · P9 Support · P8a Services credentials | 16 | ~88 |
| 2 | P1b Redaction processor · P3b Server import features · P6a Invoice display · P10a Retention · P6c Catalog pricing | 15 | ~73 |
| 3 | P7a Settings controls · P8b Store in client area · P15 Core hardening · P11 Migration checker · P18 Deploy images (on or after 10-28) | 19 | ~54 |
| 4 | P2c `.np` and registrar_refs · P5c Nepal gateways · P8c Admin client summary · P17a Test hygiene · P14 Clock leftovers | 12 | ~42 |
| 5 | P2b Domains in-flight edits · P6b PDF attachments · P5b Payments small · P13 Reports · P12a Kernel API | 15 | **~27** |
| 6 | P4 Logins and importer · P5a Stripe · P6d Exchange rates · P10b Integrity · P16a KYC | 13 | ~14 |
| 7 | P12b Ownership drift · P16b Image memory · P17b CI speed · P17c Simulators | 8 | ~6 (owner-gated only) |

Wave 1 overlap: P9 (#30) and P8a (#147, #146) both change `Notifications/Listeners/SendEventEmails.php`. Merge P8a
first, or move #30 to wave 4.

Wave 1 overlap: P9 (#30) and P8a (#147, #146) both change `Notifications/Listeners/SendEventEmails.php`. Merge P8a
first, or move #30 to wave 4.

**Estimate: 5 waves to get under 30 open, if no new issues are filed.** VAT PRs 3b and 4 run alongside on their own
track (Billing invoices), so P6a and P6b wait for VAT PR 4 and P6d waits for 3b; if VAT slips, swap P6a with P8c.

Inflow: 82 issues were opened in 2 days, mostly review follow-ups. At that rate the backlog stays flat whatever the
waves do. Suggest: (1) a review follow-up for a PR that is still open goes into that PR's fix round or into the matching
package above, not a new issue; (2) small follow-ups are filed as checklist items on the package's tracking issue;
(3) each package PR lists "Closes" lines, and the merge step closes them by hand (develop ignores closing keywords).
