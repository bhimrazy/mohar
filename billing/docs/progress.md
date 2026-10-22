# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright against a running instance).

| Workstream                                        | Owner (agent)           | Branch / PR            | Status          | Notes                                                                                                                                                                   |
| ------------------------------------------------- | ----------------------- | ---------------------- | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Foundation & Core                                 | CTO                     | `develop`              | ✅ merged       | shared kernel, contracts v2 (ADR 0008)                                                                                                                                  |
| Design system & shared UI                         | Senior Product Designer | `develop`              | ✅ merged       | 15 components                                                                                                                                                           |
| Product specs (14 modules)                        | PMs + architects        | `develop`              | ✅ merged       | `docs/product/*.md` incl. AI, migration, Nepal payments, registrars                                                                                                     |
| DevOps: Postgres + CI                             | DevOps Engineer         | #1                     | ✅ merged       | SQLite + PostgreSQL in CI                                                                                                                                               |
| Dependency policy + CI minutes                    | DevOps Engineer         | #9                     | ✅ merged       | exact pins, ≥7-day cooldown, ~2 min/run                                                                                                                                 |
| Domains                                           | Senior Engineer         | #5                     | ✅ merged       | 171 tests; follow-ups #12–#15                                                                                                                                           |
| Catalog                                           | Senior Engineer         | #4                     | ✅ merged       | 477 suite tests at merge; follow-up #16                                                                                                                                 |
| Platform admin + security tier                    | Senior Engineer         | #3                     | ✅ merged       | 628 suite tests at merge                                                                                                                                                |
| Support                                           | Senior Engineer         | #6                     | ✅ merged       |                                                                                                                                                                         |
| Services & Provisioning                           | Senior Engineer         | #7                     | ✅ merged       |                                                                                                                                                                         |
| Orders                                            | Senior Engineer         | #8                     | ✅ merged       | 1464 suite tests at merge                                                                                                                                               |
| Billing                                           | Senior Engineer         | #10                    | ✅ merged       | security + money integrity + GST tax invoice; follow-ups PR #22                                                                                                         |
| Themes module + Mohar brand                       | Principal Designer      | #17                    | ✅ merged       | Calm Fintech default, custom themes, brand settings                                                                                                                     |
| Billing follow-ups                                | Senior Engineer         | #22                    | ✅ merged       | exact quote conversion (#20), demo history; follow-ups #23                                                                                                              |
| Notifications                                     | Senior Engineer         | #28                    | ✅ merged       | branded mail layout, templates, webhooks; private links only to their owner                                                                                             |
| Email direction A (Notifications follow-up)       | Senior Engineer         | #72                    | ✅ merged       | two-card layout for every email, framework mail through templates, sweep fixes; screenshots in `docs/screenshots/notifications/direction-a/`                            |
| Affiliates                                        | Senior Engineer         | —                      | ⏳ wave 2       |                                                                                                                                                                         |
| Nepal payment drivers (Khalti, eSewa, ConnectIPS) | Senior Engineer         | #26                    | ✅ merged       | Khalti and eSewa; ConnectIPS later; follow-ups #27                                                                                                                      |
| Dashboard v2                                      | Principal Designer      | #41                    | ✅ merged       | KPI cards, charts, Automation page summary                                                                                                                              |
| Product polish sweep 1                            | Product reviewer        | #38                    | ✅ merged       |                                                                                                                                                                         |
| Database hardening (audit 1)                      | Database reviewer       | #53                    | ✅ merged       | 55 indexes, paging fix in automation                                                                                                                                    |
| Currencies and exchange rates                     | Senior Engineer         | #43                    | ✅ merged       | symbols, lakh grouping, rate sources, reviews, converted prices                                                                                                         |
| Production install path                           | Senior Engineer         | #57                    | ✅ merged       | `app:install`, reference-data installers                                                                                                                                |
| Kernel contracts (ADR 0009 PR A)                  | Senior Engineer         | #61                    | ✅ merged       | outbound address guard, Redactor, SettingField, driver registry                                                                                                         |
| Provisioning on kernel (ADR 0009 PR B, B2)        | Senior Engineer         | #62                    | 🔍 in review    | base for the cPanel/WHM driver                                                                                                                                          |
| Stripe gateway                                    | Senior Engineer         | #58                    | ✅ merged       | fixture-tested, not live-verified; simulator in S3                                                                                                                      |
| cPanel/WHM driver                                 | Senior Engineer         | #69, #73               | ✅ 1a+1b merged | spec: provisioning-cpanel.md; after #62                                                                                                                                 |
| Registrar drivers (.np, ResellerClub, Namecheap)  | Senior Engineer         | #199, #262, #264, #265 | ✅ .np merged   | .np: clients register at the registry, Mohar shows nameservers; kernel contracts merged (ADR 0009 PR C1-C3); prep for #280 in flight, then ResellerClub, then Namecheap |
| Client importer (CSV, WHMCS preset)               | Senior Engineers        | #160–#163, #184, #201  | ✅ merged       | upload, map, review, confirm and apply, report; B2 merged (invitations #255, opening balances #259)                                                                     |
| eSewa and Khalti simulators                       | Senior Engineer         | #321                   | 🔍 in re-review | NPR lifecycle end to end (#208)                                                                                                                                         |
| Flaky-test fix                                    | Senior Engineer         | —                      | ⏳ in flight    | #310 and #209; PR not yet opened                                                                                                                                        |
| VAT register and credit-note spec                 | Product manager         | —                      | ⏳ in flight    | spec only                                                                                                                                                               |
| Reports, AI module, WHMCS API importer            | Senior Engineers        | —                      | ⏳ later        | specs ready                                                                                                                                                             |

