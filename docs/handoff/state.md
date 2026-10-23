# Current state

Last updated: 2026-10-22 20:30 UTC by the CTO session, on the owner's Mac (the codespace hit its usage limit). Update this file whenever a PR merges, a workstream starts or
stops, or the owner makes a decision. Keep it short: merges older than the current day move to `billing/docs/progress.md`, and history goes in `history.md` and `docs/reviews/`.

## Summary

- Progress toward "a product a host would switch to": **62/100** (CEO review 2026-10-22,
  `docs/reviews/2026-10-22-ceo.md`; rubric in `docs/reviews/readme.md`; the round-3 score on 10-20 was 53). Merges
  after that review are not scored yet; the next CEO round scores them. CTO engineering score: 63/100
  (`docs/reviews/2026-10-22-cto.md`).
- Merged on 2026-10-22, last (after 17:20 UTC): the ResellerClub simulator (#332) and driver (#335), a timed-out gateway
  refund kept pending (#336, closes #334), Khalti "Continue payment" (#328, #251), the Nepal-first demo seed (#330,
  #39), flaky-test fixes (#326, #331), the VAT register and credit-note spec (#327), ADR 0024/0025 and ADR 0013
  Amendment 1 (#329), registrar prep (#325), module-engineer on Sonnet (#333).
- Merged on 2026-10-22, later (after the 10:30 UTC update): registrar contracts and drivers on the kernel (#262, #264,
  #265; ADR 0009 PR C1 to C3), Laravel 13.36 (#242), the local merge gate (#188), macOS tooling (#271), deploy smoke
  runs the full seed (#282), credit ledger idempotency key (#283), KYC image re-encode (#286), its backfill (#314),
  browser resize (#312) and unlock races (#308), optional BS dates on invoices (#293), invitation rate default 100
  an hour (#291), step-up window from `AUTH_STEP_UP_SECONDS` (#296) and downloads that ask for the password (#299),
  Redactor void-container fix (#294), logout clears browser history (#304), `lock_timeout` for migration DDL
  (#306), key-list dedupe (#300), Arch rule guards (#301), PostgreSQL test names within 63 bytes (#297), Stripe
  smoke route guard (#295), `develop` built on every push and E2E only on `main` pushes (#319), the CEO and CTO
  reviews (#315, #318; CTO engineering score 63/100), sizing and restore docs (#320), the Khalti and eSewa simulators (#321, closes #208), and the owner decisions
  (#289) and pause state (#270) docs.
- Merged on 2026-10-22 on the Mac: #266 hosted resume (#123), #256 chargebacks (#232, #230, #221), #259 importer B2b
  opening balances, #268 password.recent in the kernel (#81), #276 Arch rules one per target (#275; Pest bug
  pestphp/pest#1751), #253 key rotation (#25), #255 importer B2a invitations, #252 Redactor gaps (#190). #112 closed
  (its §4 landed in #268; §1/§5 left for social sign-in).
- Merged on 2026-10-22 (codespace): #237 client currency lock, #246 e2e in NPR, #248 currency code immutable, #249 KYC
  history cleared, #261 server-import lock, #260 install requires a shared lock store (#64), #245 two-site docs.
- Merged on 2026-10-22: the PHP 8.4.27 security patch for CVE-2026-109618/109619 through FrankenPHP 1.13.2, by
  security exception (#243). Client KYC (#228, #231) merged on 2026-10-21; see the KYC rule below.
- Merged on 2026-10-21: the client importer is complete (#184 confirm and apply, #201 review decisions, report and
  errors CSV; #160–#163 earlier), `.np` registered by clients with the host's nameservers (#199), Nepal-first install
  defaults (#215), billing reversals and the client lock order (#211), eSewa fixes from a REA capture of the UAT
  sandbox (#213), deployment D4 (#167), ADR 0021 row locks (#227), billing go-live fixes (#229), the e2e fix (#234), the load-test harness and sizing guide (#108), the npm advisory fix (#206), audits only on
  dependency changes (#210, #214), and the process review (#204). Details: `billing/docs/progress.md`.
- Owner decisions in force:
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
- App: `billing/` (Laravel 13.36, PHP 8.4, Inertia 3, React 19, Pest 5, vite-plus 1.1.0). Local setup:
  `billing/docs/devops/local-setup.md` and the docs-site page "Run locally". Production install:
  `billing/docs/devops/install.md`.
- Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`. Process:
  `docs/handoff/operating-model.md`, `docs/handoff/review-rules.md`.

## Open pull requests and running work

| PR             | Workstream                                                | Status (2026-10-22 20:30 UTC)                                    |
| -------------- | --------------------------------------------------------- | ---------------------------------------------------------------- |
| #337           | Company clock (VAT PR 0a, ADR 0013 Amendment 1)           | Fix round 1 done; architect and re-reviewer re-checking.         |
| not yet opened | TLD attributes (ADR 0009 Amendment 2), ResellerClub first | Building. Kernel change called out in the PR; architect reviews. |
| not yet opened | Shared overlap guard for domain jobs (#14)                | Building. A blocked job is released, never dropped.              |
| #128           | Gateway expansion spec                                    | Draft. Owner questions in the PR.                                |
| this PR        | Handoff docs update                                       | Open.                                                            |

Follow-up issues from #321: #323 (a signed eSewa COMPLETE followed by NOT_FOUND is abandoned, not reviewed);
#322 (flaky `UnlockRacesTest`) is fixed (#326).

After each merge close the issue by hand (`develop` ignores closing keywords). When resolving Markdown conflicts, run
`npx vp check --fix` before pushing (a hand-merged changelog failed CI on #252).

- #280 stays open on purpose: TLD attributes (in flight) are its last part.
- Queue, in order: VAT PRs 0b, 0c, 1a… after #337; #338 (link an existing ResellerClub order); an ADR for
  indeterminate provider outcomes (refunds, registrations) and ADR 0013 Amendment 2; issue triage (close stale follow-ups);
  #241 vite-plus 1.3.0 (from 10-23 06:00 UTC); #254 Redactor follow-ups and ADR 0023 (the CTO review's redesign
  after two fix rounds); #267, #216, #225, #273, #274; #244 Node and Debian images (after 10-28); tail latency
  (#205). Done today and off the queue: #233, #126, #278, #279, #277, #263, #250, #257, #258, #269, #272, #208.
- Proposed, awaiting the owner: changelog fragments (`billing/changelog.d/<pr>.md`, assembled at release) to end
  changelog conflicts and the extra CI runs they cause (CTO review 2026-10-22, recommendation 2).

## Open owner questions

- Nepali tax invoices: CBMS / electronic billing rules for VAT-registered hosts, and fiscal-year numbering (ask an
  accountant).
- connectIPS merchant enrolment (weeks of lead time); a WHMCS dev licence or fake-data export for importer fixtures; a
  cPanel trial VPS.
- Changelog fragments: adopt them or keep one `changelog.md` (proposed, see Queue).
- Repository settings: auto-merge, delete head branches, and Actions write for the token (re-running flaky jobs).
- Five behaviours in #211 ("Owner to confirm"), now merged with the conservative choice; confirm or change.
- Business: licence and price in NPR, support model, security policy page, trademark search.

## Open issues worth knowing

Go-live (label `go-live`): issues #11 and #126 are closed (#11 by PR #242, #126 by PR #282), so none is open from the earlier list. The 40-user tail latency (#205) is a known performance finding without the label. Full list: `gh issue list --label go-live`.

## Scheduled

- From 2026-10-23 06:00 UTC: vite-plus 1.3.0 (#241). The Laravel 13.36 bump (#11) merged in #242.

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
