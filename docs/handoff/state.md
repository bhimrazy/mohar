# Current state

Last updated: 2026-10-19 13:00 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- `develop` contains wave 1 plus Billing follow-ups (#22), Nepal payments Khalti and eSewa (#26), product polish (#38),
  image optimisation (#40), CI fixes (#46, #56), database hardening (#53), the host-header security fix (#59),
  Currencies (#43), the production install path (#57), Dashboard v2 (#41), kernel contracts PR A (#61), provisioning on the kernel PR B (#62), Notifications (#28), docs-only CI pushes (#66), kernel PR B2 with encrypted server settings (#65), the test-memory fix (#75), ADR 0016 simulators (#74), the deployment study (#76) the cPanel/WHM driver 1a (#69) and 1b (#73), and email direction A with the product-sweep fixes (#72).
- Progress toward "a product a host would switch to": about 52/100 (independent Fable review said 50 on 2026-10-18; +2 for the install path). Core
  logic is strong (251/287 P0 parity items built or partial), but there are no control-panel drivers, no real
  registrars, no international gateway, no production install, and nothing has run outside the codespace.
- The milestone that moves the number most: a fresh PostgreSQL install via the install command, a real cPanel/WHM
  account created from an order, paid through Stripe, on a server the owner provides.

## Repository

- GitHub `bhimrazy/whmcs` (private). `develop` = integration, `main` = release (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Local setup: `billing/docs/devops/local-setup.md`.
  Production install and upgrade (`php artisan app:install`): `billing/docs/devops/install.md`.
  Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`.

## Open pull requests (merge order: #77 S0, then S1; email follow-up after its review; deploy and #58 independent)

| PR  | Workstream     | Status                                                                                                                                              |
| --- | -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| #58 | Stripe gateway | Round-1 fixes pushed; short re-check pending. Merge needs the owner's Stripe test keys for the sandbox run. Saved methods (PR 2) built after merge. |

## Design and research in progress

| Workstream                | Branch                         | State                                                                                                                                                                                                                                                                                                            |
| ------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home page directions      | `design/home-page`             | Owner prefers A (Storefront), 2026-10-18. Designer revises A once against the marketing spec; then build.                                                                                                                                                                                                        |
| Storefront marketing spec | `feature/storefront-marketing` | Product manager: competitor and conversion research, `docs/product/storefront-marketing.md`. Designer revises against it.                                                                                                                                                                                        |
| Deployment                | `feature/deploy-options`       | Fable advisor studying runtimes (nginx+FPM, FrankenPHP, Octane), proxies (Traefik, Caddy), hosts (VPS, PaaS, Kamal/Coolify, cPanel shared) and the deploy-files layout; then the DevOps engineer builds dev/prod Dockerfiles, compose files and a manually dispatched image-build workflow; then a Fable review. |

## Running now (2026-10-19 13:20 UTC)

- Builds: email follow-up (template auto-update, invitation token); deployment D1; simulators S1 (stacked on #77);
  cPanel PR 2 (`feature/provisioning-cpanel-2`); storefront home page direction A (remote agent, `feature/storefront-home`).
- Reviews: #77 S0 re-check; #58 Stripe re-review; periodic round 1 (CEO, CTO, security, architecture → `docs/reviews/`).
- Periodic review round every 12 hours (`operating-model.md`, "Periodic reviews").

## Next (in order, from the Fable review and the parity roadmap)

1. cPanel PR 1a (#69), then 1b, 2 (SSO, package select, server health), 3 (import); cPanel/WHM driver in progress on `feature/provisioning-cpanel` (stacked on #65, recorded fixtures until the owner provides a WHM server) (each with PostgreSQL CI green).
2. CI runs on drafts carrying a `ci` label; every review verdict states "PostgreSQL run: yes/no".
3. Done: #47 install path merged in #57. Still open: `billing/docs/devops/production.md` and `.env.production.example`; a CI smoke job installs on fresh
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

- Approve deferring social sign-in, Affiliates and the client dashboard/profile work behind the switch blockers.
- Test accounts: not available (owner, 2026-10-18). Integrations are verified against local simulators; real smoke runs wait for real accounts.
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
