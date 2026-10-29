# Public REST API — Product Spec (v1)

Status: draft for engineering · Owner: Principal architect (foundation), module workstreams (endpoints) · Design: ADR
0029 (Proposed). Clean-room: behaviour learned from the public WHMCS API reference and REST v2 description, and from
public APIs in general (Stripe, GitHub). Names, schemas and wording are ours.

## 1. Purpose and scope

The API lets integrators, resellers, accounting tools and custom storefronts read and change Mohar data without the
web UI. Every write calls the same Action as the UI (ADR 0029 §1), so the API never behaves differently.

- **v1.0, staff API.** Staff tokens; read and core writes for clients, contacts, services, invoices, payments,
  credit, orders, domains, tickets and catalogue reads. It covers the 39 P0 actions in
  `parity/developer-architecture.md` §11, except `CreateSsoToken` (v1.1, sensitive).
- **v1.1, client, storefront and sensitive.** Client tokens with the `/account` root; storefront keys with the
  `/storefront` root (headless cart, checkout, hand-off, payment sessions); hardened tokens; refunds; client SSO links;
  the event feed.
- **Later** (separate specs): OAuth 2 / OIDC for third-party apps, quotes, affiliates, servers and products as writes,
  reports over the API, settings, webhook endpoint management, bulk operations, a WHMCS compatibility package (P2,
  owner decision).

Out of scope for good: an RPC endpoint, XML or other formats, offset paging, endpoints that reveal stored credentials
or decrypt secrets, and a password grant for client sign-in.

## 2. Conventions (every endpoint) — MUST

- Base URL `https://<host>/api/v1`; JSON only; `Authorization: Bearer <token>`.
- The envelope, money, time, ids, enums and naming follow ADR 0029 §6:
    - money is `{"amount": <int minor units>, "currency": "NPR"}`;
    - instants are UTC ISO 8601 with `Z`;
    - company-calendar dates are `YYYY-MM-DD`;
    - every object has `"object"`.
- Lists follow ADR 0029 §7: `limit` (25, at most 100), `cursor`, `filter[...]`, `sort`, and
  `filter[updated_since]` on synced collections.
- Errors are problem details with a stable `code` (ADR 0029 §8).
- `Idempotency-Key` is required where the endpoints table says **Idem** (ADR 0029 §9).
- Ability: the permission string a staff token and its owner's role must both hold. `—` means any valid token of that
  root.
- Every non-GET call writes one activity entry with `channel: api`, the token id and the request id.

## 3. Tokens and administration

### 3.1 Staff tokens (Platform, v1.0) — MUST

- Admin → My account → API tokens. The page needs `core.api_tokens.own`, and creating a token needs step-up.
  The form takes:
    - a name;
    - the abilities, picked from `PermissionRegistry` groups, with wildcards allowed but sensitive keys left out of
      wildcards;
    - an expiry (default 90 days, at most 365);
    - an optional IP allowlist (CIDR).
- The plain-text token is shown once, with a copy button and a warning. The list shows name, prefix and last four
  characters, abilities, created, expires, last used (time and IP) and a revoke action.
- Admin → Settings → API (`core.settings.update`):
    - the API on or off switch;
    - default rate limits;
    - the default and maximum token expiry;
    - an "All tokens" table (`core.api_tokens.manage`, sensitive) where an administrator can revoke any token.
- The owner gets a security notice by mail on create, revoke, expiry and (v1.1) first use of a sensitive ability from
  a new IP.
- A token stops working when it expires, is revoked, its owner loses staff status or is disabled, or the API is off.

### 3.2 Client tokens (Core client area, v1.1) — MUST

- Client area → Account → API tokens. This needs the `profile` area on the current account, and step-up.
- Abilities are the client areas the user holds on that account, each full or `:read`. A token is bound to one
  account.
- Every rule in §3.1 applies: shown once, expiry, revoke, notices, last used.
- The admin client profile lists a client's tokens and can revoke them (`core.clients.update`).

### 3.3 Storefront keys (Platform, v1.1) — MUST

- A staff token whose only ability is `orders.storefront.use`, created from Admin → Settings → API → Storefront keys.
- The form shows a warning that the key belongs on a server and never in a browser.

### 3.4 Hardened tokens (v1.1) — MUST

- A token may hold a sensitive permission only when it has an IP allowlist, expires within 30 days and lists the
  permission by its exact key (ADR 0029 §3). The form enforces these rules, and the server checks them again on every
  call.

## 4. v1.0 endpoints (staff root)

