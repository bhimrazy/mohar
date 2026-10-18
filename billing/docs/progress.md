# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright against a running instance).

| Workstream                                        | Owner (agent)           | Branch / PR | Status         | Notes                                                               |
| ------------------------------------------------- | ----------------------- | ----------- | -------------- | ------------------------------------------------------------------- |
| Foundation & Core                                 | CTO                     | `develop`   | ✅ merged      | shared kernel, contracts v2 (ADR 0008)                              |
| Design system & shared UI                         | Senior Product Designer | `develop`   | ✅ merged      | 15 components                                                       |
| Product specs (12 modules)                        | PMs + architects        | `develop`   | ✅ merged      | `docs/product/*.md` incl. AI, migration, Nepal payments, registrars |
| DevOps: Postgres + CI                             | DevOps Engineer         | #1          | ✅ merged      | SQLite + PostgreSQL in CI                                           |
| Dependency policy + CI minutes                    | DevOps Engineer         | #9          | ✅ merged      | exact pins, ≥7-day cooldown, ~2 min/run                             |
| Domains                                           | Senior Engineer         | #5          | ✅ merged      | 171 tests; follow-ups #12–#15                                       |
| Catalog                                           | Senior Engineer         | #4          | ✅ merged      | 477 suite tests at merge; follow-up #16                             |
| Platform admin + security tier                    | Senior Engineer         | #3          | ✅ merged      | 628 suite tests at merge                                            |
| Support                                           | Senior Engineer         | #6          | ✅ merged      |                                                                     |
| Services & Provisioning                           | Senior Engineer         | #7          | ✅ merged      |                                                                     |
| Orders                                            | Senior Engineer         | #8          | ✅ merged      | 1464 suite tests at merge                                           |
| Billing                                           | Senior Engineer         | #10         | ✅ merged      | security + money integrity + GST tax invoice; follow-ups #22        |
| Themes module + Mohar brand                       | Principal Designer      | #17         | ✅ merged      | Calm Fintech default, custom themes, brand settings                 |
| Notifications                                     | Senior Engineer         | —           | 🚧 in progress | wave 2                                                              |
| Affiliates                                        | Senior Engineer         | —           | ⏳ wave 2      |                                                                     |
| Nepal payment drivers (Khalti, eSewa, ConnectIPS) | Senior Engineer         | —           | 🚧 in progress | Khalti → eSewa → ConnectIPS                                         |
| Registrar drivers (.np, ResellerClub, Namecheap)  | Senior Engineer         | —           | ⏳ wave 2      | spec: registrars.md; needs #12 first                                |
| Reports, AI module, WHMCS importer                | Senior Engineers        | —           | ⏳ wave 3      | specs ready                                                         |

## Timeline

### 2026-10-18

- **Wave 1 complete**: Support (#6), Services & Provisioning (#7), Orders (#8), Billing (#10) and Themes + Mohar brand (#17) merged; 1464 tests green on SQLite and PostgreSQL.
- Wave 2 started (staggered, two engineers at a time): Notifications and Nepal payment drivers.
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
