# Build Progress

Living status board. Each workstream updates its row when it opens a PR for review. Screenshots live in
`docs/screenshots/` (captured with headless Playwright: `node scripts/screenshots.mjs`).

| Workstream                                          | Owner (agent)           | Branch                  | Status                    | Notes                                                                |
| --------------------------------------------------- | ----------------------- | ----------------------- | ------------------------- | -------------------------------------------------------------------- |
| Foundation & Core                                   | CTO                     | `develop`               | ✅ merged                 | 58 tests, Larastan L7 clean                                          |
| Design system & shared UI                           | Senior Product Designer | `develop`               | 🟡 in progress            | `resources/js/components/app`                                        |
| Product specs (commerce)                            | PM — Commerce           | `develop`               | 🟡 in progress            | catalog, orders, billing, services                                   |
| Product specs (operations)                          | PM — Operations         | `develop`               | 🟡 in progress            | domains, support, notifications, affiliates, platform-admin, reports |
| Catalog                                             | Senior Engineer         | `feature/catalog`       | 🔵 in review              | PriceBook + admin UI; screenshots `docs/screenshots/catalog/`        |
| Orders                                              | Senior Engineer         | `feature/orders`        | ⏳ queued                 |                                                                      |
| Billing                                             | Senior Engineer         | `feature/billing`       | ⏳ queued                 |                                                                      |
| Services & Provisioning                             | Senior Engineer         | `feature/services`      | ⏳ queued                 |                                                                      |
| Domains                                             | Senior Engineer         | `feature/domains`       | ⏳ queued                 |                                                                      |
| Support                                             | Senior Engineer         | `feature/support`       | ⏳ queued                 |                                                                      |
| Notifications                                       | Senior Engineer         | `feature/notifications` | ⏳ queued                 |                                                                      |
| Affiliates                                          | Senior Engineer         | `feature/affiliates`    | ⏳ queued                 |                                                                      |
| Platform admin (staff, roles, currencies, settings) | Senior Engineer         | `feature/platform`      | ⏳ queued                 |                                                                      |
| Reports                                             | Senior Engineer         | `feature/reports`       | ⏳ after billing/services | read model                                                           |

## Timeline

### 2026-10-17

- Foundation, Core module, RustFS object storage, engineering docs (ARCHITECTURE, ADR 0001–0005).