| Method | Path                                    | Ability                                   | Notes                                                                                       |
| ------ | --------------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------- |
| GET    | `/me`                                   | —                                         | Token kind, owner, effective abilities, expiry. Core only                                   |
| GET    | `/openapi.json`                         | none (public)                             | The committed OpenAPI document                                                              |
| GET    | `/currencies`                           | —                                         | Code, exponent, default flag, rate to default                                               |
| GET    | `/payment-methods`                      | `billing.invoices.view`                   | Active gateways per currency (`GatewayDirectory`)                                           |
| GET    | `/clients`                              | `core.clients.view`                       | Filters: `status`, `email`, `group_id`, `currency`, `updated_since`                          |
| GET    | `/clients/{id}`                         | `core.clients.view`                       | `include=contacts,credit_balances`                                                          |
| POST   | `/clients`                              | `core.clients.create`                     | Same Action as Admin → Clients → New; `send_welcome` as in the form                         |
| PATCH  | `/clients/{id}`                         | `core.clients.update`                     | `If-Match` supported; the inline controller logic is extracted to an Action first           |
| GET    | `/contacts`                             | `core.clients.view`                       | Filter `client_id` required                                                                 |
| POST   | `/contacts`                             | `core.contacts.manage`                    | `SaveContact`                                                                               |
| PATCH  | `/contacts/{id}`                        | `core.contacts.manage`                    |                                                                                             |
| GET    | `/products`                             | `catalog.products.view`                   | With pricing per currency and cycle (`PriceBook`); filters `group_id`, `module`             |
| GET    | `/products/{id}`                        | `catalog.products.view`                   |                                                                                             |
| GET    | `/invoices`                             | `billing.invoices.view`                   | Filters: `client_id`, `status`, `due_before`, `due_after`, `updated_since`                   |
| GET    | `/invoices/{id}`                        | `billing.invoices.view`                   | `include=items,transactions`                                                                |
| GET    | `/invoices/{id}/pdf`                    | `billing.invoices.view`                   | `application/pdf`; the fiscal PDF limiter applies                                           |
| POST   | `/invoices`                             | `billing.invoices.manage`                 | **Idem**. `IssueInvoice` via `Invoicer`; draft or issued as in the admin form               |
| POST   | `/invoices/{id}/payments`               | `billing.payments.manage`                 | **Idem**. Record a manual payment (`ApplyPayment`); `send_email`                            |
| POST   | `/invoices/{id}/capture`                | `billing.payments.manage`                 | **Idem**. Charge a stored pay method (`CaptureInvoice`); an unknown outcome follows ADR 0026 |
| POST   | `/invoices/{id}/apply-credit`           | `billing.credit.manage`                   | **Idem**. `ApplyCredit`                                                                     |
| GET    | `/transactions`                         | `billing.transactions.view`               | Filters: `client_id`, `invoice_id`, `gateway`, `date_from`, `date_to`                        |
| GET    | `/transactions/{id}`                    | `billing.transactions.view`               |                                                                                             |
| GET    | `/credit-entries`                       | `billing.invoices.view`                   | Credit ledger; filter `client_id` required; balances in `/clients/{id}?include=`            |
| POST   | `/credit-entries`                       | `billing.credit.manage`                   | **Idem**. Add credit (not an adjustment, which is sensitive: v1.1)                          |
| GET    | `/pay-methods`                          | `billing.invoices.view`                   | Filter `client_id` required; masked card or wallet data only                                |
| GET    | `/services`                             | `services.services.view`                  | Filters: `client_id`, `status`, `product_id`, `server_id`, `next_due_before`, `updated_since` |
| GET    | `/services/{id}`                        | `services.services.view`                  | `include=addons`; never credentials                                                         |
| POST   | `/services/{id}/create`                 | `services.services.provision`             | **Idem**. `ExecuteModuleCommand`; 202 when queued                                           |
| POST   | `/services/{id}/suspend`                | `services.services.provision`             | **Idem**. `reason`                                                                          |
| POST   | `/services/{id}/unsuspend`              | `services.services.provision`             | **Idem**                                                                                    |
| POST   | `/services/{id}/terminate`              | `services.services.provision`             | **Idem**                                                                                    |
| POST   | `/services/{id}/cancellation-requests`  | `services.cancellations.manage`           | `RequestCancellation`; `type` immediate or end of period                                    |
| GET    | `/service-addons`                       | `services.services.view`                  | Filter `client_id` or `service_id`                                                          |
| GET    | `/orders`                               | `orders.orders.view`                      | Filters: `client_id`, `status`, `updated_since`                                             |
| GET    | `/orders/{id}`                          | `orders.orders.view`                      | `include=items,invoice`                                                                     |
| POST   | `/orders`                               | `orders.orders.create`                    | **Idem**. Cart-less order builder for an existing client (new, shared with the storefront)  |
| POST   | `/orders/{id}/accept`                   | `orders.orders.accept`                    | `AcceptOrder`; options as in the admin accept dialog                                        |
| POST   | `/orders/{id}/cancel`                   | `orders.orders.cancel`                    | `CancelOrder`                                                                               |
| GET    | `/domains`                              | `domains.domains.view`                    | Filters: `client_id`, `status`, `tld`, `expires_before`, `updated_since`                     |
| GET    | `/domains/{id}`                         | `domains.domains.view`                    | Never the EPP code                                                                          |
| PUT    | `/domains/{id}/nameservers`             | `domains.domains.update`                  | `UpdateNameservers`; replaces the whole list, so `PUT` is right here                        |
| GET    | `/domain-availability`                  | `domains.tlds.view`                       | `name=example.com.np`; `DomainLookup`; its own limiter                                      |
| GET    | `/tlds`                                 | `domains.tlds.view`                       | Pricing per currency and action (`DomainPricing`)                                           |
| GET    | `/tickets`                              | `support.tickets.view`                    | Filters: `client_id`, `status`, `department_id`, `updated_since`; department access applies |
| GET    | `/tickets/{id}`                         | `support.tickets.view`                    | `include=replies`; internal notes only with `support.tickets.manage`                        |
| POST   | `/tickets`                              | `support.tickets.open`                    | `OpenTicket` for a client; no attachments in v1.0                                           |
| POST   | `/tickets/{id}/replies`                 | `support.tickets.reply`                   | `PostReply`; `internal: true` for a note                                                    |

