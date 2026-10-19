# ADR 0016 — Integration simulators and the end-to-end scenario

- Status: Accepted · 2026-10-18 · Owner: Principal architect · Decided by the CTO (see "CTO decisions")
- Related: ADR 0005 (quality gates), 0006 (PostgreSQL gate), 0007 (pinning and cooldown), 0009 (extension contracts),
  0013 (company clock), 0015 (outbound connection policy); `docs/handoff/product-standards.md` "Simulators and test
  doubles"; PRs #58 (Stripe), #69 and #73 (cPanel/WHM).

## Context

The owner decided on 2026-10-18 that there will be no WHM server, no Stripe test account and no production server for
now. Integrations are to be verified against local simulators built from each provider's public API reference, running
the real flow. The existing smoke commands (`provisioning:cpanel-smoke`, the Stripe smoke prompt) stay for when real
accounts exist.

Today each driver is tested with `Http::fake` and hand-written fixtures. That proves the parsing, but not the
transport: TLS, the address guard, SPKI pinning, signed callbacks crossing a real socket, and a state that changes
between calls (create, then the verify sweep finds the account). It also cannot prove that an order becomes a paid
invoice and then a live account.

## Decision

### 1. One framework, one provider simulator per integration

The framework is a first-party, dev-only Composer path package, `mohar/simulators`, in `billing/tools/simulators/`
(namespace `Mohar\Simulators\`). It is required only in `require-dev` and contains no third-party code.

Shared pieces live in `src/Kernel/`:

- **TlsFront.** A pure-PHP TLS terminator using `stream_socket_server('tls://…')`, which pipes bytes to a `php -S`
  backend running with `PHP_CLI_SERVER_WORKERS=4`. `php -S` handles HTTP; the front handles certificates and the
  transport-level faults.
- **ScenarioCa.** Creates a CA and leaf certificates for each scenario (SAN `IP:127.0.0.1` plus a DNS name) with
  `openssl_*`. It can rotate a leaf on request.
- **StateStore.** One SQLite file per scenario in `sys_get_temp_dir()/mohar-sim/<scenario-id>/`, opened with WAL and a
  busy timeout. It is never the app database.
- **RequestLog.** Every request and response, redacted with the same keys as the kernel `Redactor`. Tests assert on it
  ("exactly one `createacct`").
- **FaultPlan.** Faults keyed by provider, operation and nth call:
    - `latency(ms, jitter)`;
    - `status(5xx, body)`;
    - `slow_body` (trickle);
    - `commit_then_hang(seconds)`, the timeout after commit;
    - `reset_before_commit`;
    - `truncate`;
    - `rate_limit`;
    - `rotate_certificate`.
- **CallbackDispatcher.** Signed server-to-app deliveries (webhooks, inbound mail). It supports replay, reordering,
  delay, stale timestamps and a wrong secret.
- **ControlPlane.** `/__sim/*` on a second, plain-HTTP port bound to 127.0.0.1 and gated by a per-scenario token. It is
  never on the API port, so a driver cannot reach it.
- **Recorder.** Described below.
- **SimulatorRegistry.** `simulate:*` commands and the E2E suite discover simulators only through it.

Each provider implements `Mohar\Simulators\Contracts\Simulator`:

- `key()`;
- `reference()`: the public API reference URL and the version it follows;
- `routes()`;
- `seed(Scenario)`;
- `appConfiguration(Scenario)`: the env lines, server rows or gateway settings the app needs;
- `operations()`: the names that faults can target.

**Fidelity.** Responses follow the public reference, written in our own words and shapes, never copied from provider
SDKs or docs. Each simulator ships conformance tests in `tools/simulators/tests/`. They replay the driver's existing
fixtures (`tests/Fixtures/Cpanel`, `tests/Fixtures/Stripe`, …) as the expected shapes.

**Recorder.** `simulate:record <provider>` runs the provider's operation script against a real sandbox, using only
credentials from the environment. It stores redacted responses in `tools/simulators/<provider>/recordings/`, tagged
`source: live`. Recordings written from the docs are tagged `source: docs`. A shape check compares the simulator with
the recordings: key paths, types, error codes and reasons. It is how we audit a simulator once an account exists. Until
then every recording is `source: docs`.

### 2. Provider coverage

| Simulator                         | Speaks                                                                                         | Notable behaviour                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------- | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `whm`                             | WHM API 1 `json-api/<fn>?api.version=1`, only the 17 functions the driver uses                 | `Authorization: whm user:token`; root vs reseller ACLs (`myprivs`, account ownership, the packages a reseller may use); username rules from the reference in one table; duplicate domain and username; realistic `metadata.reason`; chunked `listaccts`; `create_user_session` URL served by the simulator                                                      |
| `stripe`                          | `/v1` Checkout Sessions (create, retrieve), PaymentIntents, Charges, Refunds, Disputes, Events | Bearer `sk_test_…`; the `Stripe-Version` header is honoured and unknown versions are refused; `Idempotency-Key` replays the stored response, and a different body gives `idempotency_error`; hosted checkout page with Pay, Decline and Abandon; `t=…,v1=…` HMAC-SHA256 webhooks with the configured secret; disputes and refunds raised from the control plane |
| `khalti`                          | KPG-2 `epayment/initiate`, `epayment/lookup`, merchant refund                                  | Hosted page; return query string followed by an authoritative lookup; statuses pending, expired, user-cancelled and refunded                                                                                                                                                                                                                                    |
| `esewa`                           | ePay v2 form post, signed base64 return, transaction status                                    | HMAC-SHA256 `signed_field_names` signature; tampered and unsigned returns                                                                                                                                                                                                                                                                                       |
| `inbound-mail`                    | Sender to the Support generic signed webhook                                                   | Signed posts, replays, oversized and malformed MIME                                                                                                                                                                                                                                                                                                             |
| `webhook-receiver`                | Receiver for Notifications outbound webhooks                                                   | Checks the signature; answers 2xx, 4xx, 5xx or slowly to drive retries. In process only (see Limits)                                                                                                                                                                                                                                                            |
| `resellerclub`                    | LogicBoxes HTTP API used by the driver                                                         | `actionstatus` async, IP allow-list refusal, demo-style reset                                                                                                                                                                                                                                                                                                   |
| `razorpay`, `paypal`, `whmcs-api` | Built with their drivers and the importer                                                      | Same framework; specified in each driver's PR                                                                                                                                                                                                                                                                                                                   |

`.np` registration is manual, so it gets no simulator.

### 3. Pointing drivers at simulators without weakening them

- **cPanel: no product change.** The scenario creates a server row with host `127.0.0.1` and the simulator's port. It
  presses "Test connection", which shows the certificate as untrusted, then "Trust this certificate" through the real
  sensitive-tier and step-up flow. The SPKI pin, the guard's resolve and pin, the port policy and the verify sweep all
  run unchanged. `rotate_certificate` must then produce the driver's "server certificate changed" failure.
- **Fixed-host providers (Stripe, Khalti, eSewa, ResellerClub, Razorpay, PayPal)** need one kernel helper,
  `App\Support\Integrations\Endpoints`:
    - Drivers always call `Endpoints::for('stripe.api', config('payments_stripe.api'))`, which returns the URL and its
      request options together. They do this whether or not an override exists, so there is no simulator branch in
      driver code.
    - Overrides come only from the environment: `INTEGRATION_ENDPOINTS` (a JSON map) and `INTEGRATION_CA_BUNDLE`. They
      are never database settings, so staff cannot change them.
    - With no override, the endpoint is the default and its options are `[]`: behaviour is byte-for-byte the current
      behaviour.
    - With an override (https only, no credentials, query or fragment), the options add `verify => <scenario CA
bundle>`, so TLS and hostname checks stay on. The destination is also resolved and pinned through
      `OutboundAddressGuard`, so loopback is reachable only when `OUTBOUND_ALLOW_LOOPBACK` allows it.
- **Fail closed.** Overrides are honoured only when the app runs as `local` or `testing` (S0 review on #77). In every
  other environment, including `production`, `staging`, an empty or an unknown name, `Endpoints` ignores every
  override and logs `critical` once per boot with the keys only. The health check and `app:install` report the
  override as an error (S1). In development and tests, active overrides are logged at `warning` at boot.
- **Guard tightening (kernel, amends ADR 0015).** Loopback is refused outside `local` and `testing` whatever
  `OUTBOUND_ALLOW_LOOPBACK` says. It stays off by default everywhere except `APP_ENV=local`.
- **Signatures and settlement stay real.** Signature checks, timestamp tolerance, idempotency and `lookup()` settlement
  run as in production. A return or webhook alone never settles a payment. The simulator must answer `lookup()`.

### 4. End-to-end scenario

There are two runners over one scenario definition, `tests/Simulators/Scenarios/HostingLifecycle.php`:

- **Pest suite `Simulators`.** A new testsuite in `phpunit.xml` that is excluded from the default run. The app runs in
  process. Simulators run as real TLS processes started by a `SimulatorHarness` trait. Callbacks are pulled from the
  dispatcher's outbox and posted to the app with their exact signed bytes and headers. Time moves with
  `travelTo()` in company time (ADR 0013).
- **`php artisan simulate:e2e`.** The scripted demo uses the full process topology:
    - `artisan serve` on a free port;
    - `queue:work`;
    - simulators delivering callbacks over HTTP;
    - a fresh PostgreSQL database `billing_e2e_<id>`, which is dropped afterwards.

    Overdue time is produced by backdating invoice rows in that throwaway database, then running `automation:run`. The
    demo prints each step and leaves `--keep` state for screenshots.

Steps:

1. `app:install` without demo data.
2. Create a cPanel server, trust its certificate, and create a product linked to a WHM package.
3. The client orders and pays through Stripe Checkout on the simulator. Settlement comes from the webhook plus
   `lookup()`.
4. The invoice is paid, the service becomes active, and the `whm` state contains the account with the right package
   and owner.
5. SSO through `create_user_session`. This step is enabled in cPanel PR 2, which ships SSO.
6. Overdue, then suspension (`suspendacct`), then payment, then unsuspension.
7. Cancellation, then termination (`removeacct`, and the account is gone).
8. A refund and a dispute from the control plane, reflected on the transaction and the invoice.

Fault cases:

- `createacct` with `commit_then_hang`: the attempt is `unknown`, and the verify sweep adopts the account only with
  proof. A foreign account with the same name is never adopted.
- A duplicate webhook is settled once.
- A forged webhook (wrong secret) and a stale one are refused, with no state change.
- An out-of-order `charge.refunded` arrives before `checkout.session.completed`.
- A rotated WHM certificate is refused.
- With the NPR variant (after the Nepal PR), a tampered eSewa return is refused.

### 5. Guarantees and how each is enforced

| #   | Guarantee                     | Enforced by                                                                                                                                                                                                                                                                   | Tested by                                                                                                                                                                                                                 |
| --- | ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Zero production footprint     | The code is outside `app/` in a `require-dev` path package. Its provider is registered only by package discovery and does nothing unless the app runs as `local` or `testing`. Each command re-checks in `handle()`.                                                          | An Arch test checks that `App\` never uses `Mohar\Simulators`. A CI step installs with `composer install --no-dev`, boots `artisan list`, and asserts there are no `simulate:*` commands and no class from the namespace. |
| 2   | No weakened security paths    | There is no override for host policy, pinning or signatures. Only `Endpoints` exists, and it adds the guard. cPanel uses the trust flow.                                                                                                                                      | E2E fault cases for a forged webhook, a rotated certificate and a refused loopback with `OUTBOUND_ALLOW_LOOPBACK=false`. An Arch test checks that no driver mentions `simulat`.                                           |
| 3   | Narrow, fail-closed overrides | Overrides come from the environment only. They are ignored in production, with a critical log and a health error, and logged at boot elsewhere.                                                                                                                               | Unit tests on `Endpoints`: in production the override is ignored; the key is logged; no settings key exists.                                                                                                              |
| 4   | Isolation                     | The state store is in the temp dir. Ports are ephemeral, and 8000 is refused. The E2E database is `billing_e2e_*`, and the harness refuses `database/preview.sqlite` and the dev database name. A `finally` block cleans up, and `simulate:clean` reaps leftovers by pidfile. | A harness test with the forbidden port and database names. A teardown test checks that no process or temp dir is left.                                                                                                    |
| 5   | No behaviour change           | The product diff is limited to `Endpoints`, the guard tightening and one call site for each fixed-host driver. These go through kernel review.                                                                                                                                | Every existing test passes unchanged. A no-override test checks that the request options are identical.                                                                                                                   |
| 6   | Bounded CI                    | A separate `e2e-simulators` job with PostgreSQL and `timeout-minutes: 10`, and the scenario budget asserted at 6 minutes. The main job never loads the `Simulators` testsuite.                                                                                                | The job's own check name keeps it apart, so a simulator failure cannot hide a main-suite failure. It is non-required until it has passed 10 consecutive runs, then required.                                              |
| 7   | Rollback                      | Delete `tools/simulators`, the `require-dev` line, `tests/Simulators` and the CI job. `Endpoints` with no override is a no-op.                                                                                                                                                | The `--no-dev` boot step already proves that the app runs without the package.                                                                                                                                            |

### 6. `stripe/stripe-mock`

We do not adopt it now. It is stateless, so it cannot settle, deliver webhooks or replay idempotency. It needs a new
Docker image under ADR 0007. Our simulator is stateful and reviewable PHP. If request-shape drift becomes a problem, we
will vendor the relevant paths of Stripe's published OpenAPI spec at a pinned commit and validate our requests in the
conformance tests. That is a data file, not running code, but it still goes through owner approval.

## Delivery

| PR  | Scope (≈ lines)                                                                                                                                                        | Depends on                      |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| S0  | Kernel: `Endpoints::for()`, loopback and overrides only in `local` and `testing`, Arch rules (`Mohar\Simulators`, no "simulat" in drivers), ADR 0015 amendment (≈ 400) | —                               |
| S1  | Framework: kernel pieces, registry, commands, `--no-dev` CI step, health check and `app:install` error for an override outside development (≈ 2,200)                   | S0                              |
| S2  | `whm` simulator and conformance tests against the #69 fixtures (≈ 2,000)                                                                                               | S1, #69 and #73 merged          |
| S3  | `stripe` simulator, hosted checkout, webhooks; PaymentsStripe adopts `Endpoints` (≈ 2,600)                                                                             | S1, #58 merged                  |
| S4  | E2E: `Simulators` suite, `simulate:e2e`, CI job (≈ 1,800)                                                                                                              | S2, S3; SSO step in cPanel PR 2 |
| S5  | `khalti` and `esewa`; PaymentsNepal adopts `Endpoints`; NPR scenario (≈ 2,200)                                                                                         | S4                              |
| S6+ | `inbound-mail` and `webhook-receiver`, then `resellerclub`, `razorpay`, `paypal`, `whmcs-api`, each with its driver                                                    | S1                              |

### S1 as delivered

- `tools/simulators/src/`: `Contracts` (`Simulator`, plus the optional `SignsCallbacks`, `HasControlActions`,
  `RecordsLive`), `Http` (request, response, route, the API kernel that applies faults and logs each call, the
  token-gated control plane), `Kernel` (TlsFront, ScenarioCa, StateStore, RequestLog, FaultPlan, CallbackDispatcher,
  Recorder, Scenario, Processes), the registry and the `simulate:list|start|stop|clean|record` commands. The guide for
  adding a provider is `tools/simulators/README.md`.
- Transport faults (`reset_before_commit`, `truncate`, `slow_body`) are carried from the API backend to the TLS front
  in `X-Sim-*` response headers, which the front strips. A fault without an nth call fires on every call; one with an
  nth fires once.
- The `simulate:e2e` command, the `Simulators` testsuite and the harness refusing the preview and dev databases come
  with S4; the pre-commit scan over `recordings/` comes with the first provider that ships recordings (S2).
- Isolation (PR #92 review):
    - Scenarios live under a root per checkout and per parallel test worker.
    - A scenario a test starts stops itself when its owner process is gone (TLS front watchdog).
    - `simulate:clean` never touches a scenario another live process owns.
    - Pids are signalled only when `/proc` shows they are the scenario's own.
    - The scenario CA is name-constrained to `127.0.0.1` and `localhost`, and its keys are 0600.
- Health: `app:install` fails and the dashboard health check is red when `INTEGRATION_ENDPOINTS` is set outside
  `local`/`testing`; in development the health check is amber and names the active keys.

## Limits: what simulators cannot prove

- That the provider really behaves as we read its reference: undocumented fields, error wording, rate limits, real
  latency, and version drift. Recordings from real accounts close this gap.
- Real DNS, public CAs, certificate chains and the network path. Proxies are bypassed by design (ADR 0015).
- Stripe's real dashboard configuration, Radar, 3-D Secure challenges, payouts, and live-mode key restrictions.
- WHM's real resource limits, server-side hooks, licence state, the cPanel login page behind SSO, and IP allow-lists.
- Outbound webhooks over a real socket: `WebhookUrlGuard` refuses loopback and private addresses whatever the
  environment, and we will not add an exception.
- Production topology: workers, Horizon, cron and multiple app nodes.

The real smoke runs must still check all of this later:

- `provisioning:cpanel-smoke` against a real WHM: root and reseller tokens, the full lifecycle, SSO in a browser, and
  the certificate pin.
- The Stripe smoke command in test mode, with webhooks through the Stripe CLI or a public endpoint, a refund, and a
  test-clock dispute.
- Khalti and eSewa in their UAT environments.
- `simulate:record` for each provider, with every `source: docs` recording replaced or confirmed.

## Risks and containment

- **Simulator drift gives false confidence.** Recordings carry a source tag, the Limits section is explicit, and real
  smoke runs are required before go-live.
- **Simulator bugs mask product bugs.** Simulators have their own conformance tests and live in a separate testsuite,
  and the existing fixture tests stay.
- **An override leaks into production.** It is ignored and logged as critical, the health check is red, and there is no
  database path for it.
- **Loopback policy is misused.** Production refuses loopback, the default is off, and the E2E sets the policy
  explicitly.
- **Flaky multi-process CI.** Ports are ephemeral, there are readiness probes, and the job has its own time budget and
  stays non-required until it is stable.
- **A shared codespace.** Commands run through `scripts/heavy.sh`, and port 8000 and the preview database are refused.
- **Clean-room.** Responses are written by us from the reference, and no SDK or docs text is copied.
- **Leaked secrets in recordings.** The kernel `Redactor` and a pre-commit scan run over `recordings/`.

## CTO decisions (2026-10-18)

1. `mohar/simulators` as a first-party Composer path package in `require-dev` is approved. It contains only our own
   code, so the ADR 0007 cooldown (which governs third-party releases) does not apply; any third-party dependency it
   ever needs goes through ADR 0007 as usual.
2. S0 is approved, including the ADR 0015 amendment: loopback is refused in production even when
   `OUTBOUND_ALLOW_LOOPBACK` is set.
3. The end-to-end scenario is HTTP-level. A browser-level run (Pest browser plugin, Playwright) needs new dependencies
   and the owner's approval; it is a later step.
4. `stripe/stripe-mock` is not adopted.
5. The end-to-end CI job is non-required until it passes 10 consecutive runs, then becomes required.
6. Outbound webhooks are simulated in process; `WebhookUrlGuard` gets no loopback or private-address exception.

## Consequences

- Every integration gets a reproducible proof of the real flow before real accounts exist.
- The product changes are one kernel helper and one tightened guard rule.
- Each new driver PR carries its simulator, which adds about 1,000–2,000 lines per provider.
- Go-live still waits on the real smoke runs listed above.
