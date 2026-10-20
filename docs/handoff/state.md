# Current state

Last updated: 2026-10-20 16:00 UTC by the CTO session. Update this file whenever a PR merges, a workstream starts
or stops, or the owner makes a decision. Keep it short: history goes in `history.md`.

## Summary

- `develop` contains wave 1 plus Billing follow-ups (#22), Nepal payments Khalti and eSewa (#26), product polish (#38),
  image optimisation (#40), CI fixes (#46, #56), database hardening (#53), the host-header security fix (#59),
  Currencies (#43), the production install path (#57), Dashboard v2 (#41), kernel contracts PR A (#61), provisioning on the kernel PR B (#62), Notifications (#28), docs-only CI pushes (#66), kernel PR B2 with encrypted server settings (#65), the test-memory fix (#75), ADR 0016 simulators (#74), the deployment study (#76) the cPanel/WHM driver 1a (#69) and 1b (#73), and email direction A with the product-sweep fixes (#72), Stripe (#58), deployment D1 (#79), invitations and template defaults (#78), the periodic-review security fixes (#89), the WHM simulator and the cPanel driver against it (S2, #100), the cPanel import page (step 3a-2, #101), the storefront marketing spec (#104), the home page design (#105), ADR 0018 sign-in methods (#106), the simulator command fix (#109), the CI speed-up (#91), the import-page re-review fixes (#119), local DB options (#125), automation redelivery (#82 via #122: `automation` queue connection, unique jobs, `automation:release-locks`), the CI minutes budget (#131) cPanel import step 3b-1 (#95) and 3b-2 (#133: per-account client/product/cycle/due-date choices, continuing a stopped import, flags for self-registered and recently changed client emails), the production compose stack (#80, D2: four workers, Caddy log redaction, 6 GB minimum), the Stripe simulator (#113, S3), the end-to-end hosting lifecycle `simulate:e2e` (#115, S4, opt-in CI job) and the docs site (#137, Astro Starlight in `docs-site/`, unpublished).
- Progress toward "a product a host would switch to": 46/100 by evidence (CEO review round 2, 2026-10-20,
  `docs/reviews/2026-10-20-ceo.md`), the weighted sum of its rubric. Correction: the 55 reported on 2026-10-19 did not
  match its own table, which sums to 38; the like-for-like movement since is +8 (cPanel driver proven against the WHM
  simulator, the deploy smoke runs, the import page, a first load test). One number from now on: the weighted sum.
- Load test (50k clients, #108): far off the p95 200 ms target. Admin dashboard p50 28.5 s, invoice search 3.8 s,
  renewals 20/s; the billing day took 546 s, #82 (automation job redelivered after 240 s) is fixed in #122; the response-time findings are still open.
- The milestone that moves the number most: a fresh PostgreSQL install via the install command, a real cPanel/WHM
  account created from an order, paid through Stripe, on a server the owner provides.

## Review round 3 (2026-10-20 afternoon): `docs/reviews/2026-10-20-{ceo-round-3,cto-round-3,security,architecture}.md`

- Score **53/100** by the CEO rubric (from 46): install 60, integrations 44, migration 25, proof 65.
- Owner decisions today: **Nepal is the main market**; the repository is switched between public and private as
  needed, with CI on GitHub while public and the local merge gate while private; licence all rights reserved, `main`
  kept as a readme-and-licence landing page; all 12 client-importer recommendations
  accepted; 4-vCPU codespace kept.
- Security: no blockers. High #172 (chargeback and overpayment refunds leave credit spendable) is being fixed.
- Architecture: kernel growth is small, but agreed orderings were skipped. ADR 0020 (hot-path reads) is Proposed. The
  registrar PR must use the kernel `ReconcileStatus`. Step-up is still owned by Platform (#112 to be cut to ADR 0018 §4).
  The company clock (ADR 0013) is needed for Nepal.
- Open owner questions:
  - do Nepali tax invoices need Bikram Sambat dates and BS fiscal-year numbering?
  - electronic billing / CBMS rules for VAT-registered hosts;
  - connectIPS merchant enrolment (weeks of lead time);
  - a WHMCS dev licence or a fake-data export;
  - a cPanel 15-day trial on a VPS.

## Repository

- GitHub `bhimrazy/whmcs` (visibility switched by the owner as needed; see operating-model.md, "Repository visibility
  and CI"). `develop` = integration, `main` = landing page (readme and licence); release not yet cut.
- App: `billing/` (Laravel 13.34, PHP 8.4, Inertia 3, React 19, Pest 5). Local setup: `billing/docs/devops/local-setup.md`.
  Production install and upgrade (`php artisan app:install`): `billing/docs/devops/install.md`.
  Shared PostgreSQL for tests: `scripts/pg.sh`. Storage cleanup: `scripts/storage-cleanup.sh`.

## Open pull requests

| PR   | Workstream                                       | Status (2026-10-20 12:00 UTC)                                                                  |
| ---- | ------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| #111 | Deployment D3: blue-green, migration check       | Draft. Carries `worker-automation` from #80. Next: board review, one smoke run.                 |
| #108 | Load test and sizing guide                       | Draft, retargeted to develop after #80.                                                         |
| #94  | Storefront home page, direction A                | Draft. Product re-check of 9350ef84, CTO sign-off on the seeder changes.                         |
| #99  | Admin > Website (stacked on #94)                 | Draft. After #94.                                                                               |
| #112 | Sign-in PR 0                                     | Draft. To be cut to the #81 fix (social sign-in deferred behind registrars).                     |
| #120 | Client import spec                               | Draft. Owner questions in the PR.                                                               |
| #128 | Gateway expansion spec (connectIPS, Fonepay, …)  | Draft. Owner questions in the PR.                                                               |
| #129 | Integration test matrix for payment gateways     | Ready. Docs.                                                                                    |

In progress, no PR yet: #130 late payment booking (`feature/late-payment-booking`), #148 client origin for the import
flags (`feature/client-origin`, must land before the import is offered to hosts). D4 (image workflow, cPanel cron mode)
is local on `.worktrees/deploy-d4`.

Kept: `feature/payments-stripe-saved-methods` (design revision 3, to be ported to develop in a small docs PR before
the Stripe saved-cards work).

## Design and research in progress

| Workstream                | Branch                         | State                                                                                                                                                                                                                                                                                                            |
| ------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home page directions      | `design/home-page`             | Owner prefers A (Storefront), 2026-10-18. Designer revises A once against the marketing spec; then build.                                                                                                                                                                                                        |
| Storefront marketing spec | `feature/storefront-marketing` | Product manager: competitor and conversion research, `docs/product/storefront-marketing.md`. Designer revises against it.                                                                                                                                                                                        |
| Deployment                | `feature/deploy-options`       | Fable advisor studying runtimes (nginx+FPM, FrankenPHP, Octane), proxies (Traefik, Caddy), hosts (VPS, PaaS, Kamal/Coolify, cPanel shared) and the deploy-files layout; then the DevOps engineer builds dev/prod Dockerfiles, compose files and a manually dispatched image-build workflow; then a Fable review. |

## Running now (2026-10-20 12:00 UTC; budget 4 agents: 2 code, 2 review; no launch while load > 6)

- Code: #130 late payment booking; #148 client origin.
- Queue, in order: #130 reviews (board, security) and merge; #148 review and merge; D3 (#111) board review and one
  smoke run; matrix tests M-01..M-15 (#129); client importer build after the spec (#120); performance workstream from the
  load test; #112 cut to the #81 fix; move the owner preview to PostgreSQL; lockfile and dependency audit; follow-ups
  #134–#136, #139–#147, #149, #150.
- Owner actions asked:
  - eSewa happy-path payment in the UAT sandbox (CAPTCHA, cannot be automated);
  - confirm a $0 Actions budget (3,000 min/month cap);
  - approve the GitHub branch cleanup (Fable audit: 54 delete, 6 archive as tags, 16 keep);
  - repository settings: "Allow auto-merge", "Automatically delete head branches", required CI check on `develop`;
  - after-the-fact approval of the early unpinned tool runs and installs (10-17/18), listed in the security note;
  - a long-lived restricted Stripe test key as a Codespaces or Actions secret, for Stripe drift recordings;
  - answers in #120 (client import) and #128 (gateways).
- Lesson (10-20): after the reboot two agents ran commands in the main checkout because their worktree was gone and an
  unchained `cd` failed. Nothing was pushed; both were reverted. Briefs now require `cd <dir> && …`.

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

Go-live blockers: #25 (key rotation), #130 (late payment booking), the load-test performance findings. Import follow-ups: #134–#136. Data: #48–#52. Product sweep: #31–#37, #42. Integrations:
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
