# Current state

Last updated: 2026-10-22 08:45 UTC (14:30 NPT) by the CTO session. Work moved from the codespace (usage limit) to the owner's Mac. Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **53/100** (CEO review round 3, 2026-10-20 afternoon,
  `docs/reviews/2026-10-20-ceo-round-3.md`; rubric in `docs/reviews/readme.md`). The merges below have not been scored
  yet; the next CEO round scores them.
- Merged on 2026-10-22 (later): #237 client currency lock, #246 e2e in NPR, #248 currency code immutable, #249 KYC
  history cleared, #261 server-import lock, #260 install requires a shared lock store (#64), #245 two-site docs.
- Merged on 2026-10-22: the PHP 8.4.27 security patch for CVE-2026-109618/109619 through FrankenPHP 1.13.2, by
  security exception (#243), and client KYC: uploads plus staff review by Full Administrators only (#228, #231).
- Merged on 2026-10-21: the client importer is complete (#184 confirm and apply, #201 review decisions, report and
  errors CSV; #160–#163 earlier), `.np` registered by clients with the host's nameservers (#199), Nepal-first install
  defaults (#215), billing reversals and the client lock order (#211), eSewa fixes from a REA capture of the UAT
  sandbox (#213), deployment D4 (#167), ADR 0021 row locks (#227), billing go-live fixes (#229), the e2e fix (#234), the load-test harness and sizing guide (#108), the npm advisory fix (#206), audits only on
  dependency changes (#210, #214), and the process review (#204). Details: `billing/docs/progress.md`.
- Owner decisions in force:
  - **Nepal is the main market.** Clients register `.np` themselves; Mohar shows the host's nameservers.
  - The repository is **public** and named **`bhimrazy/mohar`**; `main` is a readme-and-licence page, all rights
    reserved.
  - Subagents run on Opus (money, auth, kernel, security, re-checks) or Sonnet (docs, mechanical fixes) by task
    (10-21; Fable's weekly limit resets 10-25).
  - Security audits block only dependency changes; no nightly audit while `main` is the default branch (10-21).
  - KYC (#197): Owner and Admin approve documents; off by default; documents deleted 90 days after the account
    closes (10-21).
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

| PR   | Workstream                                            | Status (2026-10-21 18:20 UTC)                                  |
| ---- | ----------------------------------------------------- | -------------------------------------------------------------- |
| #188 | Local merge gate script                               | Draft. Needed only while the repository is private.            |
| #112 | Sign-in PR 0                                          | Draft. To be cut to the ADR 0018 §4 fix, or closed.            |
| #128 | Gateway expansion spec (connectIPS, Fonepay, cards)   | Draft. Owner questions in the PR.                              |

## Paused work, resuming on the owner's Mac (since 2026-10-22 08:45 UTC)

The codespace `mohar-codespace-2` is shut down (disk kept until 2026-11-21). Every branch was pushed; nothing
reached GitHub after the pause. Resume each step below with a fresh agent (the stopped agents cannot be resumed on
a new machine). The Mac runs the main checkout with `MOHAR_ROOT` set in `.claude/settings.local.json`.

| PR | Where it stopped | Next step |
| --- | --- | --- |
| #252 Redactor gaps (#190) | Fix round done at d8f3dfc8 | Second re-check (allow-list secret probes, linearity of fail-closed scan, void elements), then merge |
| #266 hosted resume (#123) | Fix round done at 2af1c49e; gates pass | Merge `origin/develop` (has #260, needed for N1/P4), re-reviewer re-check, merge |
| #256 chargebacks (#232, #230, #221) | Fix round done | Re-reviewer re-check, merge |
| #255 importer B2a invitations | Fix round done | Re-reviewer re-check, merge |
| #259 importer B2b opening balances | Fix round done | Re-check; unfinished probes on branch `wip/recheck-259-probes` |
| #268 password.recent kernel (#81) | Opened | Combined review + principal-architect gate |
| #253 key rotation (#25) | Second fix round (N1 orphan image, N2 secret seeding, N3 overlapping runs, N4 `--only`) written, gates not run | Run gates, re-check |
| #262/#264/#265 registrar contracts (PR C) | C2 review fixes in progress, `wip` commit 74c5fb60 on `feature/kernel-registrars-domains` | Finish C2 after #252 merges, then re-review |
| #242 Laravel 13.36 (#11) | Cooldown clears 13:25 UTC 10-22 | Add `ci`, merge on green (the scheduled watcher stops with the codespace) |

After each merge close the issue by hand (`develop` ignores closing keywords).

- Queue, in order: #241 vite-plus 1.3.0 (from 10-23 06:00 UTC); #233 KYC EXIF stripping; #126 deploy smoke seed;
  #254 Redactor follow-ups and ADR 0023; #263 credit ledger idempotency key; #267, #269, #250, #257, #258, #216, #225;
  #244 Node and Debian images (after 10-28); #208 simulators (low); tail latency (#205). The periodic review round
  is overdue. The owner's preview on port 8000 is behind `develop`.
- Owner questions from importer B2: how Nepali hosts invite migrated clients today; whether 500 invitations per
  hour is right; whether a missing PAN is a warning or blocks the import.

## Open owner questions

- Nepali tax invoices: CBMS / electronic billing rules for VAT-registered hosts, and fiscal-year numbering (ask an
  accountant).
- connectIPS merchant enrolment (weeks of lead time); a WHMCS dev licence or fake-data export for importer fixtures; a
  cPanel trial VPS.
- Repository settings: auto-merge, delete head branches, and Actions write for the token (re-running flaky jobs).
- Five behaviours in #211 ("Owner to confirm"), now merged with the conservative choice; confirm or change.
- Business: licence and price in NPR, support model, security policy page, trademark search.

## Open issues worth knowing

99 open. Go-live (label `go-live`): #25 key rotation, #218 client currency edit (#237), #238 currency code rename, #190
Redactor gaps, the 40-user tail latency (#205). Full list: `gh issue list --label go-live`.

## Scheduled

- On or after 2026-10-22: bump `laravel/framework` to 13.36.x and `laravel/wayfinder` to 0.1.22 (#11).

## Local-only state on the codespaces

Not in git; does not exist on a new device. Not yet carried to the Mac: `rea/`, `.worktrees/_review/` helpers, the
Stripe CLI login, Composer auth and `~/.npmrc` (log in again locally, or copy from the shut-down codespace):

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
