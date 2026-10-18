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

None. PR #22 (Billing follow-ups: exact quote conversion #20, 90-day demo billing history) merged
2026-10-18 04:52 UTC as `45441e3`; its non-blocking review items are issue #23.

## In progress

| Workstream            | Lead               | Branch / worktree                                     | Spec                                     | State on 2026-10-18 04:55 UTC                                                                                        |
| --------------------- | ------------------ | ----------------------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Notifications         | module engineer    | `feature/notifications`, `.worktrees/notifications`   | `product/notifications.md`               | Uncommitted work only (untracked files); no commits, branch not pushed, no PR.                                       |
| Nepal payment drivers | module engineer    | `feature/payments-nepal`, `.worktrees/payments-nepal` | `product/payments-nepal.md`              | Commits on the branch (hosted-checkout hardening, Khalti, eSewa), only part of them pushed; no PR. ConnectIPS later. |
| Dashboard v2          | principal designer | `feature/dashboard-v2`, `.worktrees/dashboard-v2`     | `design/dashboard-v2.md` (to be written) | Started; no commits yet. Owner request.                                                                              |

Notifications reuses the Themes mail layout (`resources/views/vendor/mail`). Dashboard v2 brings the real admin
dashboard up to the Calm Fintech direction: charts and sparklines, 12-month income against the previous year,
orders by type, income by currency, automation health. Engineers commit and push after each milestone and open a
draft PR into `develop` when their gates pass.

## Next

1. Wave 2, when a build slot frees (at most two engineers at a time):
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
