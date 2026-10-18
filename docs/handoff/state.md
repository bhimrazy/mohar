# Current state

Last updated: 2026-10-18 by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- Wave 1 is merged into `develop`: Core, Platform admin, Catalog, Domains, Support, Services &
  Provisioning, Orders, Billing, Themes + Mohar brand, DevOps (Postgres CI, dependency policy). Billing
  follow-ups (PR #22) merged after it.
- Full suite: 1469 tests passing on SQLite and PostgreSQL in CI at the PR #22 head, now merged into `develop`.
- Last progress estimate given to the owner: about 68/100, before Orders merged. Remaining weight is
  wave 2–3 modules, E2E, observability and an integration and security pass.

## Repository

- GitHub: `bhimrazy/whmcs` (private). `develop` = integration, `main` = release (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Setup:
  `billing/docs/devops/local-setup.md`.

## Open pull requests

Merged: #26 (Nepal payments), #38 (product polish), #40 (image optimisation), #46 (CI draft race). Planned order: #53 (DB hardening) → #43 → #41 → #28. `database-reviewer` and `principal-architect` gates apply (see operating-model.md). Architecture review and ADRs 0009–0014 (Proposed) in `billing/docs/architecture/` and `billing/docs/adr/`.

| PR  | Workstream             | Status (2026-10-18 06:05 UTC)                                                                                                                      |
| --- | ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| #26 | Nepal payments         | Board, security and re-review passed. Combined QA, design and product review running; merges after it.                                             |
| #43 | Currencies             | Gates green on SQLite and PostgreSQL. Engineer applying CTO decisions (NRB rate type, market-rate seeds, manual-rate stale warning). Reviews next. |
| #41 | Dashboard v2           | Gates green. Designer fixing NPR to "Rs", historical-rate "All" total, Slate chart colours, duplicate counters. Reviews next.                      |
| #28 | Notifications          | Engineer applying CTO decisions (auth mail via events, no passwords, dedup by event id, Reply-To, From name, subject style). Reviews next.         |
| #38 | Product polish sweep 1 | Review board running.                                                                                                                              |

Merged since the last update: #40 (doc images optimised with sharp, 50 MB → 18 MB, CI image size check).

## In progress

| Workstream            | Lead               | Branch / worktree                                     | Spec                                     | State on 2026-10-18 04:55 UTC                                                                                                                                                   |
| --------------------- | ------------------ | ----------------------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Notifications         | module engineer    | `feature/notifications`, `.worktrees/notifications`   | `product/notifications.md`               | Uncommitted work only (untracked files); no commits, branch not pushed, no PR.                                                                                                  |
| Nepal payment drivers | module engineer    | `feature/payments-nepal`, `.worktrees/payments-nepal` | `product/payments-nepal.md`              | Commits on the branch (hosted-checkout hardening, Khalti, eSewa), only part of them pushed; no PR. ConnectIPS later.                                                            |
| Dashboard v2          | principal designer | `feature/dashboard-v2`, `.worktrees/dashboard-v2`     | `design/dashboard-v2.md` (to be written) | Started; no commits yet. Owner request.                                                                                                                                         |
| Currencies            | module engineer    | `feature/currencies`, `.worktrees/currencies`         | `product/platform-admin.md` §6           | Started 05:10 UTC. Symbols instead of codes next to amounts ($, ₹, Rs; lakh grouping) and exchange-rate sources, schedule, safety limit, history, price preview. Owner request. |
| WHMCS parity study    | three researchers  | none (docs only)                                      | `billing/docs/parity/*.md`               | Started 05:15 UTC. Matrices of every WHMCS feature, setting, API, hook and module contract with our status and priority. Owner request.                                         |

Notifications reuses the Themes mail layout (`resources/views/vendor/mail`). Dashboard v2 brings the real admin
dashboard up to the Calm Fintech direction: charts and sparklines, 12-month income against the previous year,
orders by type, income by currency, automation health. Every user-facing PR now also gets a `product-reviewer` review, and develop gets a product sweep before
progress is reported to the owner (`product-standards.md`). Engineers commit and push after each milestone and open a
draft PR into `develop` when their gates pass.

## Next

Queued until load allows (the codespace has been at load 5–7 with five agents): PR #38 review, social sign-in,
client dashboard and client profile (#31, #32, after Dashboard v2), Affiliates, Registrars, then wave 3 from the roadmap.

The full plan after wave 2 is in `billing/docs/parity/roadmap.md` (waves 3 and 4, from the WHMCS parity
studies). The items below are the near-term queue.

1. Wave 2, when a build slot frees (at most two engineers at a time):
   - **Social sign-in** (owner request 2026-10-18; raised from P2 in the parity study). Admin → Settings →
     Sign-in integrations: per-provider enable, client ID and secret (write-only, encrypted), callback URL
     to copy. Google first, then GitHub, Facebook, X and LinkedIn, all via first-party `laravel/socialite`
     (to be added at a version at least 7 days old). Microsoft and Apple later via a provider registry.
     Client sign-up and login, link and unlink from the profile. Accounts are matched only by a verified
     email, with confirmation, to prevent takeover. Staff SSO optional, restricted by domain, with 2FA
     still enforced.
   - Affiliates (`product/affiliates.md`), worktree `.worktrees/affiliates` exists.
   - Registrars (`product/registrars.md`): prerequisites PR first (C1 remote ids, C2 `findSubmission`
     reconcile, C3 stale-claim sweep = issue #12, C5 redaction gaps), then the `.np` manual driver,
     ResellerClub (needs C4 `registrar_refs`), Namecheap. Worktree `.worktrees/registrars` exists.
2. Wave 3: Reports, AI module (`laravel/ai` 1.0.1; check current Claude API constraints with the
   `claude-api` skill first), WHMCS importer (API-only connector, `import:whmcs`).
3. After wave 2: integration build with screenshots of every area; security architecture pass.

## Tracked gaps (no plan yet)

- Observability: no OpenTelemetry package, config or spec (named in `billing/docs/product-vision.md`).
- Browser E2E: Pest browser plugin chosen but `pestphp/pest-plugin-browser` is not installed.
- Billing invoice and quote PDFs do not read `BrandProvider` for name and logo.
- Branch protection and required checks on `develop`/`main` are unverified (the API returns 403; owner action).

## Scheduled

- On or after 2026-10-22: bump `laravel/framework` to 13.36.x and `laravel/wayfinder` to 0.1.22 (#11,
  security fixes; held back by the 7-day cooldown).

## Open issues

#11 framework bump · #12 Domains stuck-pending recovery · #13 Domains needs_review after sync ·
#14 Domains shared overlap guard · #15 Domains small hardening · #16 Catalog follow-ups ·
#18 Support guest key rotation on merge ·
#21 test-suite memory growth (phpunit `memory_limit` raised to 1G as a stopgap) ·
#23 PR #22 review follow-ups · #24 CI job time grew to 3.5–5.5 min.

## Decisions pending from the owner

None open.

## Local-only state on the current codespace

These are not in git and do not exist on a new device:

- Worktrees in `.worktrees/` (re-create with `scripts/new-worktree.sh <slug>` or
  `git worktree add .worktrees/<slug> feature/<slug>` for an existing branch).
- `.worktrees/_review/`: screenshot and preview helpers (Playwright scripts, theme artifact builder).
- `rea/`: reverse-engineering tool checkout used at the start; not needed for the build.
- Agent transcripts under `~/.claude/projects/-workspaces-whmcs/` (needed to resume agents in place).
- Published design pages, private to the owner's account: "Theme Directions"
  https://claude.ai/artifact/DqKtfBu3KVCoNVJdFoBTSs and the Dashboard v2 comparison
  https://claude.ai/artifact/9zNAXTVDgoF8juYYZ6wnZM.
