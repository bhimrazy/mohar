# Current state

Last updated: 2026-10-21 06:10 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **53/100** (CEO review round 3, 2026-10-20 afternoon,
  `docs/reviews/2026-10-20-ceo-round-3.md`; rubric in `docs/reviews/readme.md`). The next CEO round scores the
  merges below.
- Owner decisions in force: **Nepal is the main market**; clients register `.np` themselves and Mohar shows the
  host's nameservers (#199); the repository is **public** and renamed **`bhimrazy/mohar`** (10-21), `main` is a
  readme-and-licence page, all rights reserved; subagents run on Opus or Sonnet by task (10-21; Fable's weekly
  limit resets 10-25); the client-importer recommendations
  are accepted; a basic KYC module is queued (#197).
- Merged since 2026-10-20 16:00: client importer A0–A3 (#160–#163: kernel contracts, upload, mapping, review),
  registrar prerequisites (#164), the chargeback credit fix (#181, H1 from the security round), renewals
  performance (#189), the simulator port race (#198, closes #182), worktrees from a fresh `origin/develop` (#200),
  the run-locally docs page (#202), Admin > Website (#99), plus docs: state after merges (#193), review round 3 (#173),
  `main` landing page (#179), process docs (#180, #186, #187).
- Load test on the GitHub runner (small profile, 2 vCPU / 4 GB, 50k clients; #108 comment 2026-10-21): p95 102 ms at
  5 VUs, 424 ms at 40 VUs, billing day 285 s, renewals 39/s, queue drain 11,525 jobs in 64 s, no errors. Tail latency
  at 40 VUs (dashboard p95 5.1 s, sign-in p50 2.4 s) is the remaining performance finding.
- The milestone that moves the number most: a Nepali host's switch path end to end on `develop`: import clients
  (#184/#201), `.np` guidance (#199), pay by eSewa or Khalti, a real cPanel/WHM account.

## Repository

- GitHub `bhimrazy/mohar` (public; the owner switches visibility as needed; the old name `bhimrazy/whmcs` redirects).
  `develop` = integration, `main` = release landing page (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Local setup: `billing/docs/devops/local-setup.md`
  and the docs-site page "Run locally". Production install and upgrade: `billing/docs/devops/install.md`.
- Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`. Process:
  `docs/handoff/operating-model.md`, `docs/handoff/review-rules.md`.

## Open pull requests

| PR   | Workstream                                             | Status (2026-10-21 06:10 UTC)                                                                 |
| ---- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| #184 | Client importer B1a: confirm and apply                 | Draft. Engineer finishing the combined-review fixes (account-takeover rules, one lock, event). |
| #201 | Client importer: review decisions, report, errors CSV  | Draft, stacked on #184. Same engineer.                                                        |
| #199 | `.np` registered by the client; nameservers; search    | Draft. Fixes pushed with probes adopted (0a4fbbb5); re-check running.                         |
| #108 | Load test harness, host profiles, sizing guide         | Draft. Board: changes requested (missing `sizing.md`, drift guards); DevOps engineer fixing.  |
| #188 | Local merge gate script                                | Draft. Needed only while the repository is private.                                           |
| #167 | Deployment D4: image build workflow, cPanel cron mode  | Draft. Queued for review.                                                                     |
| #112 | Sign-in PR 0                                           | Draft. To be cut to the ADR 0018 §4 fix.                                                      |
| #128 | Gateway expansion spec (connectIPS, Fonepay, cards)    | Draft. Owner questions in the PR.                                                             |

Process review PR (this branch): `docs/reviews/2026-10-21-process-and-agents.md`.

## Running now (2026-10-21 06:10 UTC; budget: load below 8, 2 GB free, disk below 85%)

- Code: importer #184/#201 fixes; DevOps #108 fixes.
- Review: re-check of #199.
- Queue, in order: #199 CI and merge; #184/#201 re-check (combined, plus architect on the kernel event), CI, merge;
  #108 re-check and merge; importer B2 (invitations, opening balances, tax-ID warnings); KYC #197 (after the owner
  confirms staff review, retention and default-on); #188 review; #167 D4; #112 cut; kernel PR C before ResellerClub;
  #190 Redactor gaps; #170 Nepal install defaults; #191, #192, #194, #195, #203; the Bikram Sambat question.
- Cleanup when the work lands: delete `wip/client-importer-apply-snapshot` and `wip/np-self-registered-snapshot`;
  reconcile the MySQL portability line in `architecture.md`.

## Open owner questions

- Nepali tax invoices: Bikram Sambat dates and BS fiscal-year numbering? Electronic billing / CBMS rules for
  VAT-registered hosts?
- KYC (#197): staff review of documents, retention period, on by default?
- connectIPS merchant enrolment (weeks of lead time); a WHMCS dev licence or fake-data export for importer fixtures;
  a cPanel trial VPS.
- Repository settings: auto-merge, delete head branches, required CI check on `develop`; the branch cleanup list.
- Business: licence and price in NPR, support model, security policy page, trademark search.

## Open issues worth knowing

95 open. Go-live blockers: #25 (key rotation), the 40-VU tail latency (#108 comment), #190 (Redactor gaps). Importer
follow-ups: #134–#136, #203 (`.np` minors). Nepal: #27, #170. Full list: `gh issue list`.

## Scheduled

- On or after 2026-10-22: bump `laravel/framework` to 13.36.x and `laravel/wayfinder` to 0.1.22 (#11).

## Local-only state on the current codespace

Not in git; does not exist on a new device:

- Worktrees in `.worktrees/` (recreate with `scripts/new-worktree.sh <slug>`, or `git worktree add .worktrees/<slug>
  feature/<slug>` for an existing branch). `.worktrees/_review/`: screenshot and preview helpers.
- The owner's preview on port 8000 (`database/preview.sqlite`, started by the `resume-session` skill).
- `rea/`: the REA reverse-engineering toolkit, installed per user on 10-17; not used by the build and never pointed
  at WHMCS files (`CLAUDE.md`, Boundary).
- Agent transcripts under `~/.claude/projects/-workspaces-whmcs/` (needed to resume agents in place; on a new device,
  relaunch from `.claude/agents/` with the brief template in the `run-workstream` skill).
- Published design review page: https://claude.ai/artifact/9zNAXTVDgoF8juYYZ6wnZM (private to the owner).
