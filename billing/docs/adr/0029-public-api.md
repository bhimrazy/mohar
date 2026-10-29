# ADR 0029 — Public REST API

- Status: Proposed · 2026-10-29 · Owner: Principal architect. The number may change: a sibling ADR (frontend
  translations) is being written at the same time and takes 0028 if it merges first.
- Related: `billing/docs/product/public-api.md` (scope, endpoints, PR plan), ADR 0001 (modules), ADR 0007 (dependency
  approval), ADR 0010 (public surface, versioning, `kernel:api-snapshot`), ADR 0011 (events and `WebhookEvents`), ADR
  0012 (registries), ADR 0013 (company clock), ADR 0014 (observability, queues), ADR 0018 §4 (step-up), ADR 0020 (hot
  path reads), ADR 0021 (row locks), ADR 0023 (log redaction), ADR 0026 (indeterminate outcomes).
- Research: `docs/reviews/2026-10-29-whmcs-parity.md` §2.14 and §6 (gap #5), `docs/reviews/2026-10-29-oss-comparison.md`
  §9, `billing/docs/parity/developer-architecture.md` §10–§11, `billing/docs/parity/roadmap.md` wave 4.

## Context

Mohar has no public API. `ModuleServiceProvider` already mounts a module's `routes/api.php` under `/api/v1` with the
`api` middleware group, but the only route there is the HMAC-signed inbound email webhook. Sanctum is not installed.
Integrators, resellers and custom storefronts cannot reach Mohar without the web UI. This is the largest gap against
WHMCS for integrators.

What WHMCS publishes, summarised in our own words:

- The classic API is one RPC endpoint that takes a form POST naming an action (about 162 actions in 16 groups).
  Credentials are an identifier and secret pair bound to an admin user. Each pair carries roles, and a role is an
  allowlist of action names. Calls must also come from an allowlisted IP or send an install-wide access key. Errors are
  `result=error` with a free-text message. Lists page by offset. The same actions run in process through `localAPI()`.
- REST API v2 (9.0 and later) is a separate, resource-style API for custom storefronts: catalogue, domain search,
  cart and checkout, client sessions with 2FA, invoice payment. Session and cart state travel in a signed header that
  every response replaces.

The open-source alternatives differ. FOSSBilling builds its own UI on its API, so every UI action is also an API
action. Paymenter has scoped API keys, admin API controllers and OpenAPI generated from code.

Mohar already has most of the parts an API needs:

- permission strings in `PermissionRegistry`, with sensitive keys that wildcards never grant;
- client sub-user areas (`ClientContext::AREAS`);
- one Action per state change (`Modules/*/Actions`);
- action-level idempotency where money moves (`PlaceOrder` with `orders.idempotency_key` and a fingerprint,
  `credit_ledger.idempotency_key`);
- an append-only `ActivityLogger`;
- one step-up mechanism (`password.recent`, ADR 0018 §4);
- a webhook registry (`WebhookEvents`) and a signed webhook envelope;
- a kernel snapshot with CI enforcement (ADR 0010, #513).

The API must add a transport over these parts, not a second application layer.

## Decision

### 1. Shape: a thin, resource-style JSON layer over the existing Actions

- **Resource-style JSON over HTTPS** under `/api/v1`. JSON is the only format, and no RPC endpoint exists.
- **Every write endpoint calls the same Action the admin or client UI calls.** An API controller does no more than an
  admin controller: it validates (FormRequest), authorises, calls one Action, and returns one Resource. If an admin
  controller still holds logic inline, the API PR for that resource first extracts it into an Action that both
  controllers use. This rule goes into `architecture.md` ("Business logic"), so the two front doors cannot behave
  differently.
- **Our own UI does not move onto the API.** The Inertia app keeps its web routes and sessions. Shared Actions already
  give the "one behaviour" benefit that FOSSBilling gets from building its UI on its API, without making every UI
  change an API change.
- **Each module owns its endpoints.** Its routes live in `routes/api.php`, its controllers in `Http/Controllers/Api`
  and its representations in `Http/Resources/Api`. When a module is absent (`MODULES=`), its endpoints do not exist and
  return 404 problem documents. `GET /api/v1/me` and the token endpoints need Core alone.
- **Three audiences, three path roots.** Each token kind can call only its own root, and calling another root returns
  403 `auth.wrong_audience`:

    | Root                   | Caller                             | Token kind                                                       |
    | ---------------------- | ---------------------------------- | ---------------------------------------------------------------- |
    | `/api/v1/*`            | Staff integrations (admin API)     | Staff token                                                      |
    | `/api/v1/account/*`    | A client's own scripts and portals | Client token                                                     |
    | `/api/v1/storefront/*` | A custom storefront site's backend | Storefront key (a staff token with only `orders.storefront.use`) |

    Keeping the roots separate means no query has to choose between staff and client visibility. A client token can never
    reach an admin endpoint through a missed scope check.

- **Package endpoints** live under `/api/v1/ext/<package-key>/*`. Core reserves every other path. A package uses the
  same middleware, Resource base and problem types (§10), and its operations appear in the OpenAPI document under its
  own tag.
- **Machine callbacks are not part of the public API.** The inbound email route moves from `routes/api.php` to a new
  per-module `routes/callbacks.php`. That file keeps the URL `/api/v1/inbound/email/{provider}` and its HMAC check, and
  is excluded from token authentication and from the OpenAPI document. `routes/api.php` then means "public API" and
  nothing else.

### 2. Authentication: tokens whose abilities are permission strings

- **Tokens are personal access tokens, stored hashed.** We use `laravel/sanctum` (first-party; approval needed under
  ADR 0007). Its `personal_access_tokens` table is extended with these columns: `kind` (`staff`, `client`), `client_id`
  (client tokens only), `expires_at` (required), `ip_allowlist` (CIDR list, nullable), `last_used_at`, `last_used_ip`
  and `created_by` (the staff member who created it, which can differ from the owner). Sanctum's cookie (SPA) mode is
  not enabled. Plain-text tokens are shown once and stored only as a SHA-256 hash.
- **Token format** is `<prefix>_<kind letter>_<40 random base62 characters>`. A stable prefix lets secret scanners and
  the Redactor recognise leaked tokens. The prefix is a config value, `api.token_prefix`, and the owner decides its
  default. The Redactor (ADR 0023) gets a value rule for that pattern.
- **Tokens travel only in `Authorization: Bearer`.** A token in a query string or request body is refused with 400
  `auth.token_in_url`, and that request is not logged with its query string. Production refuses plain HTTP.
- **Staff token abilities are permission strings** from `PermissionRegistry`, using the role grammar (exact keys and
  `module.*` and `module.area.*` wildcards). Two rules are stricter than for roles:
    - A token cannot hold `*`.
    - A wildcard on a token never grants a sensitive permission. That is already the role rule, and the token check
      applies it again.
- **Effective ability = token ability AND the owner's current role.** Every request checks
  `token grants P` and `user->hasPermission(P)` together. Narrowing a role, disabling a staff member or removing their
  staff flag therefore takes effect on the next call, with nothing to sync. A token whose owner is no longer staff
  answers 401 `auth.token_revoked`.
- **Client tokens** belong to a (user, client account) pair. Their abilities are client areas from
  `ClientContext::AREAS` (`billing`, `services`, `domains`, `support`, `profile`, `contacts`), each either full or
  read-only (`billing:read`). The check is token area AND the user's live pivot areas (owners hold every area), using
  the same `ClientContext::allows()` the client area uses. Areas stay in Core. API middleware in `App\Http\Api` may
  depend on Core, as `tests/Arch` already allows.
- **Creating a token needs step-up** (`password.recent`, ADR 0018 §4) and, for staff, 2FA whenever the 2FA policy
  requires it for that staff member. Every create, revoke or expiry writes an activity entry and sends a security
  notice (ADR 0018 §7) to the owner. Expiry is required: the default is 90 days and the maximum is 365.
- **New permissions:**
    - `core.api_tokens.own`: create and revoke one's own staff tokens. Not sensitive.
    - `core.api_tokens.manage`: list and revoke anyone's staff tokens. Sensitive.
    - `orders.storefront.use`: the storefront-key ability. Not sensitive.
    - `core.clients.sso`: create a single-use sign-in link for a client (v1.1). Sensitive, because the link skips the
      client's 2FA.
- **OAuth later, not in v1.** Third-party apps acting for a client or a staff member (authorisation code with PKCE,
  refresh-token rotation, the OIDC provider in `developer-architecture.md` §10) need their own ADR and an approved
  server package. The rule that ties v1 to that future is the request model: the token guard resolves a principal of
  (user, kind, client id, abilities), and an OAuth access token will resolve the same principal with scopes in the same
  ability grammar. No endpoint reads the token row directly. Endpoints read only the principal.
- **No password grant.** No endpoint takes a client's email and password and returns a token. A storefront that needs a
  signed-in client hands off to Mohar's own checkout (§9).

### 3. Step-up over the API: sensitive abilities need a hardened token

A token cannot confirm a password mid-call. ADR 0018 §4 allows one step-up mechanism and no other, so the API does not
invent an emailed approval or a second password. Instead, the requirement moves to the token:

- A staff token may hold a **sensitive** permission only if all of these hold:
    - it was created under step-up (already required for every token);
    - it has a non-empty IP allowlist;
    - it expires within 30 days;
    - it lists that permission by its exact key.

    A route guarded by a sensitive permission refuses any other token with 403 `auth.sensitive_requires_hardened_token`.
    This is the API equivalent of the `password.recent` middleware on the same web route.

- Every call to a sensitive route is audited with the token id, IP and request id. The first sensitive call from a new
  IP within the allowlist sends the owner a security notice.
- v1.0 exposes no sensitive route. Refunds, client sign-in links (SSO), credit adjustments and anything that reveals
  credentials arrive in v1.1, together with the hardened-token UI (spec §5). Endpoints that reveal stored credentials
  (`services.services.credentials`) are not offered at all.

### 4. Versioning and deprecation

- **The major version is in the path** (`/api/v1`). Within v1, only compatible changes ship:
    - new endpoints;
    - new optional parameters or filters;
    - new response fields;
    - new problem codes;
    - new values in enums documented as open.

    Clients must ignore unknown fields. Those are the ADR 0010 rules, applied to HTTP representations.

- **Breaking** means removing or renaming a field, path, parameter or code; changing a type or a documented meaning;
  making an optional input required; or adding a value to a closed enum. A breaking change ships only in `/api/v2`.
  When v2 ships, v1 keeps working for at least 12 months after v2 becomes generally available.
- **Deprecation inside a version:**
    - mark the operation or field `deprecated: true` in the OpenAPI document;
    - send `Deprecation` (RFC 9745) and, once a date is set, `Sunset` (RFC 8594) headers on every response that uses it;
    - add a kernel changelog entry.

    A deprecated element keeps working for at least 6 months, and in every case until the next major version.

- **Beta window.** Until the owner declares v1 generally available, v1 is beta. A breaking change is allowed then on
  the same conditions as ADR 0010's pre-1.0 window: the same PR updates the snapshot, gets the architect gate and
  carries a kernel changelog entry marked breaking. The OpenAPI document says `beta` in `info.description` until GA.
- The OpenAPI `info.version` is `1.<minor>.<patch>`. It is bumped by the release PR, never by a feature PR, as
  `Kernel::API_VERSION` is. It is separate from `Kernel::API_VERSION`, because PHP packages and HTTP clients are
  different audiences.

### 5. The API surface is part of the kernel contract

- ADR 0010's list of public surface gains two entries:
    - every operation and representation in `billing/docs/api/openapi-v1.json`;
    - the classes in `App\Http\Api` marked `@api`. These are the foundation a package uses to ship endpoints.
- `php artisan api:openapi` (Core, no module dependencies) generates the OpenAPI 3.1 document from routes,
  FormRequests and Resources, with all modules loaded. CI checks it the same way it checks `kernel-api.txt`:
    - it regenerates the document and fails if the result differs from the committed file;
    - it fails when the document changes without a kernel changelog fragment;
    - `api:openapi --diff <base>` lists breaking changes against the last released document, and CI fails on any of
      them after GA (before GA, unless the fragment is marked breaking).
- **Generator.** The candidate is `dedoc/scramble` (it infers from FormRequests and API Resources), which needs ADR
  0007 approval. Generated output may need hints in code (PHPDoc array shapes, attributes). If Scramble is not
  approved, we write a small generator of our own driven by attributes on Resources and FormRequests. Either way, the
  committed JSON is the contract, not the tool.
- **Docs.** The JSON is served at `GET /api/v1/openapi.json` without authentication (it contains no secrets). The
  readable reference is published on the docs site (`docs-site.md`) from the same file. Mohar serves no interactive
  console, because that would need third-party scripts under the CSP.

### 6. Resources, naming and representation

- **Paths** use lowercase plural nouns and hyphens (`/invoices`, `/pay-methods`). Collections are flat, not nested
  under a client. A client's invoices are `/invoices?filter[client_id]=12`. The only nesting is for things that do not
  exist outside a parent (`/invoices/{id}/items`, `/tickets/{id}/replies`).
- **State transitions** are `POST /{resource}/{id}/<verb>` (`/orders/{id}/accept`, `/services/{id}/suspend`). Each
  maps to one Action. Updates are `PATCH` with partial bodies. There is no `PUT`.
- **Field names** are snake_case. Every object carries `"object": "<type>"`, using the same entity names as webhook
  types (`invoice`, `service`).
- **Ids** are the integer primary keys. Human numbers (`invoice.number`, `order.number`) are separate fields. Clients
  treat ids as opaque.
- **Money** is `{"amount": 1130000, "currency": "NPR"}`: integer minor units and an ISO code, never floats or
  formatted strings. A currency's exponent is available from `/currencies`. Totals carry their own currency.
- **Time** follows ADR 0013:
    - instants are ISO 8601 in UTC with `Z` (`2026-10-29T04:15:00Z`);
    - company-calendar dates (`due_date`, `next_due_date`) are `YYYY-MM-DD`, never converted to an instant;
    - date filters on calendar fields take `YYYY-MM-DD` in the company calendar;
    - instant filters take ISO 8601 with an offset.
- **Enums** use the stored snake values (`unpaid`, `pending_review`). Each enum in the document says whether it is open
  or closed.
- **Envelope**:
    - single object: `{"data": {...}}`;
    - list: `{"data": [...], "meta": {"has_more": true, "next_cursor": "..."}, "links": {"next": "..."}}`.

    These are Laravel API Resources with their default wrapper.

- **Expansion** is `?include=items,payments`, with an allowlist per endpoint. An unknown value returns 400
  `request.invalid_include`. Sparse fieldsets are not supported.
- **One representation per entity.** The `Http/Resources/Api/<Entity>Resource` a module owns is also the `data.object`
  its webhooks send for that entity. ADR 0010 already split webhook payloads from merge-field resolvers. This ADR makes
  the webhook resource and the API resource the same class, so an integrator learns one shape. The PR that ships an
  entity's API resource moves that entity's webhook payload onto it, and amends `notifications.md` §8 to say which
  `api_version` envelope carries the v1 shapes.
- **Secrets never leave.** No representation carries passwords, API tokens, EPP codes, full card data or gateway
  credentials. `HygieneTest` gets a check over `Http/Resources/Api` that reuses the event secret-name list (ADR 0011).

### 7. Lists: cursor pagination, filters and sorting

- **Cursor pagination only.** Parameters are `limit` (default 25, maximum 100) and `cursor`, an opaque value taken from
  `meta.next_cursor`. The cursor encodes the sort key and the id, so rows inserted or changed during paging are not
  skipped or repeated. There is no offset paging and no total count by default. A count endpoint can be added later
  when a real consumer needs one.
- **Filters** are `filter[<name>]=<value>`. Each endpoint declares its filters, and every filter is backed by an index.
  An unknown filter returns 400 `request.invalid_filter`, never a silently unfiltered list. Every collection that
  integrations sync offers `filter[updated_since]` and `sort=updated_at`.
- **Sort** is `sort=<field>` or `sort=-<field>`, from a per-endpoint allowlist. The default is `-id`, and `id` is
  always the tie-breaker.
- One shared `App\Http\Api\ListQuery` implements the filter, sort and cursor parsing. A module declares its allowlists
  and does not write its own pagination.

### 8. Errors: RFC 9457 problem details with stable codes

- Every error under `/api` is `application/problem+json`, with these members:
    - `type`: a relative URI `/api/problems/<code>`, documented on the docs site;
    - `title`;
    - `status`;
    - `detail`;
    - `code`: the stable machine key, `<area>.<reason>`;
    - `request_id`;
    - for validation failures, `errors: [{"field": "items.0.amount", "code": "...", "message": "..."}]`.

    `code` is the contract, while `title` and `detail` are human text that may change and may be translated once the
    frontend translations ADR lands. Exception messages that are not problem text never reach the client.

- **Kernel codes**, owned by `App\Http\Api`:

    | Code                                          | Status |
    | --------------------------------------------- | ------ |
    | `auth.unauthenticated`                        | 401    |
    | `auth.token_expired`                          | 401    |
    | `auth.token_revoked`                          | 401    |
    | `auth.token_in_url`                           | 400    |
    | `auth.forbidden` (carries `required_ability`) | 403    |
    | `auth.wrong_audience`                         | 403    |
    | `auth.ip_not_allowed`                         | 403    |
    | `auth.sensitive_requires_hardened_token`      | 403    |
    | `request.validation_failed`                   | 422    |
    | `request.invalid_filter`                      | 400    |
    | `request.invalid_include`                     | 400    |
    | `request.invalid_cursor`                      | 400    |
    | `resource.not_found`                          | 404    |
    | `resource.conflict`                           | 409    |
    | `resource.precondition_failed`                | 412    |
    | `idempotency.key_required`                    | 400    |
    | `idempotency.key_reused`                      | 422    |
    | `idempotency.in_progress`                     | 409    |
    | `idempotency.outcome_unknown`                 | 409    |
    | `rate_limited`                                | 429    |
    | `server.error`                                | 500    |
    | `server.unavailable`                          | 503    |

- **Module codes** use the module's area (`billing.invoice_not_payable`, `orders.not_pending`). A module maps its
  domain exceptions to codes in a renderer it registers for API requests. That follows the existing rule that kernel
  exceptions know nothing about HTTP (`architecture.md`, "Business logic").
- **No existence leaks.** A client token asking for another account's record gets 404, not 403. A staff token missing
  the ability gets 403, because staff may know the record exists.

### 9. Idempotency: required on calls that move money or provision

- Callers send an `Idempotency-Key` header: 1 to 255 visible ASCII characters, with a UUID recommended. It is
  **required** on these operations, each marked `x-idempotency: required` in the OpenAPI document:
    - recording a payment;
    - capturing a payment;
    - adding or applying credit;
    - creating an invoice;
    - creating an order;
    - checking out a cart;
    - starting a payment session;
    - the service commands (create, suspend, unsuspend, terminate);
    - refunds (v1.1).

    A call without the key gets 400 `idempotency.key_required`. On every other `POST` and `PATCH` the key is accepted
    and honoured but optional.

- **Store.** Core owns the `api_idempotency_keys` table: owner scope, key, method and route name, a SHA-256 of the
  canonical request body, `state` (`in_progress`, `completed`), the response status and body, `created_at` and
  `expires_at` (24 hours). The scope is the token's principal (owner user id, kind and client id), not the token, so a
  rotated token cannot charge twice. A unique index on (scope, key) settles races.
- **Flow.** The middleware `api.idempotent`:
    1. inserts an `in_progress` row in its own short transaction;
    2. runs the endpoint;
    3. stores the response once the Action's transaction has committed.

    The outcomes:
    - **Same key, same body:** the stored response is replayed, with `Idempotent-Replayed: true`.
    - **Same key, different body:** 422 `idempotency.key_reused`.
    - **Same key while the first call still runs:** 409 `idempotency.in_progress`.
    - **Validation or authorisation fails before the Action runs:** the row is deleted, so the caller can fix the
      request and retry with the same key.
    - **The Action started and failed (any status, 5xx included):** the response is stored and replayed. A retry
      never runs a payment a second time just because the first attempt crashed.
    - **The row stays `in_progress` past the lock window (120 seconds; the process died):** the call gets 409
      `idempotency.outcome_unknown`, with a link to the resource. ADR 0026 applies: the caller reads the resource,
      whose status (`pending_review`, an attempt marked ambiguous) says what happened.

- **Domain keys underneath.** The HTTP key does not replace action-level idempotency. Where an Action already takes a
  key (`CheckoutData::$idempotencyKey`, the credit ledger), the API passes `api:<scope hash>:<key>`, so the database
  constraint still holds if the HTTP store is lost. An Action that moves money and lacks a domain key gets one in the
  same PR that exposes it.
- Response bodies are stored only after the Resource has run, and Resources never hold secrets (§6), so nothing secret
  is stored. Rows are pruned daily by an `Automation` housekeeping task.

### 10. Cross-cutting rules

- **Rate limits.** Named limiters keyed by token (or by IP when there is no token):

    | Limiter     | Applies to                          | Default per minute |
    | ----------- | ----------------------------------- | ------------------ |
    | `api`       | all calls                           | 120                |
    | `api-write` | non-GET calls                       | 60                 |
    | `api-money` | idempotency-required calls          | 20                 |
    | `api-guest` | storefront without a client session | 60 per IP          |

    Defaults are settings (`api.rate_limits.*`, Admin → Settings → API), with an optional per-token override that may
    only lower them. Responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`. A 429 carries `Retry-After` and
    the `rate_limited` problem. Fiscal PDF downloads keep their own stricter limiter (security review 10-29, M3).

- **Audit.** Every non-GET call writes one `ActivityLogger` entry through the Action, as the UI does. `ActivityLogger`
  also records `channel: api`, the token id and the request id when the request came through a token (a Core change;
  no new log). GET calls are not audited one by one. Token create, revoke and expiry, refusals for a sensitive ability,
  and IP-allowlist refusals are audited.
- **`last_used_at` is written at most once a minute per token**, not on every request (ADR 0020, hot path).
- **Transactions and events.** Responses are built after the Action's transaction commits. Events still dispatch after
  commit (ADR 0011). An API call triggers the same mail and webhooks as the same UI action, and a parameter exists only
  where the admin form offers the same choice (for example `send_email` on recording a payment).
- **Queued work.** A command that the Action queues (provisioning) answers 202 with the resource in its current state.
  The caller follows progress with `GET`, or with the webhook for the outcome. No separate "operation" resource is
  added.
- **Concurrency.** Single-object `GET`s return a weak `ETag`. `PATCH` accepts `If-Match` and answers 412
  `resource.precondition_failed` on a mismatch. ADR 0021 row locks still guard the Action itself.
- **Caching.** Authenticated responses send `Cache-Control: no-store`. `GET /api/v1/openapi.json` and public
  storefront catalogue reads may be cached for 60 seconds.
- **Observability.** Every API request emits one log line with these fields:
    - route name;
    - principal kind and owner id;
    - token id (never the secret);
    - status;
    - duration;
    - query count;
    - request id (`X-Request-Id`, accepted from the caller when it is a valid UUID, otherwise generated).

    The same values become span attributes when OpenTelemetry lands (ADR 0014).

- **Performance budget.** On the small sizing profile (`billing/docs/devops/sizing.md`, 40 users):
    - a single-object GET has p95 under 200 ms;
    - a 100-row list has p95 under 400 ms;
    - list endpoints use a constant number of queries whatever the page size, checked by a query-budget test like the
      reports budget (ADR 0020 Amendment (#515)).

    The load-test harness gains an API scenario before GA.

- **CORS.** The admin and account roots send no CORS headers. The storefront root does not enable CORS in v1: it is
  called server to server (§11).
- **Settings, not config**, for anything an administrator changes: whether the API is on, rate limits, the default
  token expiry. The `api.enabled` setting defaults to on for staff tokens, and when it is off every `/api/v1` route
  answers 503 `server.unavailable` except callbacks. Operator-level values (token prefix, idempotency window) are
  config.

### 11. Headless cart and payments (v1.1)

WHMCS's v2 storefront API carries session and cart state in a signed header that every response replaces. We do
something simpler:

- A **storefront key** (a staff token with only `orders.storefront.use`) is held by the custom site's backend. The
  browser never sees it.
- **Carts are server-side records** (the existing cart table behind `CartManager`), addressed by an unguessable cart id
  that the storefront stores. `CartManager` gains a session-free entry point.
- **Guest checkout** creates the client through the same `RegisterCheckoutClient` and fraud screening as the web
  checkout. It requires `Idempotency-Key` and the `api-money` limiter.
- **An existing client** is handed off with `POST /storefront/carts/{id}/handoff`. It returns a single-use URL (60
  seconds) to Mohar's own checkout with the cart attached. There the client signs in normally, with 2FA and every
  sign-in rule (ADR 0018). A storefront API never handles client passwords.
- **Payment** reuses `StartHostedPayment`. Checkout returns the order, the invoice and a `next_action` (a redirect URL,
  or the QR payload the gateway declares). `POST /account/invoices/{id}/payment-sessions` does the same for an unpaid
  invoice ("payment links").
- **Browser-direct storefront calls** (CORS, a public key) wait for the captcha verifier contract on the roadmap.

### 12. No WHMCS-compatible API shim in v1

We do not ship an endpoint that mimics the WHMCS RPC actions. The reasons:

- It would tie us to RPC form POSTs, credentials in the body, offset paging and free-text errors. Those are what this
  ADR avoids.
- It would mean matching about 162 actions' parameter semantics, and that would lock our domain model to theirs.
- A switching host's main need, moving data, is covered better by the WHMCS API importer (`migration.md`). That
  importer _reads_ their API.

What we do instead:

- The developer docs carry a mapping table from WHMCS action names to Mohar endpoints, written in our own words. Using
  action names for compatibility is allowed by the boundary in `CLAUDE.md`; copying their text is not.
- An optional compatibility package that implements the most-used actions on top of the public API stays possible. It
  would be a separate package under `/api/v1/ext/whmcs-compat`, built only if hosts ask for it (P2, owner decision).

## Consequences

- Integrators get a stable, documented API, and our UI and API cannot drift, because both call the same Actions.
- The API is mostly one PR per module after one foundation PR, so module PRs can run in parallel.
- Two new dependencies need approval (Sanctum, and Scramble or a generator of our own). The committed OpenAPI file
  becomes a second generated contract next to `kernel-api.txt`.
- The three roots mean some duplication (an invoice appears under `/invoices` and `/account/invoices`). Each root's
  controller calls the same query or Action. Resources are shared, with fields hidden by audience where needed.
- Sensitive operations wait for v1.1 and the hardened-token UI. v1.0 cannot issue refunds or SSO links.
- Webhook payloads move onto the API Resources entity by entity, which changes some webhook payload fields. Each such
  move is a compatible addition, or a change to the webhook `api_version` noted in its PR.

## Alternatives rejected

- **A WHMCS-style RPC endpoint**, or a shim as the main API: see §12.
- **Building the admin UI on the API** (FOSSBilling): this would double the work of every UI change and give up
  Inertia's server-driven pages. Shared Actions give the same consistency.
- **Separate API roles** (WHMCS): a second permission system that could drift from staff roles. Abilities as
  permission strings, checked against the live role, cannot exceed what the person may do in the UI.
- **Our own token table without Sanctum:** possible (about the same schema), but Sanctum is first-party and already
  solves hashing, the guard and abilities. Owner decision under ADR 0007.
- **Offset pagination:** it skips or repeats rows on live data, the problem `migration.md` §2.1 works around.
- **Date-pinned versions per account** (Stripe style): too much machinery for our size. A path major version plus
  additive-only changes is enough.
- **A signed rotating state header for carts** (WHMCS v2): complex for storefronts and hard to cache. Server-side carts
  addressed by id are simpler.
