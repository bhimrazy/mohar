# Current state

Last updated: 2026-10-20 06:55 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- `develop` contains wave 1 plus Billing follow-ups (#22), Nepal payments Khalti and eSewa (#26), product polish (#38),
  image optimisation (#40), CI fixes (#46, #56), database hardening (#53), the host-header security fix (#59),
  Currencies (#43), the production install path (#57), Dashboard v2 (#41), kernel contracts PR A (#61), provisioning on the kernel PR B (#62), Notifications (#28), docs-only CI pushes (#66), kernel PR B2 with encrypted server settings (#65), the test-memory fix (#75), ADR 0016 simulators (#74), the deployment study (#76) the cPanel/WHM driver 1a (#69) and 1b (#73), and email direction A with the product-sweep fixes (#72), Stripe (#58), deployment D1 (#79), invitations and template defaults (#78), the periodic-review security fixes (#89), the WHM simulator and the cPanel driver against it (S2, #100), the cPanel import page (step 3a-2, #101), the storefront marketing spec (#104), the home page design (#105), ADR 0018 sign-in methods (#106) and the simulator command fix (#109).
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

## Open pull requests

| PR   | Workstream                                       | Status (2026-10-20 06:55 UTC)                                                        |
| ---- | ------------------------------------------------ | ------------------------------------------------------------------------------------- |
| #80  | Deployment D2: production compose, smoke test    | Ready, CI green, smoke 11/11 on GitHub. Board and security review running.            |
| #111 | Deployment D3: blue-green, migration check        | Draft. First blue-green smoke run next; then D4 (image workflow, cPanel cron mode).   |
| #108 | Load test and sizing guide (stacked on #80)      | Draft. Small profile passed; larger profiles and the guide follow.                    |
| #91  | CI speed-up                                      | Draft, CI green. Revision in progress (target under 10 minutes, timeout back to 15).  |
| #94  | Storefront home page, direction A                | Draft, conflicts with develop. Product-review fixes in progress, then product re-check. |
| #99  | Admin > Website (stacked on #94)                 | Draft. After #94.                                                                     |
| #95  | cPanel import, step 3b (choose, review, import)  | Draft. 3b-1 (digest-bound, queued apply) in progress, then 3b-2; re-checks after.     |
| #113 | Simulators S3: Stripe simulator                  | Draft, retargeted to develop after S2 merged. Architect and security review running.  |
| #112 | Sign-in PR 0: kernel methods and step-up         | Draft, conflicts with develop. Architect and security review running.                |

Not in a PR yet: simulators S4 end-to-end (`feature/simulators-s4`), cPanel PR 2 (SSO, package select, server health).
Parked, no owner: `fix/automation-redelivery` (ADR 0017 scheduler WIP) and `feature/payments-stripe-saved-methods`
(design revision 3). A branch audit (Fable) decides whether to keep them.

## Design and research in progress

| Workstream                | Branch                         | State                                                                                                                                                                                                                                                                                                            |
| ------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home page directions      | `design/home-page`             | Owner prefers A (Storefront), 2026-10-18. Designer revises A once against the marketing spec; then build.                                                                                                                                                                                                        |
| Storefront marketing spec | `feature/storefront-marketing` | Product manager: competitor and conversion research, `docs/product/storefront-marketing.md`. Designer revises against it.                                                                                                                                                                                        |
| Deployment                | `feature/deploy-options`       | Fable advisor studying runtimes (nginx+FPM, FrankenPHP, Octane), proxies (Traefik, Caddy), hosts (VPS, PaaS, Kamal/Coolify, cPanel shared) and the deploy-files layout; then the DevOps engineer builds dev/prod Dockerfiles, compose files and a manually dispatched image-build workflow; then a Fable review. |

## Running now (2026-10-20 06:55 UTC)

- The session stopped overnight (2026-10-19 16:09 to 2026-10-20 06:36 UTC). On resume: pushed two worktrees'
  unpushed commits, snapshotted the import engineer's staged work to `wip/import-apply-v2-snapshot`, merged #100 and
  #101, retargeted #113, and resumed the seven interrupted agents.
- Builds: storefront #94/#99 fixes, import 3b #95, simulators S4, deployment D3 #111, CI speed-up #91.
- Reviews: #80 (board, security), #112 (architect + security), #113 (architect + security); a Fable branch audit
  of the 83 GitHub branches (52 already merged into develop).
- Periodic review round 2: CEO and CTO (Fable) running; security and architecture skipped because every PR merged
  since round 1 had its own security and architecture or board review. Schedule recreated at 02:17 and 14:17.

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