**Exception to the nameservers row.** ADR 0029 §6 says "no `PUT`". The nameservers row is the only exception: it
replaces a whole list, which is what `PUT` means. The ADR is amended if review prefers
`POST /domains/{id}/nameservers`.

## 5. v1.1 endpoints

### 5.1 Account root (client tokens)

| Method | Path                                         | Area          | Notes                                                                       |
| ------ | -------------------------------------------- | ------------- | --------------------------------------------------------------------------- |
| GET    | `/account`                                   | —             | Account profile, currency, credit balance                                   |
| PATCH  | `/account`                                   | `profile`     | Same rules as the client profile form (email change needs the web step-up) |
| GET    | `/account/contacts`, POST, PATCH `{id}`      | `contacts`    |                                                                             |
| GET    | `/account/invoices`, `/{id}`, `/{id}/pdf`    | `billing`     |                                                                             |
| POST   | `/account/invoices/{id}/payment-sessions`    | `billing`     | **Idem**. `StartHostedPayment`; returns `next_action`                       |
| GET    | `/account/services`, `/{id}`                 | `services`    |                                                                             |
| POST   | `/account/services/{id}/cancellation-requests` | `services`  | Client-area rules                                                           |
| GET    | `/account/domains`, `/{id}`                  | `domains`     |                                                                             |
| PUT    | `/account/domains/{id}/nameservers`          | `domains`     |                                                                             |
| GET    | `/account/tickets`, `/{id}`; POST; POST `/{id}/replies` | `support` | Client-visible replies only                                     |

### 5.2 Storefront root (storefront keys)

| Method | Path                                         | Notes                                                                             |
| ------ | -------------------------------------------- | --------------------------------------------------------------------------------- |
| GET    | `/storefront/products`, `/{id}`              | Public catalogue in a `currency`; hidden products excluded                        |
| GET    | `/storefront/currencies`                     |                                                                                   |
| GET    | `/storefront/domain-availability`, `/tlds`   | `api-guest` limiter                                                               |
| POST   | `/storefront/carts`                          | Returns the cart with its unguessable `id`                                        |
| GET    | `/storefront/carts/{id}`                     | Lines, totals, tax, promotion                                                     |
| POST   | `/storefront/carts/{id}/items`; PATCH, DELETE `/items/{item}` | Same validation as the web cart (`CartManager`)                  |
| POST   | `/storefront/carts/{id}/promotion`; DELETE   | Promotion code                                                                    |
| POST   | `/storefront/carts/{id}/checkout`            | **Idem**. Guest checkout only: creates the client (`RegisterCheckoutClient`), runs fraud screening, places the order; returns order, invoice and `next_action` |
| POST   | `/storefront/carts/{id}/handoff`             | Single-use 60-second URL to the web checkout with the cart; for existing clients  |

### 5.3 Sensitive staff endpoints (hardened tokens)

