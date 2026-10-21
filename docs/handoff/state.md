# Current state

Last updated: 2026-10-21 08:10 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **53/100** (CEO review round 3, 2026-10-20 afternoon,
  `docs/reviews/2026-10-20-ceo-round-3.md`; rubric in `docs/reviews/readme.md`). The merges below have not been scored
  yet; the next CEO round scores them.
- Merged on 2026-10-21: the client importer is complete (#184 confirm and apply, #201 review decisions, report and
  errors CSV; #160–#163 earlier), `.np` registered by clients with the host's nameservers (#199), Nepal-first install
  defaults (#215), billing reversals and the client lock order (#211), eSewa fixes from a REA capture of the UAT
  sandbox (#213), the load-test harness and sizing guide (#108), the npm advisory fix (#206), audits only on
  dependency changes (#210, #214), and the process review (#204). Details: `billing/docs/progress.md`.
- Owner decisions in force:
  - **Nepal is the main market.** Clients register `.np` themselves; Mohar shows the host's nameservers.
  - The repository is **public** and named **`bhimrazy/mohar`**; `main` is a readme-and-licence page, all rights
    reserved.
  - Subagents run on Opus (money, auth, kernel, security, re-checks) or Sonnet (docs, mechanical fixes) by task
    (10-21; Fable's weekly limit resets 10-25).
  - Security audits block only dependency changes; no nightly audit while `main` is the default branch (10-21).
  - KYC (#197): staff approve documents; off by default; documents deleted 90 days after the account closes (10-21).
  - Invoices use AD dates; an optional "BS beside AD" switch later (#207); BS fiscal-year numbering waits for an
    accountant's answer (10-21).
- Load test on the GitHub runner (small profile 2 vCPU / 4 GB, 50k clients; `billing/docs/devops/sizing.md`): p95
  424 ms at 40 users, billing day 285 s, no errors. Tail latency at 40 users (dashboard p95 5.1 s, sign-in p50
  2.4 s) is the remaining performance finding.
- The milestone that moves the number most: a Nepali host's switch path end to end on `develop`. Import clients and
  `.np` guidance are merged; next are eSewa and Khalti verified live, and a real cPanel/WHM account.

## Repository

- GitHub `bhimrazy/mohar` (public; the old name `bhimrazy/whmcs` redirects). `develop` = integration, `main` = release
  landing page (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5, vite-plus 1.1.0). Local setup:
  `billing/docs/devops/local-setup.md` and the docs-site page "Run locally". Production install:
  `billing/docs/devops/install.md`.
- Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`. Process:
  `docs/handoff/operating-model.md`, `docs/handoff/review-rules.md`.

## Open pull requests

| PR   | Workstream                                            | Status (2026-10-21 08:10 UTC)                                  |
| ---- | ----------------------------------------------------- | -------------------------------------------------------------- |
| #167 | Deployment D4: image build workflow, cPanel cron mode | Ready; board items fixed and verified; merges when CI is green |
| #188 | Local merge gate script                               | Draft. Needed only while the repository is private.            |
| #112 | Sign-in PR 0                                          | Draft. To be cut to the ADR 0018 §4 fix, or closed.            |
| #128 | Gateway expansion spec (connectIPS, Fonepay, cards)   | Draft. Owner questions in the PR.                              |

## Running now (2026-10-21 08:10 UTC; budget: load below 8, 2 GB free, disk below 85%)

- Code: KYC (#197); billing go-live fixes (#223 deposit refund, #212 Stripe inquiries, #222 late sweep spacing, #224
  overpayment deadlock probe); ADR 0021 and the 0012 and 0018 addenda with the registry rename (#219).
- Queue, in order: #218 (Core client edit changes the currency of a client with records, go-live); importer B2
  (invitations, opening balances, tax-ID warnings); Khalti and eSewa simulators from captures (#208, low); #112 cut;
  kernel PR C before ResellerClub; #190 Redactor gaps; #216, #217, #221, #225 follow-ups; the tail-latency
  performance work (#205).

## Open owner questions

- Nepali tax invoices: CBMS / electronic billing rules for VAT-registered hosts, and fiscal-year numbering (ask an
  accountant).
- connectIPS merchant enrolment (weeks of lead time); a WHMCS dev licence or fake-data export for importer fixtures; a
  cPanel trial VPS.
- Repository settings: auto-merge, delete head branches, and Actions write for the token (re-running flaky jobs).
- Five behaviours in #211 ("Owner to confirm"), now merged with the conservative choice; confirm or change.
- Business: licence and price in NPR, support model, security policy page, trademark search.

## Open issues worth knowing

99 open. Go-live (label `go-live`): #25 key rotation, #218 client currency edit, #223 deposit refund, #190
Redactor gaps, the 40-user tail latency (#205). Full list: `gh issue list --label go-live`.

## Scheduled

- On or after 2026-10-22: bump `laravel/framework` to 13.36.x and `laravel/wayfinder` to 0.1.22 (#11).

## Local-only state on the current codespace

Not in git; does not exist on a new device:

- Worktrees in `.worktrees/` (recreate with `scripts/new-worktree.sh <slug>`, or `git worktree add .worktrees/<slug>
  feature/<slug>` for an existing branch). `.worktrees/_review/`: screenshot and preview helpers.
- The owner's preview on port 8000 (`database/preview.sqlite`, started by the `resume-session` skill).
- `rea/`: the REA reverse-engineering toolkit, installed per user on 10-17; used once (10-21) to capture the eSewa
  UAT sandbox for #213; never pointed at WHMCS files (`CLAUDE.md`, Boundary).
- Agent transcripts under `~/.claude/projects/-workspaces-whmcs/` (needed to resume agents in place; on a new device,
  relaunch from `.claude/agents/` with the brief template in the `run-workstream` skill).
- Published design review page: https://claude.ai/artifact/9zNAXTVDgoF8juYYZ6wnZM (private to the owner).
