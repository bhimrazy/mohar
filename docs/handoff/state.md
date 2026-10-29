# Current state

Last updated: 2026-10-25 (evening) by the CTO session, on the owner's Mac. Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: merges older than the current day move to `billing/docs/progress.md`, and history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **74/100** (CEO review 2026-10-29,
  `docs/reviews/2026-10-29-ceo.md`; rubric in `docs/reviews/readme.md`; 70 on 10-24). CTO engineering score: 66/100
  (`docs/reviews/2026-10-29-cto.md`). Same day: security round (`2026-10-29-security.md`, no high findings), WHMCS
  parity (`2026-10-29-whmcs-parity.md`: 152 of 226 features built and tested, top-10 gaps) and open-source comparison
  (`2026-10-29-oss-comparison.md`: FOSSBilling, Paymenter; adopt a frontend i18n ADR first).
- Merged on 2026-10-23 (about 45 PRs, #360 to #434; list: `gh pr list --state merged --search "merged:2026-10-23"`):
    - Company clock: Services, Notifications, Platform, Domains and the Namecheap dates read the company date (#361,
      #363, #364, #398, #425); `CalendarDate` for company dates (#410).
    - VAT: tax-invoice snapshot, fiscal backfill and per-sequence numbering (#369, PR 1a); credit and debit note
      issuer (#385, PR 1b).
    - Reports: PDF wrapping and alignment (#360); paged on-screen `ReportViewer` (#401).
    - Registrars: Namecheap simulator and driver (#373, #376) with its fixes (#411, #425); ResellerClub order link
      (#365), phone typed as +cc number (#380), `CallingCodes` split (#404, #427), first-writer-wins `RegistrarRefs`
      (#408), "Self-registered" status (#423); TLD answers masked in result meta (#368).
    - Redaction (ADR 0023): the ADR (#382), kernel match rules (#395), short stored secrets (#400), masked new
      password in driver failures (#386).
    - Auth: step-up for a login without a password (#384); changing the sign-in email needs step-up and notifies the
      old address (#393).
    - CI and tooling: changelog fragments (#397); test PostgreSQL WAL cap and Docker volume (#375, #394); storage
      cleanup on macOS (#392); `pg.sh --help` (#434); load-test follow-ups (#415); release tarball and draft releases
      (#412).
    - UI: searchable long selects (#413); product sweep with branded error pages (#416); email template list
      (#422); client dashboard panels and due-invoice banner (#424).
    - Kernel: `WebhookEvents` registry (#405). Simulators: atomic scenario write (#371), WHMCS API simulator (#409).
    - Performance: one bcrypt check per sign-in, cheaper dashboard roll-up (#374). Other fixes: eSewa signed COMPLETE
      not found goes to staff review (#362); flaky KYC test (#387).
    - Earlier merges: `billing/docs/progress.md`.
- Owner decisions in force:
    - 2026-10-24: PRs squash-merge by default; a merge commit only when another open PR is based on the branch (#446).
      Merge when nothing blocks and send non-blocking items to planned packages, except money, auth and data deletion.
      CI split into sharded jobs approved (#448). The owner wants a product walkthrough soon: docs overview and walkthrough first.
      #464 CTO calls: a refused late fee is skipped and logged once; order cancel/delete rolls back and keeps the refusal
      record; no staff dismiss for Needs attention; `billing.credit.refund` is a hand grant; `unclassifiedBlock` stays.
    - 2026-10-23 (`requirements.md`): #406 waits for the FrankenPHP 1.13.2 cooldown on 10-28. Product standards: error
      pages carry the host's brand, and a country is chosen by name. Cautious release defaults (#412, #415): no
      `attest-build-provenance`, images from the default branch only, no heartbeat setting, the first release run
      waits for the owner, the load-test label trigger stays, no paid runner runs.
    - 2026-10-23: typed Devanagari in report PDFs is a known limitation (no Devanagari font); the CSV keeps it. The
      shared test PostgreSQL gets 4 GB and a small WAL (#375). Code comments: short, complete, never overstated
      (global rule).
    - 2026-10-22, VAT: an invoice is a proforma until paid and becomes a tax invoice on payment; a credit note
      defaults to account credit; register exports are CSV and PDF (XLSX later); no CBMS integration for now.
      Single source: ADR 0024.
    - 2026-10-22, agents: more than 5 at once is fine on Mohar when the work needs it, bounded by machine load (about
      7 on the Mac); `module-engineer` runs on Sonnet (#333), reviewers stay on Opus.
    - 2026-10-22: invitations default 100/hour, staff-initiated; missing PAN warns only; optional BS date beside AD
      (#207); payments and importer verified against simulators only for now (no bank, WHMCS or cPanel accounts);
      KYC photos resized in the browser (#288). Details: `requirements.md`, Features.
    - **Nepal is the main market.** Clients register `.np` themselves; Mohar shows the host's nameservers.
    - The repository is **public** and named **`bhimrazy/mohar`**; `main` is a readme-and-licence page, all rights
      reserved.
    - Subagents run on Opus (money, auth, kernel, security, re-checks) or Sonnet (docs, mechanical fixes) by task
      (10-21; Fable's weekly limit resets 10-25).
    - Security audits block only dependency changes; no nightly audit while `main` is the default branch (10-21).
    - KYC (#197, owner decision 2026-10-21): off by default; documents deleted 90 days after the account closes.
      Staff review rule: viewing needs `kyc.documents.view` (Support role); approving or declining needs
      `kyc.documents.review`, a sensitive permission held by Full Administrators (`*`) only by default, and every
      decision needs a recent password. Spec: `billing/docs/product/kyc.md` section 7.
    - Invoices use AD dates; an optional "BS beside AD" switch later (#207); BS fiscal-year numbering waits for an
      accountant's answer (10-21).
- Load test on the GitHub runner (small profile 2 vCPU / 4 GB, 50k clients; `billing/docs/devops/sizing.md`): p95
  424 ms at 40 users, billing day 285 s, no errors. Tail latency at 40 users (dashboard p95 5.1 s, sign-in p50
  2.4 s) is the remaining performance finding. Memory: the stack used at most 857 MiB there; 4 GB is the minimum
  in every doc (#320), the smallest size tested.
- The milestone that moves the number most: a Nepali host's switch path end to end on `develop`. Import clients and
  `.np` guidance are merged; next are eSewa and Khalti verified live, and a real cPanel/WHM account.

## Repository

- GitHub `bhimrazy/mohar` (public; the old name `bhimrazy/whmcs` redirects). `develop` = integration, `main` = release
  landing page (not yet cut).
- App: `billing/` (Laravel 13.36, PHP 8.4, Inertia 3, React 19, Pest 5, vite-plus 1.3.0). Local setup:
  `billing/docs/devops/local-setup.md` and the docs-site page "Run locally". Production install:
  `billing/docs/devops/install.md`.
- Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`. Process:
  `docs/handoff/operating-model.md`, `docs/handoff/review-rules.md`.

## Open pull requests and running work

Merged 2026-10-24 (details in `billing/docs/progress.md` at the next update): #438, #440, #444–#461, #462 (P1b redaction backstop on every log channel, after two security rounds), #463 (P3b server
import features), #464 (VAT PR 4c: credit refunds, Nepal VAT mode flag lifted, demo seed has the mode on), #465–#469
(handoff docs; develop fix for the #459/#461 scan; docs-site Overview and Walkthrough; pre-walkthrough sweep;
walkthrough VAT step and `schedule:work` in `composer dev`). Issues closed by hand.

Merged 2026-10-25: #471 (`.np` rule, per-TLD how-to, registrar_refs merge), #472, #473 (invoice From / Bill to), #474 (daily
re-lookup of Khalti and eSewa payments for portal refunds, P5c), #475 (settings controls), #476, #477, #478 (store, cart and
checkout inside the client area, one domain search, slug URLs; reserved group slugs renamed by migration
`2026_10_25_100600`), #479 (`deploy:check-migrations` enforces architecture rules 5 and 6; baseline of 39 old migrations,
sha256-frozen in `CheckMigrationsRulesTest`), #480 (VAT register `detail`/`ird`/`summary` layouts, PDF export, Billing role
gets the two register export permissions), #481 (admin client summary), #482 (VAT PR 5: credit/debit note PDF, email with
PDF via `BillingDocuments::document()`, webhooks, client-area notes, print counting; kernel: `$notify` on note events and
`Invoicer::cancel`, `EmailSent` related ids). Issues closed by hand: #27, #32, #37, #157, #307, #317, #389.

Gateway expansion, merged 2026-10-25 (owner decisions in `requirements.md`: connectIPS, Cybersource cards and Razorpay are extension drivers
verified against our simulators, no real accounts yet; NepalPay QR dropped; Fonepay waits for a bank spec): #128 (spec,
real-test-account to-do list in §15), simulators #485 Razorpay, #486 Cybersource, #487 connectIPS; foundation #489 (`certificate`
setting type, `GatewayCredentialExpiring`/`Expired`, unique `(gateway, provider_ref)` on `payment_attempts`, return lookup by
provider ref, step-up refusals keep no old input on any `password.recent` form) with follow-ups #490, #495 (broken drivers
reported and hidden, not fatal), #496, #501 (flaky tests); drivers #491 connectIPS, #492 Cybersource, #493 Razorpay; #497 (a
pending refund matched by its provider id is settled; Stripe books only `succeeded` refunds; a released refund's id moves to
`raw.mohar.released_provider_refund_id`); #500 (settle guard sends a paid result without currency or amount to staff review;
`ReturnHint`/`PaymentAttemptRef::$signedCancel`, stored on the attempt); #494 (Arch rules for the whole app as a text scan:
the arch plugin parsed all of `app/` and ran out of 1 GB); #488 (`scripts/pr-screenshots.sh`: screenshots in PR descriptions on
`refs/pr-assets/pr-<n>`, owner rule). Issues closed by hand: #154, #30, #24.

Open follow-ups from the gateway wave: ADR 0010 `kernel:api-snapshot` and `Kernel::API_VERSION` still do not exist;
`SignedOutcome` enum if a third signed signal appears; a Stripe simulator pending refund scenario; connectIPS `.pfx` from a
real NCHL may use legacy encryption OpenSSL 3 refuses (clear message shown).

Billing follow-ups, merged 2026-10-25: #503 (admin Billing → Credit notes list with filters, note page and resend; new
permission `billing.tax_notes.resend`, the seeded Billing role has it; kernel: event `TaxNoteResendRequested`), #504 (P6b:
invoice and quote PDFs attached to their emails through `BillingDocuments` `INVOICE`/`QUOTE`; quote PDF keyed by a content
digest; "attached" text only when the file is attached; a failed render sends the mail without it; renewal query budget 47),
#505 (a failed Stripe refund already booked goes to Disputes to review, kernel `CallbackEvent::REFUND_FAILED`; data-only
migration `2026_11_03_300800` moves released refund ids into `raw`). Issues closed by hand: #483, #29, #498, #499.

Wave merged 2026-10-29: #510 (P2c: `.np` wording, own out-of-currency cart message, one registrar account read per call;
kernel `DomainNameRules::registeredByClient`), #511 (P18: deploy prunes only this project's recorded images, never live or
previous; Debian trixie-20261020 base), #512 (spec: unpaid invoice and domain import, phase 3; build starts with PR 0),
#513 (P12a: `kernel:api-snapshot`, `billing/docs/architecture/kernel-api.txt`, `Kernel::API_VERSION` 0.1.0, `config_fields`
removed; production `dns_opt` timeout 2 attempts 2), #514 (#506: note PDF claim, renewal budget and seed memory flakes),
#515 (P13: export step-up decided for the query user, future confirmation times refused, one Carbon clock, PDF header cap,
`App\Support\Reports\ReportConformance` with a query budget), #520 (P4: mail-down warnings, invitation password
fingerprint, unverified logins refused on add, owner transfer and merge, a first invitation replaces the password, password
reset ends other sessions, sign-up survives mail down), #522 (security M1/M3: one wrong-password budget per login for
step-up, password change and account deletion; PDF download limits). Docs: #509, #517, #518, #519, #521. Issues closed by
hand: #506, #203, #267, #158, #68, #354, #367, #431, #443, #97, #171.

Rule since 10-29: every open kernel PR merges develop and runs `php artisan kernel:api-snapshot`; a re-review follows every
fix round on money and auth PRs (CTO review).

No agents running. No open PRs. Open follow-ups: #507 (a "succeeded" refund racing a failed report); #516 (test `objects`
disk survives runs); #523 (password-guess budget not atomic, audit `client_id`, L3 step-up for provisioning secrets);
#406 and #244 (re-pin caddy/composer after 10-29 18:13 UTC, k6 10-31, postgres 11-02, Node 11-04); #67, #70, #83 (P12a
leftovers); #503 review leftovers (invoice-number search index, per-note resend cooldown, email-log link); #520 leftovers
(pending-attach flow for unverified logins, mail send inside the first-invitation transaction); #511 leftover (rollback with
mutable tags); a scheduled `check-dependency-age.mjs --all` job; `product-vision.md` claims to mark "planned" (AI,
OpenTelemetry, p95 under 200 ms, i18n/WCAG). Owner decisions open: `symfony/mime` 8.1.10 and Node 24.22.1 cooldown
exceptions; lift "simulators only" for eSewa and Khalti; Nepal SMS provider and panel order after cPanel; import spec Q2
(ResellerClub per-client customer) blocks its PR 4.
Next (from the 10-29 reviews): import PR 0 (ADR 0019 contracts), mail transport settings with test send, frontend i18n ADR,
month-end reports pack, public API design.

Walkthrough: `docs-site` Overview and Get started → Walkthrough (12 steps, run against a seeded app). Before a demo
start Mailpit (`docker compose up -d` in `billing/`) and `composer dev` (now includes the scheduler).

Deferred, not yet in the issue plan (add to packages when they are next planned):
- #464 re-review: no test for B2's amount-differs branch; flagging a late success overwrites the staff note in
  `description`.
- #468 sweep, not fixed: no Reports section beyond the registers; NPR-default exchange rates read "Rs 1 = $0.006582"
  (standard wants "$1 = Rs 151.94") and INR comes from the ECB; ticket-status and taxed-item settings are typed keys
  (needs a multi-select setting type, P7a); client invoice has no seller/bill-to block with PAN; `domains.invoice_lead_days`
  falls back to a missing billing setting (always 14); customer pages default to the product favicon; new-ticket
  "Send request" disabled with no reason; guest cart drops after sign-in with no discount line; Change plan preselects
  the current plan with a refund preview; a paid order stays Pending until Accept order.
- #462 security re-check, low: a single quoted inner value over 64 KB masks the rest of the line (over-masks only).

After each merge close the issue by hand (`develop` ignores closing keywords). Signed-off PRs must be marked ready:
CI skips drafts without the `ci` label. Review follow-ups go into the open PR's fix round or a planned package, not
new issues; a PR merges when nothing blocks, except money, auth and data deletion, which keep the strict loop (owner,
2026-10-24). Wave plan and deferred items: `docs/handoff/issue-plan.md`.

- Data-changing steps to warn the owner about before any real install: migrations `2026_10_23_500700` (#423),
  `2026_10_24_400750` (#453, deletes duplicate report lines), `2026_10_24_000110` (#459, re-keys or demotes keyless
  system roles); `billing:fiscal-vat-split`; #460's retention tasks delete old rows on the first daily run;
  `support:recompute-unanswered --since=<deploy start>` after a blue-green deploy (#450).
- Data-changing since: migration `2026_10_25_100600` (#478) renames groups whose slug the store now reserves (to `<slug>-plans`,
  logged); `app:install` resets the Billing system role to add the two register export permissions (#480).
- Data-changing since: migration `2026_11_01_300780` adds `invoices.print_count` (#482); #489's migration adds the unique provider-ref index (duplicates resolved first: the paid attempt keeps the reference, losers keep it in `raw` and go to review) and a Core `integration_credentials` table.
- Next: VAT PR 6 and 8 if planned, P6b (extend `BillingDocuments`, do not add `InvoiceDocuments`), #483 admin Credit notes list
  with resend, P2c remainder, P13 (issue plan).
- Deferred from this wave (add to packages): #480 accept only csv/pdf in the export route, throw on unknown layout, drop dead
  `can.export`, Nepali IRD header labels (kernel change), release note that Billing-role staff can export buyer PANs; #478
  `/domains/search` drops `?currency=` on redirect, reserved-slug test from registered routes, Change plan wording for staff,
  discount label percentage; #481 screenshots show old labels, unchanged Quick links card; #482 shared default objects in
  `IdentifiesOccurrence`, a render failure fails all three mail attempts, no `kernel:api-snapshot` check yet (ADR 0010);
  #474 recorded-credit refund should name the payment; #471 target's ResellerClub customer wins on merge.
- Owner decisions open from this wave: Nepali labels in the IRD CSV header (kernel change), accountant on whether the emailed
  note PDF counts as a print, keeping both emails on a staff refund (kept for now).
- Namecheap is merged but not enabled. Before enabling it: a sandbox check of the not-in-account error numbers (2016166, 2019166), the `.us`,
  `.ca` and `.eu` extension details and the transfer texts (simulator README "verify" list).

## Open owner questions

- VAT (from #464, with an accountant): does the register keep every stretch the mode was on; does the note register's
  Total exclude other tax; are foreign-tax-only documents "exempt".
- Docker cleanup on the Mac (proposed 10-24, not run): build cache (~9.9 GB), dangling images (~1.4 GB),
  `mohar-k286-php:local` (~1 GB). Keep `mohar-pg`, its volume and Mailpit; other projects' images untouched.
- Nepali tax invoices: CBMS / electronic billing rules for VAT-registered hosts, and fiscal-year numbering (ask an
  accountant).
- connectIPS merchant enrolment (weeks of lead time); a WHMCS dev licence or fake-data export for importer fixtures; a
  cPanel trial VPS.
- Namecheap `.ca` and `.eu`: offered as built (a wrong detail name makes the registrar refuse the order, nothing is
  bought), or kept off until a sandbox check.
- Repository settings: Actions write for the token (re-running flaky jobs). Auto-merge and delete head branches are on
  (2026-10-23).
- Five behaviours in #211 ("Owner to confirm"), now merged with the conservative choice; confirm or change.
- Bcrypt cost against sign-in bursts on a 2 vCPU host: #433 measured a 1.3 to 1.8 s sign-in p50 when 28 users sign in at
  once. Lowering the cost speeds sign-in and weakens stored hashes; a security trade-off for the owner.
- Business: licence and price in NPR, support model, security policy page, trademark search.

## Open issues worth knowing

Go-live (label `go-live`): issues #11 and #126 are closed (#11 by PR #242, #126 by PR #282), so none is open from the earlier list. The 40-user tail latency (#205) is a known performance finding without the label. Full list: `gh issue list --label go-live`.

## Scheduled

- Nothing scheduled.

## Local-only state on the codespaces

Not in git; does not exist on a new device. On the Mac: Homebrew PHP 8.4.26 (upgrade to 8.4.27 when bottled), Homebrew Composer, global Playwright 1.64.1,
Mailpit in `compose.yaml`; screenshots via `scripts/screenshot.mjs`. Not carried over: `rea/`, the Stripe CLI login,
Composer auth and `~/.npmrc` (log in again locally). The codespace list below is history:

- Worktrees in `.worktrees/` (recreate with `scripts/new-worktree.sh <slug>`, or `git worktree add .worktrees/<slug>
  feature/<slug>` for an existing branch). `.worktrees/_review/`: screenshot and preview helpers.
- The owner's preview on port 8000 (`database/preview.sqlite`, started by the `resume-session` skill).
- `rea/`: the REA reverse-engineering toolkit, installed per user on 10-17; used once (10-21) to capture the eSewa
  UAT sandbox for #213; never pointed at WHMCS files (`CLAUDE.md`, Boundary).
- Agent transcripts under `~/.claude/projects/-workspaces-whmcs/` (needed to resume agents in place; on a new device,
  relaunch from `.claude/agents/` with the brief template in the `run-workstream` skill).
- Main site extras copied from the original codespace on 10-22: the Stripe CLI login (`~/.stripe`, `~/.config/stripe`),
  Composer auth, `~/.npmrc`. The repository is cloned at `/workspaces/mohar`, with `/workspaces/whmcs` linked to it so
  existing paths work; PHP is switched to 8.4 (`~/.php/current`), since new codespaces default to 8.3.
- Published design review page: https://claude.ai/artifact/9zNAXTVDgoF8juYYZ6wnZM (private to the owner).