| Method | Path                                  | Ability (sensitive)            | Notes                                                                             |
| ------ | ------------------------------------- | ------------------------------ | --------------------------------------------------------------------------------- |
| POST   | `/transactions/{id}/refunds`          | `billing.refunds.manage`       | **Idem**. Same refund Action, reservations and ADR 0026 rules as the admin UI     |
| POST   | `/credit-entries/adjustments`         | `billing.credit.adjust`        | **Idem**                                                                          |
| POST   | `/clients/{id}/sign-in-links`         | `core.clients.sso` (new)       | Single-use, 60 s, destination allowlist (`invoices`, `services/{id}`, `cart`, `tickets`), audit entry, per-client opt-out |

### 5.4 Event feed

- `GET /events` lists webhook-registered events (`WebhookEvents` types) for the last 30 days. It uses cursor paging,
  `filter[type]` and `filter[created_after]`.
- The envelope is the webhook envelope, so an integration that missed webhooks can catch up. Each event needs the
  ability that guards its entity.

## 6. Acceptance criteria

### Foundation — MUST

1. With every module loaded and with `MODULES=Core`:
    - `GET /api/v1/me` works with a staff token;
    - every module endpoint answers 404 problem JSON when its module is absent;
    - the inbound email callback still works at its old URL without a token.
2. A request with no token, an expired token, a revoked token, or a token whose owner lost staff status gets 401
   with the matching `auth.*` code. A token sent in the query string gets 400 `auth.token_in_url`.
3. A staff token with `billing.invoices.view`, held by an owner whose role lacks it, gets 403 `auth.forbidden` with
   `required_ability`. After the role is narrowed, the next call is refused without changing the token.
4. A token cannot be created with `*`. A `billing.*` token does not reach a route guarded by a sensitive `billing`
   permission.
5. Creating a token without a recent password confirmation is refused. Each create or revoke writes one activity
   entry and sends one notice.
6. Every error under `/api` is `application/problem+json` with `type`, `title`, `status`, `code` and `request_id`. No
   stack trace or exception message leaks when `APP_DEBUG` is off.
7. Lists:
    - an unknown filter, sort or include gets 400;
    - a tampered cursor gets 400 `request.invalid_cursor`;
    - paging through a list while rows are inserted returns every row that existed at the start exactly once;
    - `limit` above 100 is refused.
8. Idempotency, on `POST /invoices/{id}/payments`:
    - no key: 400;
    - the same key and body twice: one transaction, an identical response and `Idempotent-Replayed: true`;
    - the same key with another body: 422;
    - two parallel calls: one 2xx and one 409 `idempotency.in_progress`;
    - a validation failure keeps the key reusable;
    - a 5xx after the Action started is replayed, not re-run.
9. Rate limits: going past the `api-money` limit gets 429 with `Retry-After`. The limits come from settings.
10. `php artisan api:openapi` is deterministic, the CI check fails on a stale `billing/docs/api/openapi-v1.json`, and
    `--diff` reports a removed field as breaking.
11. Every API request logs one line with route, principal, token id, status, duration, query count and request id,
    and never the token secret. A leaked token string in a log line is masked by the Redactor.
12. `architecture.md` states the "one Action for UI and API" rule. A hygiene test fails when a class in
    `Http/Controllers/Api` writes a model directly (`save`, `create`, `update`, `delete`, `DB::`).

### Per endpoint PR — MUST

1. Each endpoint in its table row has feature tests for:
    - success;
    - a missing ability (403);
    - a missing record (404);
    - validation (422), with field codes;
    - for **Idem** rows, a replay.
2. Each write endpoint has a test proving it calls the same Action as the admin route. A behaviour test is enough: the
   same input gives the same records and events through both doors.
3. The list endpoint makes the same number of queries at `limit=5` and at `limit=100` (query-budget test).
4. The resource holds no secret field (`HygieneTest` over `Http/Resources/Api`). Money is minor units with a currency,
   and calendar dates are `Y-m-d`.
5. The webhook payload for the entity, if the module registers one, comes from the same Resource. The webhook test
   and the API test assert the same `data.object` keys.
6. The OpenAPI document is regenerated and committed, with a kernel changelog fragment listing the new operations.
7. Single-object GET p95 stays under 200 ms in the API load-test scenario (once that scenario exists, before GA).

### v1.1 — MUST

1. A client token on account A cannot read account B's invoice (404). A `billing:read` token gets 403 on
   `payment-sessions`. Removing the user's `billing` area on the account takes effect on the next call.
2. Guest checkout through the storefront creates exactly one client, order and invoice per idempotency key, and runs
   fraud screening as the web checkout does. A hand-off URL works once and expires after 60 seconds.
