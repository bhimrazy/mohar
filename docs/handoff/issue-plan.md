# Wave plan, 2026-10-31

This plan replaces the "Order" section of the 2026-10-24 triage below (that triage stays as history; its package IDs
P1 to P18 are mostly merged). It interleaves four tracks so that no file moves twice:

- **ADR 0030** extension model: #571, M1 to M8 (`billing/docs/adr/0030-extension-model.md`, A8 gives the order).
- **Code structure** (#570): S0 to S8 (`docs/reviews/2026-10-31-code-structure.md` §6).
- **Best-practices audit fixes** (#569): AF1 to AF9 (`docs/reviews/2026-10-31-best-practices.md`, "Proposed fix PRs").
  AF numbers are the audit's PR numbers 1 to 9. "Finding M3" is the audit's M3, not ADR 0030's M3.
- **Nepal VAT package** (NV1 to NV3, from `docs/research/2026-10-31-nepal-vat-ebilling.md`) and the load-test and
  database follow-ups.

Owner decisions that shape it (all 2026-10-31, `requirements.md`): ADR 0030 accepted; Platform merges into Core; an Auth
module is extracted; the 130 PR-named tests are renamed by behaviour; `spatie/typescript-transformer` approved in
principle, for later.

## Reading the tables

- **Track.** CTO = the CTO session makes it (kernel changes: `app/Contracts|Data|Events|Support`), one kernel PR at a
  time, each rebasing on the last and regenerating `kernel-api.txt`. Eng = a `module-engineer` in its own worktree.
  Docs = product-manager or architect.
- **Loop.** **Strict** = the owner's money, auth and data-deletion loop (review board in combined mode, fix round,
  `re-reviewer` at the final head), and here also every migration. **Normal** = one review board pass and merge when
  nothing blocks. **Docs** = slop reviewer if needed.
- **Parallel** says what can run beside it. "Quiet window" means no other open PR touches the same module or driver
  folders (pure moves are reviewed with `git diff -M` and mix no edits).

## Constraints that fix the order

1. #571 before M5a (ADR 0030 A1) and first in A8's order; M3 follows it because M3 changes the gateway registry that the
   settlement callers read.
2. M3 before M1 before M2 before M4 (moves follow the kernel contract, A8). M4 before M5a before M6. M7 after M4. M5b
   last of the lifecycle work, with its first consumer (AcmePanel in M7).
3. S3 and S4 on Billing wait for M3; on Services and Domains wait for M2. **Platform gets no S3 or S4 of its own:** it
   merges into Core (S8) after M2, and the merged Core gets one S3 and one S4.
4. S2 (Core infrastructure to `app/Support`) runs after M2, so the moved gateway and driver files get their new
   imports once. S2 comes before ADR 0031's snapshot change and before S7.
5. ADR 0031 (Core public surface, AF2) is decided after S2 and S8, so it versions what is left in Core. M3 uses the
   contracts that exist then and adds only the ones it needs (ADR 0030, "Relationship with ADR 0031").
6. Per module the order is S3, then S4 (both edit the provider's `use` lines), then that module's AF5 conversion, then
   S5. AF5 comes before S5 because after-commit is a correctness fix; S5 then splits code that already has it.
7. S3 moves a class's unit test with it, so S1 never moves those; S1 per module renames Feature tests in place and
   mirrors the rest. S1 on Payments, Extensions and driver tests waits for M1 (gateways) or M2 (cPanel, registrars,
   ECB, NRB).
8. S5 splits the multi-verb Actions (`RefundTransaction` has 12 public methods) that M5a gates (A1's settlement and
   new-work lists). S5 lands on those before M5a, never beside it.
9. AF9 (starter-kit removal) before S6 and S7: do not move dead files. S6 is one PR, right after a UI wave merges. M6
   follows S6. ADR 0028's mechanism lands before S6 without converting pages; the page conversions follow S6.
10. AF6 (stray-HTTP guard, probe naming rule) before S1.
11. Money, tax and migration work is not moved while it is in flight: NV2 waits for Billing's S3, S4, AF5 and S5.

## Waves

| Wave | PR | Track | Loop | Parallel with | Waits for |
| --- | --- | --- | --- | --- | --- |
| **0 (in flight)** | #571 per-account disable stops stranding settlement (branch `feature/inactive-settlement`) | Eng | Strict (money) | AF3 | merges first; AF3 rebases on it (both touch `RegistrarGateway` and `ExecuteModuleCommand`) |
| 0 | AF3 provisioning retry, sensitive keys, `Queues::*` (findings M3, M4, L2; `feature/audit-fixes-1`) | Eng | Normal, combined review (integrations, redaction) | #571 | — |
| **1 Fence** | S0 module building blocks and folder rules in `architecture.md`; allowed-folders arch rule; review-rules line on renaming adopted probes | Docs | Docs | all of wave 1 | — (first, one day) |
| 1 | AF4 admin permission sweep, Form Request rule, money-controller move (findings M1, M2) | Eng | **Strict** (auth, permissions) | AF6, AF8, AF9, NV1 | — |
| 1 | AF6 stray-HTTP guard, operation budgets, probe naming rule (finding M5) | Eng | Normal, CTO verifies from the diff | AF4, AF9 | #558 (merged) |
| 1 | AF8 pre-push gate installed by `new-worktree.sh` (finding M7) | Eng (devops) | Normal | any | — |
| 1 | AF9 starter-kit removal, `useHttp`, money helpers, tokens, bundle budget (findings L1, L3 to L5, L7) | Eng | Normal, product reviewer for the auth pages | AF4, AF6 | — |
| 1 | NV1 IRD register layout and counts, "copy of original" a MUST (see Nepal VAT package) | Eng | Normal, accountant check afterwards | AF4, AF6, AF9 | — |
| **2 Gateways** | **M3** `GatewayDriverRegistry` (key, class), `settingsFields()` as `list<SettingField>`, `GatewayHttp` and minor-units helper, D3 imports removed, `GatewayConformance`, `GatewayManager` out of `Drivers/` | **CTO** | **Strict** (payments) | S0, AF6 test PRs | #571 merged |
| 2 | **M1** loader and base provider, move Stripe, Razorpay, Cybersource, split Nepal into Esewa, Khalti, Connectips; arch scans cover `app/Extensions` | CTO (kernel part called out) + Eng | Normal, re-review at final head (payments), `git diff -M` | S1, S3/S4 on non-payment modules | M3; quiet window for payments |
| 2 | AF1 request and job correlation (finding H2) | **CTO** | Normal, architect review | M1 moves | M3 (one kernel PR at a time) |
| 2 | S1 per module: Kernel and Tooling folders, `Fakes` dedupe, dissolve `Security/Review*`, `Rr536`, `ReviewProbes`; then Billing (not Payments), Orders, Catalog, Notifications, Support, Core, Themes | Eng, test-only | Normal | M1, M3, S3 | AF6; Payments tests wait for M1 |
| 2 | S3 then S4 on Orders, Notifications, Support; on Billing once M3 is in (Billing/Support/AfterCommit stays for AF5). Adds the registry snapshot test helper | Eng | Normal (S3/S4), snapshot test is the proof | M1, S1 | S0; Billing: M3 |
| 2 | AF5 base: ADR 0011 after-commit, arch baseline (finding H3), then one PR per module right after that module's S3/S4 | **CTO**, then Eng | **Strict** for Billing and Services (money events), Normal elsewhere | S1 | kernel queue after AF1 |
| 2 | DB1 CHECK constraints on `services.status` (#559) and `domains.status` (#560, settle `DomainStatus` first); enum-sync tests | Eng | **Strict** (migration), database reviewer | M1, S1 | — |
| 2 | LT1 investigate #566 (medium renewals 4.0/s) and #567 (public KB and announcements errors at 100 VUs): record status codes first | Eng (devops, database) | Normal; the #566 fix is **Strict** | M1 | #566 fix lands before S5 on Billing |
| **3 Drivers and kernel move** | **M2** move cPanel, ResellerClub, Namecheap, ECB, NRB to `app/Extensions` | Eng | Normal, `git diff -M`; quiet window | S1 (non-driver), AF5 modules | M1; no open Services, Domains or Platform PR |
| 3 | **S2** Core infrastructure to `app/Support/{Database,Tooling}`, 17 `Null*` fallbacks to `Support/<Area>`; kernel fragment, snapshot | **CTO** | Normal, architect gate | S3/S4 Services and Domains | M2 (one import pass), S0 |
| 3 | S3 then S4 on Services and Domains, then AF5 for each | Eng | S3/S4 Normal; AF5 for Services **Strict** | S2 rebases | M2 |
| 3 | S1 on Services, Domains and driver tests | Eng, test-only | Normal | S2 | M2 |
| 3 | DB2 drop redundant indexes and `jobs_queue_index` (#561) in one contract migration | Eng | **Strict** (migration) | any | a week of `idx_scan` data from the load-test database |
| **4 Core, manifest, UI** | **S8** Platform merges into Core, then S3 and S4 on the merged Core (and its AF5) | Eng | Normal, registry snapshot test equal before and after; `git diff -M` | M4, S5 | M2, S2 |
| 4 | AF2 ADR 0031 Core public surface, then the snapshot scope change | Docs (architect), then **CTO** | Docs, then Normal | M4 | S2, S8 |
| 4 | **M4** manifest schema 1, `ExtensionCatalog`, `extensions:cache`, `extensions:list`, kernel constraint, Composer discovery | **CTO** | Normal, architect gate | S5, T1 | S4 on Billing, M1 |
| 4 | S5 one verb per Action: Billing x3, Services x2, Catalog, Domains, Support, Migration (reviewed as code) | Eng | Normal; **Strict** for Billing refund and payment Actions | M4, T1 | S3 and AF5 of that module; the #566 fix |
| 4 | T1 ADR 0028 translation mechanism (hook, message files, no page conversions) | Eng | Normal | S5, M4 | AF9 |
| 4 | S6 frontend paths mirror modules; arch rule 8 | Eng | Normal, product reviewer | S5 | AF9, T1, a quiet UI window |
| **5 Lifecycle** | **M5a** `extensions` table, install/upgrade/enable/disable, registry gating per A1, `ExtensionUsage`, backfill | Eng + CTO review | **Strict** (money and provisioning gating, migration) | S7, NV2 | #571, M3, M4, S5, S8 |
| 5 | **M6** Admin Settings, Extensions page, permission, step-up, activity log | Eng | Normal, security checklist | S7 | M5a, S6 |
| 5 | S7 Auth module (`Fortify` actions, `Auth` and `Settings` controllers, requests, concerns, notifications, `routes/settings.php`; `User` stays) | Eng | **Strict** (auth), product reviewer for sign-in | M5a, M6 | S2, AF2, AF9 |
| 5 | NV2 tax invoice fields (see Nepal VAT package) | Eng | **Strict** (tax, numbering, migration) | M5a, S7 | accountant answers; Billing S3, S4, AF5, S5 |
| **6 Author DX and tail** | **M7** `make:extension`, `extensions:check`, AcmePanel path package with a migration and an `upgrade()` | Eng + CTO | Normal | NV3, L3 | M4, M5a |
| 6 | **M5b** per-extension migrations, lifecycle hooks, `extension:<id>` middleware, guarded listeners | Eng | **Strict** (uninstall, migrations) | NV3 | M7 (first consumer) |
| 6 | NV3 invoice fields and bill reports, server-location check | Eng | Normal | M7 | owner's IRD route decision |
| 6 | AF7 nightly coverage and mutation report on money paths | Eng (devops) | Normal | any | **owner approval** (CI policy, coverage driver pin; CI minutes cap) |
| 6 | **M8+** fraud and notification-channel families, declared hosts, UI slots, verified list | per PR | per PR | — | M7 |
| Later | L3 split `architecture.md` into an index and topic pages; L1 Payments module (only if Billing passes 300 files, after M3); L5 TypeScript types from Resources with `spatie/typescript-transformer` (ADR 0007, after AF9 and Resource-only props) | Docs / Eng | Normal | — | S0; M3; AF9 |

Counting: wave 0 has 2 builders; wave 1 has 5 builders plus S0 docs; wave 2 lists more PRs than can run at once, so
stagger them by the Waits-for column and never exceed 7 agents (the Mac's limit, `operating-model.md`).

### What can run in parallel, in one line each

- Wave 1 is fully parallel; none of it moves a file that M1 to M3 move.
- In wave 2, M3 and AF1 and AF5-base are a CTO queue (one at a time); everything else runs beside them. M1 needs the
  quiet window for payments, so no payment PR, including AF3 follow-ups, is open then.
- In wave 3, M2 needs its own quiet window; S2 follows it; S3/S4 on Services and Domains follow M2.
- In wave 4, M4 and S5 and T1 run side by side. S6 waits for a quiet UI window.
- In wave 5, M5a, M6, S7 and NV2 touch different areas (Core gating, Core UI, Auth, Billing) and can overlap, except
  that M6 starts after M5a and S7 and M5a both edit Core providers (merge one, rebase the other).

## Nepal VAT package (NV)

Source: `docs/research/2026-10-31-nepal-vat-ebilling.md` §14 and §15; questions: `docs/handoff/accountant-questions.md`.
Spec to update with each PR: `billing/docs/product/vat-register.md`. Billing is the module; it is money and tax, so
NV2 is strict.

| PR | Scope | Waits on the accountant? |
| --- | --- | --- |
| **NV1** (wave 1) | (a) `ird` register layout: drop the goods, quantity and unit columns, use the export wording (निर्यात, not निकासी), add the total column (कुल जम्मा) (gap 4); the Nepali header labels may need a kernel change (#480), which the CTO session makes. (b) Register counts per period: sales invoices, credit notes, debit notes (gap 9, Schedule 10). (c) "Copy of original" on every reprint becomes a **MUST** in the spec and gets a test across download, print and email paths (A7, Procedure cl. 6(च)). | No for (a) to (c) as built from the primary text. If the accountant names a different post-2083 sales book format, the columns change in a follow-up. |
| **NV2a** (wave 5) | On a tax invoice paid in a convertible foreign currency, print the NPR equivalent and the NRB rate of the transaction day, and snapshot it as the rate used (Rule 21, gap 5; D6). | Partly: the source and date are settled; buying, selling or mid rate (D6) and "payment day or invoice day" are open. Build with the existing rate-type setting; confirm before launch. |
| **NV2b** (wave 5) | Per-BS-fiscal-year numbering in VAT mode for invoices and notes together: `{BS_FY}` in the format and the `bs_fiscal_year` reset on by default; refuse a format without it when reset is per year (gap 6; D2). Needs a migration for existing sequences. | Yes for notes (the Rules say only "running number"), confirm for invoices (Procedure cl. 6(ङ)). Do not change a live host's sequence before the answer. |
| **NV2c** (wave 5) | BS date always shown on tax invoices and notes in VAT mode (optional today, #207) (A9, gap 7). | Yes (accountant question 1.5). |
| **NV3** (wave 6) | Other Schedule 5 and 6 fields: transaction date beside the issue date, payment method on every tax invoice, amount in words (gap 7); cancelled-bills, corrected-records and audit-trail reports and a master bill view (gap 8); XLSX or XML export (gap 10); deploy check and docs warning that VAT mode on a server outside Nepal is non-compliant (gap 3, A10); a note on how CBMS would report debit notes (gap 11). | Partly: the report shapes follow the Procedure; the server-location warning waits on whether a VPS counts as "cloud". |

Not in the package: the CBMS driver and the IRD software approval itself. The 2026-10-31 owner decision keeps CBMS out of
scope; the research finds that approval is required at any size and CBMS is likely for a cloud-hosted install. That is
the owner action "IRD software approval route" in `state.md`; NV3 and any CBMS work wait for it.

## Load-test and database follow-ups (from #543, #552, #554)

| Issue | What | Where in the waves |
| --- | --- | --- |
| #559, #560 | `services.status` and `domains.status` CHECK constraints | DB1, wave 2 |
| #561 | drop redundant indexes (database review M9) and `jobs_queue_index` | DB2, wave 3, after a week of index stats |
| #566 | medium host renews 4.0/s against 24.8/s on the small host a week earlier; billing day 3,059 s | LT1, wave 2 (investigate); the fix is strict and lands before S5 on Billing |
| #567 | KB and announcements errors at 100 VUs on medium (a per-IP limit is the first guess) | LT1, wave 2 |

Sizing to quote (`billing/docs/devops/sizing.md`, #543): the small host (2 vCPU, 4 GB) is comfortable to about 15 req/s,
which is 200 to 350 people at once (10 to 30 s per page); the medium host (4 vCPU, 8 GB) about 400 people at once, a
lower bound because the load generator shared its CPUs. The small host's billing day did not finish in the 10-31 runs.

## Rules for the plan

- A review follow-up goes into the open PR's fix round or a package above, not a new issue (unchanged).
- Each PR lists "Closes" lines and the merge step closes issues by hand.
- An owner-gated item (AF7, NV3, any CBMS work) stays out of a wave until the owner answers; the CTO re-plans when the
  accountant's answers arrive.
- Re-plan after wave 2: S1 and S3 numbers (130 files, 16 Actions) are from `develop` at `a5b803bf`.

---

# Issue triage, 2026-10-24 (history)


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
| P6b | #29, #23 | Extend `BillingDocuments` (#482) with `invoice` and `quote` documents and set `attaches_document` on the invoice and quote templates; quote fallback note | M | VAT PR 4 (the PDF shows the tax-invoice state) | no | module-engineer |
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
