# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright against a running instance).

| Workstream                                        | Owner (agent)           | Branch / PR | Status                    | Notes                                                               |
| ------------------------------------------------- | ----------------------- | ----------- | ------------------------- | ------------------------------------------------------------------- |
| Foundation & Core                                 | CTO                     | `develop`   | ✅ merged                 | shared kernel, contracts v2 (ADR 0008)                              |
| Design system & shared UI                         | Senior Product Designer | `develop`   | ✅ merged                 | 15 components                                                       |
| Product specs (12 modules)                        | PMs + architects        | `develop`   | ✅ merged                 | `docs/product/*.md` incl. AI, migration, Nepal payments, registrars |
| DevOps: Postgres + CI                             | DevOps Engineer         | #1          | ✅ merged                 | SQLite + PostgreSQL in CI                                           |
| Dependency policy + CI minutes                    | DevOps Engineer         | #9          | ✅ merged                 | exact pins, ≥7-day cooldown, ~2 min/run                             |
| Domains                                           | Senior Engineer         | #5          | ✅ merged                 | 171 tests; follow-ups #12–#15                                       |
| Catalog                                           | Senior Engineer         | #4          | ✅ merged                 | 477 suite tests at merge; follow-up #16                             |
| Platform admin + security tier                    | Senior Engineer         | #3          | ✅ merged                 | 628 suite tests at merge                                            |
| Support                                           | Senior Engineer         | #6          | 🔍 re-review              | 790 suite tests with merged modules                                 |
| Services & Provisioning                           | Senior Engineer         | #7          | 🔧 fixing review findings |                                                                     |
| Orders                                            | Senior Engineer         | #8          | 🔧 fixing review findings |                                                                     |
| Billing                                           | Senior Engineer         | #10         | 🔧 fixing review findings | security + money integrity + GST tax invoice                        |
| Themes module + Mohar brand                       | Principal Designer      | #17         | 🔧 merging develop        | Calm Fintech default, custom themes, brand settings                 |
| Notifications                                     | Senior Engineer         | —           | ⏳ wave 2                 |                                                                     |
| Affiliates                                        | Senior Engineer         | —           | ⏳ wave 2                 |                                                                     |
| Nepal payment drivers (Khalti, eSewa, ConnectIPS) | Senior Engineer         | —           | ⏳ wave 2                 | spec: payments-nepal.md                                             |
| Registrar drivers (.np, ResellerClub, Namecheap)  | Senior Engineer         | —           | ⏳ wave 2                 | spec: registrars.md; needs #12 first                                |
| Reports, AI module, WHMCS importer                | Senior Engineers        | —           | ⏳ wave 3                 | specs ready                                                         |

## Timeline

### 2026-10-18

- Merged: dependency policy (#9), Domains (#5), Catalog (#4), Platform (#3). Contracts v2 (ADR 0008) settled cross-module seams.
- Product decisions: Calm Fintech default theme with customisable themes; product name **Mohar** (configurable).
- Specs: registrar drivers; Nepal payment gateways.

### 2026-10-17

- Foundation, Core module, RustFS object storage, engineering docs (ARCHITECTURE, ADR 0001–0005).
- PM research of the product category → 10 module specs with acceptance criteria.
- Kernel hardening from PM findings: anchored billing cycles, period-aware invoice guard, central automation pipeline.
- Design system + shared components ([light](screenshots/01-design-system-showcase.png), [dark](screenshots/01-design-system-showcase-dark.png)).
- Wave 1 started: 7 senior engineers + DevOps in parallel worktrees.

![Design system showcase](screenshots/01-design-system-showcase.png)
