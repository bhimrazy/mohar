# Current state

Last updated: 2026-10-19 15:05 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- `develop` contains wave 1 plus Billing follow-ups (#22), Nepal payments Khalti and eSewa (#26), product polish (#38),
  image optimisation (#40), CI fixes (#46, #56), database hardening (#53), the host-header security fix (#59),
  Currencies (#43), the production install path (#57), Dashboard v2 (#41), kernel contracts PR A (#61), provisioning on the kernel PR B (#62), Notifications (#28), docs-only CI pushes (#66), kernel PR B2 with encrypted server settings (#65), the test-memory fix (#75), ADR 0016 simulators (#74), the deployment study (#76) the cPanel/WHM driver 1a (#69) and 1b (#73), and email direction A with the product-sweep fixes (#72).
- Progress toward "a product a host would switch to": 55/100 by evidence (CEO review, 2026-10-19,
  `docs/reviews/2026-10-19-ceo.md`): the score moves only when work is merged and proven. Built-but-unproven work
  (simulators, the image, Stripe) is tracked separately; including it gives about 62.
- The milestone that moves the number most: a fresh PostgreSQL install via the install command, a real cPanel/WHM
  account created from an order, paid through Stripe, on a server the owner provides.

## Repository

- GitHub `bhimrazy/whmcs` (private). `develop` = integration, `main` = release (not yet cut).
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Local setup: `billing/docs/devops/local-setup.md`.
  Production install and upgrade (`php artisan app:install`): `billing/docs/devops/install.md`.
  Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`.

## Open pull requests (merge order: #77 S0, then S1; email follow-up after its review; deploy and #58 independent)

| PR  | Workstream | Status |
| --- | ---------- | ------ |

## Design and research in progress

| Workstream                | Branch                         | State                                                                                                                                                                                                                                                                                                            |
| ------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home page directions      | `design/home-page`             | Owner prefers A (Storefront), 2026-10-18. Designer revises A once against the marketing spec; then build.                                                                                                                                                                                                        |
| Storefront marketing spec | `feature/storefront-marketing` | Product manager: competitor and conversion research, `docs/product/storefront-marketing.md`. Designer revises against it.                                                                                                                                                                                        |
| Deployment                | `feature/deploy-options`       | Fable advisor studying runtimes (nginx+FPM, FrankenPHP, Octane), proxies (Traefik, Caddy), hosts (VPS, PaaS, Kamal/Coolify, cPanel shared) and the deploy-files layout; then the DevOps engineer builds dev/prod Dockerfiles, compose files and a manually dispatched image-build workflow; then a Fable review. |

## Running now (2026-10-19 14:15 UTC)

- Builds: security fixes #89; cPanel import step 1 #87 (in review) and step 2; deployment
  D1 #79 and D2 #80; CI speed-up #91 (urgent: CI exceeds 15 min, stopgap #93); simulators S1 #92 and S2; storefront home
  page (`feature/storefront-home`).
- Reviews: #87 (security, board+database, product); #92 S1 (restarts on the rebased head).
- Merged today: #61–#77 kernel, cPanel 1a/1b, emails A, S0, reviews and studies. Stripe (#58, fixture-tested, not live-verified), deployment D1 (#79) and invitations/template defaults (#78) merged; CI limit 25 min (#93).
- Periodic review round every 12 hours (`operating-model.md`, "Periodic reviews"). Round 1 ran 2026-10-19 13:20 UTC
  (`docs/reviews/2026-10-19-*.md`); the 14:20 and 14:47 rounds were skipped: everything merged since round 1 (S0 #77, Stripe #58,
  deployment D1 #79, CI timeout #93, docs) had its own security and architecture or board review before merging. Next round at the 02:17 slot.

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

Social sign-in: next free engineer (owner, 2026-10-19). Deferred: Affiliates, client dashboard and profile (#31, #32). None
is a switch blocker in the parity studies.

## Decisions pending from the owner

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