## Timeline

### 2026-10-22

- Merged (full list in `docs/handoff/state.md`): registrar contracts and drivers on the kernel (#262, #264, #265),
  Laravel 13.36 (#242), the local merge gate (#188), macOS tooling (#271), the credit ledger idempotency key (#283),
  KYC image re-encode with its backfill, browser resize and unlock races (#286, #314, #312, #308), optional BS dates
  on invoices (#293), step-up fixes (#296, #299), logout clears browser history (#304), `lock_timeout` for migration
  DDL (#306), Redactor and arch-rule fixes (#294, #301), `develop` built on every push with E2E on `main` only
  (#319), and the CEO and CTO reviews (#315, #318).
- In flight: #321 simulators (in re-review), the flaky-test fix, registrar prep for #280, the VAT register and
  credit-note spec. Next: the ResellerClub driver after #280.
- Sizing and restore docs (#320, draft): one minimum everywhere, 2 vCPU and 4 GB, with the measured memory (857 MiB at
  50,000 clients on the load-test stack) and when to grow. The documented backup and restore ran end to end once on
  Docker (database, bucket and an encrypted KYC image restored, 42 s); `down -v` needed both colour profiles and
  `deploy/bin/deploy` needed a portable path.

### 2026-10-21

- Morning: process review of the agents, skills and handoff docs (#204: shared review rules, `review-round` skill,
  operating model rewritten as the rules in force). Repository renamed `bhimrazy/mohar`. Subagents moved from Fable to
  Opus or Sonnet by task after Fable's weekly limit.
- Merged: npm advisory fix by vite-plus 1.1.0 (#206), load-test harness and sizing guide (#108, runner p95 424 ms at
  40 users on 2 vCPU), audits only on dependency changes (#210, #214), `.np` registered by clients with the host's
  nameservers (#199), Nepal-first install defaults and a queue timeout check (#215), the client importer's confirm and
  apply (#184) and review decisions, report and errors CSV (#201): **clients can now be imported**, billing reversals:
  won disputes, credit netting and the client lock order for renewals and checkouts (#211), eSewa fixes found by a REA
  capture of the UAT sandbox: cancel answers `NOT_FOUND`, booking window 50 minutes (#213).
- Afternoon merges: deployment D4, image build workflow and cPanel cron mode (#167); ADR 0021, confirm-then-apply row
  locks in the order payment → invoice → client (#227); billing go-live fixes: deposit refunds, Stripe dispute
  inquiries, late-sweep spacing, overpayment lock order (#229, closing #223, #212, #222, #224); the end-to-end hosting
  scenario green again (#234, follow-up #235).
- Owner decisions: KYC documents are reviewed by Owner and Admin only; approve and decline stay disabled until the
  documents are opened.
- Branch and issue cleanup: 107 stale remote branches removed (34 archived as tags), 10 issues closed with evidence.

### 2026-10-20

- Afternoon: the repository went public (owner; licence all rights reserved, `main` a readme-and-licence page, #179,
  #180, #186, #187). Merged #166 (dashboard and search on the 50k-client hot paths), #165, #94 (storefront home page
  and public layout) and #99 (Admin > Website). Reviews: #181 and #164 changes requested, #189 approve with fixes.

- Merged: CI speed-up (#91), import-page re-review fixes (#119), local DB options (#125), automation redelivery fix
  (#122, closes #82), CI minutes budget (#131), cPanel import step 3b-1 (#95, tick, review and a queued import with a
  per-account report; 50k-account stop in 6.8 s on PostgreSQL).
- Stripe verified against the owner's sandbox and eSewa against its UAT environment, with no code changes; a late
  payment booking bug found by the test matrix (#130).
- Merged later the same day: production compose stack (#80), Stripe simulator (#113), end-to-end lifecycle `simulate:e2e`
  (#115, 50 s on a runner), docs site (#137, Astro Starlight, not published), cPanel import step 3b-2 (#133).

### 2026-10-18

- Merged: Nepal payments (#26), product polish (#38), image optimisation (#40), CI fixes (#46, #56), database hardening (#53), host-header fix (#59), currencies (#43), install path (#57), Dashboard v2 (#41), kernel contracts PR A (#61), provisioning on the kernel PR B (#62), Notifications (#28), docs-only CI pushes (#66), kernel B2 (#65).
- **Wave 1 complete**: Support (#6), Services & Provisioning (#7), Orders (#8), Billing (#10) and Themes + Mohar brand (#17) merged; 1464 tests green on SQLite and PostgreSQL.
- Billing follow-ups (#22) merged: exact quote conversion (#20), 90 days of demo billing history; 1469 tests. Review follow-ups in #23.
- Wave 2 started (staggered, two engineers at a time): Notifications and Nepal payment drivers. Dashboard v2 started (principal designer).

### 2026-10-17

- Foundation, Core module, RustFS object storage, engineering docs (`architecture.md`, ADR 0001–0005).
- PM research of the product category → 10 module specs with acceptance criteria.
- Kernel hardening from PM findings: anchored billing cycles, period-aware invoice guard, central automation pipeline.
- Design system + shared components ([light](screenshots/01-design-system-showcase.png), [dark](screenshots/01-design-system-showcase-dark.png)).
- Wave 1 started: 7 senior engineers + DevOps in parallel worktrees.
- Merged: dependency policy (#9), Domains (#5), Catalog (#4), Platform (#3). Contracts v2 (ADR 0008) settled cross-module seams.
- Product decisions: Calm Fintech default theme with customisable themes; product name **Mohar** (configurable).
- Specs: registrar drivers; Nepal payment gateways.

![Design system showcase](screenshots/01-design-system-showcase.png)