3. A refund through a token without an IP allowlist, or with an expiry over 30 days, gets 403
   `auth.sensitive_requires_hardened_token`. With a hardened token it follows the same refund rules as the admin UI,
   including the pending outcome on a timeout.
4. A sign-in link is single-use, lands only on an allowlisted destination, is refused for a client who opted out,
   and writes an audit entry naming the staff member and the token.

## 7. PR plan

The foundation PRs are kernel work made by the CTO session (CLAUDE.md, shared-kernel rule). The endpoint PRs are
module work and can run in parallel once API-0b merges.

| PR      | Scope                                                                                                                                                                          | Depends on        | Owner         |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------- | ------------- |
| ADR     | This ADR and spec (docs only)                                                                                                                                                  | —                 | Architect     |
| Approve | Owner approval of `laravel/sanctum` and the OpenAPI generator (ADR 0007); token prefix                                                                                         | ADR               | Owner         |
| API-0a  | `App\Http\Api` foundation: Sanctum install with token columns, the principal, middleware (`api.token`, `api.ability`, `api.audience`), problem renderer, request id, rate limiters, `ListQuery`, `ApiResource` base, `/me`, `routes/callbacks.php` move, ActivityLogger `channel`, Redactor token rule, hygiene tests, `architecture.md` rule | Approve           | CTO           |
| API-0b  | `api_idempotency_keys` and `api.idempotent`, `api:openapi` with the CI check and diff, `/openapi.json`, the API settings section, an `Automation` prune task                     | API-0a            | CTO           |
| API-1   | Platform: staff token UI, Settings → API, all-tokens table, security notices                                                                                                    | API-0a            | Platform      |
| API-2   | Core: clients, contacts, currencies (extract client create and update into Actions)                                                                                             | API-0b            | Core          |
| API-3   | Billing reads: invoices, invoice PDF, transactions, credit entries, pay methods, payment methods                                                                                | API-0b            | Billing       |
| API-4   | Billing writes: create invoice, record payment, capture, apply and add credit, with domain keys where missing                                                                  | API-3             | Billing       |
| API-5   | Services and Catalog: services, addons, commands, cancellation requests, products                                                                                              | API-0b            | Services      |
| API-6   | Orders: list, get, accept, cancel, cart-less `POST /orders` builder                                                                                                            | API-0b            | Orders        |
| API-7   | Domains: list, get, nameservers, availability, TLD pricing                                                                                                                     | API-0b            | Domains       |
| API-8   | Support: tickets list, get, open, reply                                                                                                                                        | API-0b            | Support       |
| API-9   | Docs site: reference from the JSON, problem pages, WHMCS action mapping table, API load-test scenario                                                                           | API-2 to 8        | Docs, DevOps  |
| v1.0 GA | Owner declares v1 generally available, ending the beta window                                                                                                                  | API-9             | Owner         |
| API-10  | Client tokens and the `/account` root, including payment sessions                                                                                                               | v1.0              | Core, Billing |
| API-11  | Storefront keys, session-free `CartManager` entry point, `/storefront` root, hand-off                                                                                           | API-6, API-10     | Orders        |
| API-12  | Hardened tokens, refunds, credit adjustments, client sign-in links                                                                                                              | API-1, API-4      | CTO, Billing  |
| API-13  | Event feed                                                                                                                                                                     | API-0b            | Notifications |

Reviews:

- **Architect gate:** API-0a, API-0b and API-12.
- **Security and database checklists:** API-0a, API-0b, API-4, API-10, API-11 and API-12, which handle tokens, money
  or sign-in.
- **The review board alone:** the other endpoint PRs.

## 8. Open questions for the owner

1. Approve `laravel/sanctum` (first-party) under ADR 0007, or have us build our own token table?
2. Approve `dedoc/scramble` for OpenAPI generation, or have us write a small generator of our own?
3. The token prefix. It is shown to integrators and used by secret scanners, so it should not follow the configurable
   brand. Options: a neutral fixed string, or the product key.
4. Confirm there is no WHMCS RPC shim in v1, and say whether a compatibility package should go on the roadmap.
5. Confirm a storefront API that does guest checkout only and hands existing clients off to the web checkout, with no
   password sign-in over the API.
6. Is the API available in every edition and licence? This depends on the licence and price model decision.
7. The support window: is v1 kept for 12 months after v2 is generally available?
8. Should staff "service accounts" (no interactive login) own integration tokens, so that tokens do not depend on one
   person's employment? We recommend this as a follow-up to API-1.
