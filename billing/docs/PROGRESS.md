# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright against a running instance).

| Workstream                 | Owner (agent)           | Branch                  | Status      | Notes                                                                               |
| -------------------------- | ----------------------- | ----------------------- | ----------- | ----------------------------------------------------------------------------------- |
| Foundation & Core          | CTO                     | `develop`               | ✅ merged   | 61 tests, Larastan L7 clean                                                         |
| Design system & shared UI  | Senior Product Designer | `develop`               | ✅ merged   | 15 components — [showcase](screenshots/01-design-system-showcase.png)               |
| Product specs (commerce)   | PM — Commerce           | `develop`               | ✅ merged   | `docs/product/{catalog,orders,billing,services}.md`                                 |
| Product specs (operations) | PM — Operations         | `develop`               | ✅ merged   | `docs/product/{domains,support,notifications,affiliates,platform-admin,reports}.md` |
| Catalog                    | Senior Engineer         | `feature/catalog`       | 🔨 building |                                                                                     |
| Orders                     | Senior Engineer         | `feature/orders`        | 🔨 building |                                                                                     |
| Billing                    | Senior Engineer         | `feature/billing`       | 🔨 building |                                                                                     |
| Services & Provisioning    | Senior Engineer         | `feature/services`      | 🔨 building |                                                                                     |
| Domains                    | Senior Engineer         | `feature/domains`       | 🔨 building |                                                                                     |
| Support                    | Senior Engineer         | `feature/support`       | 🔨 building |                                                                                     |
| Platform admin             | Senior Engineer         | `feature/platform`      | 🔨 building | staff, roles, currencies, settings UI, Core pages                                   |
| DevOps                     | DevOps Engineer         | `feature/devops`        | 🔨 building | postgres:alpine, GitHub Actions CI                                                  |
| Notifications              | Senior Engineer         | `feature/notifications` | ⏳ wave 2   |                                                                                     |
| Affiliates                 | Senior Engineer         | `feature/affiliates`    | ⏳ wave 2   |                                                                                     |
| Reports                    | Senior Engineer         | `feature/reports`       | ⏳ wave 3   | read model over all modules                                                         |

## Timeline

### 2026-10-17

- Foundation, Core module, RustFS object storage, engineering docs (ARCHITECTURE, ADR 0001–0005).
- PM research of the product category → 10 module specs with acceptance criteria.
- Kernel hardening from PM findings: anchored billing cycles, period-aware invoice guard, central automation pipeline.
- Design system + shared components ([light](screenshots/01-design-system-showcase.png), [dark](screenshots/01-design-system-showcase-dark.png)).
- Wave 1 started: 7 senior engineers + DevOps in parallel worktrees.

![Design system showcase](screenshots/01-design-system-showcase.png)
