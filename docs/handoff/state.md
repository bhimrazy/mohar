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

Signed off and in the merge queue (`scripts/merge-queue.sh`, which merges at the signed head once CI
passes and refreshes changelog-only conflicts): #429 VAT PR 2 (staff issue, settle and reverse tax notes), #428 VAT
PR 6 (VAT and tax-note registers, Reports kernel items, closes #403), #433 load-test arrivals spread (the #205
cause: every k6 user signed in in the same second; the runner load test passed; `sizing.md` tables to be updated from
that run). Signed off, queued next: #435 CalendarDate follow-ups (Domains typed, runtime Carbon-task baseline,
ASCII-only format; closes #426).

| PR   | Workstream                                                                                  | Status (2026-10-24)                                                         |
| ---- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| #432 | VAT PR 3a: Nepal VAT mode settings, VAT/export classification (flag `features.billing.nepal_vat_mode`, hidden until VAT PR 4) | Fix round 2; the architect approved.                                        |
| #128 | Gateway expansion spec                                                                      | Draft. Owner questions in the PR.                                           |

After each merge close the issue by hand (`develop` ignores closing keywords). Signed-off PRs must be marked ready:
CI skips drafts without the `ci` label.

- Queue after these: VAT PR 3b (old paths: `Invoicer::cancel` callers, automation, `CancelInvoiceItems`,
  `LateFeeApplied`), then VAT PRs 4, 5 and 7; follow-ups after #432 (#428 sums VAT only from flagged levels, #429
  notes copy their invoice's flags); #430 (covered by #432); #431 (report query budget, conformance kit); #436 (last
  Domains `todayDate` callers); #418 default country picker; #402, #407, #414 follow-ups; #244 Node and Debian images
  (after 10-28); the admin dashboard above its 200 ms target (from #205).
- Namecheap is merged but not enabled. Before enabling it: a sandbox check of the not-in-account error numbers (2016166, 2019166), the `.us`,
  `.ca` and `.eu` extension details and the transfer texts (simulator README "verify" list).
- Changelog fragments (`billing/changelog.d/`, assembled at release; owner decision 2026-10-23, #397).

## Open owner questions

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
