# Current state

Last updated: 2026-10-18 08:20 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- `develop` contains wave 1 plus Billing follow-ups (#22), Nepal payments Khalti and eSewa (#26), product polish (#38),
  image optimisation (#40), CI race fix (#46) and database hardening (#53).
- Progress toward "a product a host would switch to": about 50/100 (independent Fable review, 2026-10-18). Core
  logic is strong (251/287 P0 parity items built or partial), but there are no control-panel drivers, no real
  registrars, no international gateway, no production install, and nothing has run outside the codespace.
- The milestone that moves the number most: a fresh PostgreSQL install via the install command, a real cPanel/WHM
  account created from an order, paid through Stripe, on a server the owner provides.

## Repository

- GitHub `bhimrazy/whmcs` (private). `develop` = integration, `main` = release (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Local setup: `billing/docs/devops/local-setup.md`.
  Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`.

## Open pull requests (merge order: #43 → #41 → #28)

| PR  | Workstream    | Size          | Status                                                                                                                                                                             |
| --- | ------------- | ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| #43 | Currencies    | +6.7k / −0.5k | Board, architecture and re-review passed; rebased on #53; 1703 tests on SQLite and PostgreSQL. Product review running. Merges next.                                                |
| #41 | Dashboard v2  | +5.1k / −0.6k | Board fixes and DB-audit fixes done; architecture approved. Rebases on #53 and #43 after #43 merges, then re-review.                                                               |
| #28 | Notifications | +12.8k        | Too large (split future work). Engineer is redesigning secret-link emails as structure-only (B1). Needs a security sign-off on B1 and a PostgreSQL run before merge. Rebases last. |

## In progress

| Workstream                    | Branch / worktree                                 | State                                                                 |
| ----------------------------- | ------------------------------------------------- | --------------------------------------------------------------------- |
| Production install path (#47) | `feature/install-path`, `.worktrees/install-path` | Module engineer building `mohar:install`; must commit and push early. |

## Next (in order, from the Fable review and the parity roadmap)

1. Merge #43, then #41, then #28 (each with PostgreSQL CI green).
2. CI runs on drafts carrying a `ci` label; every review verdict states "PostgreSQL run: yes/no".
3. Finish #47 plus `billing/docs/devops/production.md` and `.env.production.example`; a CI smoke job installs on fresh
   PostgreSQL without demo data.
4. ADR 0009 (kernel extension contracts), only PRs A and B, minimal, built together with the first real consumer.
5. cPanel/WHM driver: short spec, then build against ADR 0009 PR B; test against a real WHM server from the owner,
   or recorded fixtures until one is available.
6. Stripe gateway on the existing gateway contracts (no kernel change), in parallel.
7. ADR 0013 steps 1–2 (company clock: `core.timezone`, automation in company time), with a Kathmandu test.
8. Then: registrars (ADR 0009 PR C, `.np` manual, ResellerClub), Razorpay/PayPal, WHMCS importer.

Deferred, pending owner approval: social sign-in, Affiliates, client dashboard and profile (#31, #32). None
is a switch blocker in the parity studies.

## Decisions pending from the owner

- Email template direction: A, B or C (design review page, Emails section).
- Approve deferring social sign-in, Affiliates and the client dashboard/profile work behind the switch blockers.
- Test accounts: a WHM/cPanel server, a Stripe test account, a Khalti merchant sandbox (to verify the refund unit).
- Repository settings: branch protection and required checks on `develop` and `main`.
- Business: licence and price model, support model, published security policy, trademark search for "Mohar".

## Scheduled

- On or after 2026-10-22: bump `laravel/framework` to 13.36.x and `laravel/wayfinder` to 0.1.22 (#11).

## Open issues worth knowing

Go-live blockers: #47 (install path), #25 (key rotation). Data: #48–#52. Product sweep: #31–#37, #42. Integrations:
#27 (Nepal follow-ups), #29 (PDF attachments), #30 (Support contract), #44 (credential reveal), #45 (historical
rates), #55 (TLD race). Tests and CI: #21, #24, #54. Full list: `gh issue list`.

## Local-only state on the current codespace

Not in git; does not exist on a new device:

- Worktrees in `.worktrees/` (recreate with `scripts/new-worktree.sh <slug>`, or `git worktree add .worktrees/<slug>
feature/<slug>` for an existing branch).
- `.worktrees/_review/`: screenshot and preview helpers (Playwright scripts, theme artifact builder).
- `rea/`: reverse-engineering tool checkout used at the start; not needed for the build.
- Agent transcripts under `~/.claude/projects/-workspaces-whmcs/` (needed to resume agents in place; on a new device,
  relaunch from `.claude/agents/` with the brief template in the `run-workstream` skill).
- Published design review page: https://claude.ai/artifact/9zNAXTVDgoF8juYYZ6wnZM (private to the owner).
