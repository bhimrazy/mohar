# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright against a running instance).

| Workstream                                        | Owner (agent)           | Branch / PR | Status       | Notes                                                                                                                                        |
| ------------------------------------------------- | ----------------------- | ----------- | ------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Foundation & Core                                 | CTO                     | `develop`   | ✅ merged    | shared kernel, contracts v2 (ADR 0008)                                                                                                       |
| Design system & shared UI                         | Senior Product Designer | `develop`   | ✅ merged    | 15 components                                                                                                                                |
| Product specs (14 modules)                        | PMs + architects        | `develop`   | ✅ merged    | `docs/product/*.md` incl. AI, migration, Nepal payments, registrars                                                                          |
| DevOps: Postgres + CI                             | DevOps Engineer         | #1          | ✅ merged    | SQLite + PostgreSQL in CI                                                                                                                    |
| Dependency policy + CI minutes                    | DevOps Engineer         | #9          | ✅ merged    | exact pins, ≥7-day cooldown, ~2 min/run                                                                                                      |
| Domains                                           | Senior Engineer         | #5          | ✅ merged    | 171 tests; follow-ups #12–#15                                                                                                                |
| Catalog                                           | Senior Engineer         | #4          | ✅ merged    | 477 suite tests at merge; follow-up #16                                                                                                      |
| Platform admin + security tier                    | Senior Engineer         | #3          | ✅ merged    | 628 suite tests at merge                                                                                                                     |
| Support                                           | Senior Engineer         | #6          | ✅ merged    |                                                                                                                                              |
| Services & Provisioning                           | Senior Engineer         | #7          | ✅ merged    |                                                                                                                                              |
| Orders                                            | Senior Engineer         | #8          | ✅ merged    | 1464 suite tests at merge                                                                                                                    |
| Billing                                           | Senior Engineer         | #10         | ✅ merged    | security + money integrity + GST tax invoice; follow-ups PR #22                                                                              |
| Themes module + Mohar brand                       | Principal Designer      | #17         | ✅ merged    | Calm Fintech default, custom themes, brand settings                                                                                          |
| Billing follow-ups                                | Senior Engineer         | #22         | ✅ merged    | exact quote conversion (#20), demo history; follow-ups #23                                                                                   |
| Notifications                                     | Senior Engineer         | #28         | ✅ merged    | branded mail layout, templates, webhooks; private links only to their owner                                                                  |
| Email direction A (Notifications follow-up)       | Senior Engineer         | #72         | 🔄 in review | two-card layout for every email, framework mail through templates, sweep fixes; screenshots in `docs/screenshots/notifications/direction-a/` |
| Affiliates                                        | Senior Engineer         | —           | ⏳ wave 2    |                                                                                                                                              |
| Nepal payment drivers (Khalti, eSewa, ConnectIPS) | Senior Engineer         | #26         | ✅ merged    | Khalti and eSewa; ConnectIPS later; follow-ups #27                                                                                           |
| Dashboard v2                                      | Principal Designer      | #41         | ✅ merged    | KPI cards, charts, Automation page summary                                                                                                   |
| Product polish sweep 1                            | Product reviewer        | #38         | ✅ merged    |                                                                                                                                              |
| Database hardening (audit 1)                      | Database reviewer       | #53         | ✅ merged    | 55 indexes, paging fix in automation                                                                                                         |
| Currencies and exchange rates                     | Senior Engineer         | #43         | ✅ merged    | symbols, lakh grouping, rate sources, reviews, converted prices                                                                              |
| Production install path                           | Senior Engineer         | #57         | ✅ merged    | `app:install`, reference-data installers                                                                                                     |
| Kernel contracts (ADR 0009 PR A)                  | Senior Engineer         | #61         | ✅ merged    | outbound address guard, Redactor, SettingField, driver registry                                                                              |
| Provisioning on kernel (ADR 0009 PR B, B2)        | Senior Engineer         | #62         | 🔍 in review | base for the cPanel/WHM driver                                                                                                               |
| Stripe gateway                                    | Senior Engineer         | #58         | 🔍 in review | needs owner's test keys                                                                                                                      |
| cPanel/WHM driver                                 | Senior Engineer         | —           | ⏳ next      | spec: provisioning-cpanel.md; after #62                                                                                                      |
| Registrar drivers (.np, ResellerClub, Namecheap)  | Senior Engineer         | —           | ⏳ wave 2    | spec: registrars.md; needs #12 first                                                                                                         |
| Reports, AI module, WHMCS importer                | Senior Engineers        | —           | ⏳ wave 3    | specs ready                                                                                                                                  |

## Timeline

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
