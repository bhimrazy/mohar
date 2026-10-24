# Current state

Last updated: 2026-10-24 by the CTO session, on the owner's Mac. Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: merges older than the current day move to `billing/docs/progress.md`, and history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **62/100** (CEO review 2026-10-22,
  `docs/reviews/2026-10-22-ceo.md`; rubric in `docs/reviews/readme.md`; the round-3 score on 10-20 was 53). Merges
  after that review are not scored yet; the next CEO round scores them. CTO engineering score: 63/100
  (`docs/reviews/2026-10-22-cto.md`).
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

Merged 2026-10-24 (details in `billing/docs/progress.md` at the next update): #438, #440, #444–#461, #463 (P3b server
import features), #464 (VAT PR 4c: credit refunds, Nepal VAT mode flag lifted, demo seed has the mode on), #465–#469
(handoff docs; develop fix for the #459/#461 scan; docs-site Overview and Walkthrough; pre-walkthrough sweep;
walkthrough VAT step and `schedule:work` in `composer dev`). Issues closed by hand.

Session wrapped on the owner's request 2026-10-24 (walkthrough ready). No agents running.

| PR   | Workstream                                    | Status and next step (2026-10-24)                                                                 |
| ---- | --------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| #462 | P1b Redaction log backstop (#254, #383)       | Signed off at `7d41f4e4` after two security rounds; merge when CI passes, close #254 and #383.     |
| #128 | Gateway expansion spec                        | Draft. Owner questions in the PR.                                                                 |

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
- Next: VAT PR 5 and 7, P6a/P6b (VAT PR 4c is merged), P7a settings controls, P2c, P5c, P8c, P13 (issue plan).
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
