# mohar/simulators

Dev-only provider simulators (ADR 0016). This package is required only in `require-dev` and does nothing unless
`APP_ENV` is `local` or `testing`. Nothing under `app/` may reference it.

## Running one

```sh
php artisan simulate:list                  # installed simulators and the reference each follows
php artisan simulate:start whm             # prints the API URL, the control plane, the CA bundle and the env lines
php artisan simulate:stop <scenario-id>    # --keep leaves the state directory for inspection
php artisan simulate:clean                 # stop every scenario and remove leftovers
```

Each scenario runs as three processes on free 127.0.0.1 ports (never 8000):

- a TLS front with a leaf certificate signed by the scenario's own CA;
- the API backend (`php -S`, four workers);
- the control plane (`php -S`, plain HTTP).

State lives in `sys_get_temp_dir()/mohar-sim/<checkout>/<id>/`. `<checkout>` is a hash of this checkout's path, with `-w<TEST_TOKEN>` added for a parallel test worker. A run in one worktree or worker never sees, stops or removes another's.

Lifetimes:

- A scenario started from code (`Processes::start()`, as tests do) belongs to the process that started it. The TLS front checks every second that this owner is alive, and stops the scenario when it is gone, so a killed test runner leaves nothing running.
- `simulate:start` starts detached scenarios, which run until `simulate:stop` or `simulate:clean`.
- `simulate:clean` reaps detached scenarios, scenarios whose owner is gone, and leftovers. It never touches a scenario another live process owns.
- Tests stop only the scenarios they started.

A scenario whose process exits at start fails at once: the error carries the tail of its logs, and the scenario directory is kept. Pids are signalled only while `/proc/<pid>/environ` shows they belong to the scenario, so a pid reused after a reboot is never hit.

The package needs Linux: `setsid`, `/proc` and ext-posix.

The control plane answers only requests that carry `X-Sim-Token`:

| Method and path                  | What it does                                                       |
| -------------------------------- | ------------------------------------------------------------------ |
| `GET /__sim/health`              | Liveness                                                           |
| `GET /__sim/log?operation=`      | Redacted request log, oldest first                                 |
| `POST /__sim/faults`             | `{"operation", "type", "params", "nth"}`; types are `Fault::TYPES` |
| `DELETE /__sim/faults`           | Clear the plan                                                     |
| `POST /__sim/rotate-certificate` | New leaf from the same CA (the pin changes)                        |
| `GET /__sim/outbox`              | Pending callbacks                                                  |
| `POST /__sim/actions/{name}`     | A simulator's own actions (`HasControlActions`)                    |

## Simulators in this package

### `whm`: cPanel & WHM, for the cPanel driver

- **API.** WHM API 1 for the 17 functions the driver calls, plus the login links that `create_user_session` returns, served on the same TLS port.
- **Seed.** Created on `simulate:start whm`:
    - `root`, with token `WhmSimulator::ROOT_TOKEN` and full access;
    - `reseller1`, with `RESELLER_TOKEN` and the driver's ACLs only: its own package `reseller1_starter`, a limit of 3 accounts, and no dedicated IPs delegated. Root's packages need the `viewglobalpackages` ACL on both the reseller and the token, as in WHM;
    - four packages, two accounts (`alpha` owned by root, `bravo` owned by the reseller), and two free dedicated IPs.
- **Rules.**
    - Usernames follow one table (`UsernameRules`).
    - A token never exceeds its user's privileges, and a reseller sees and changes only what it owns.
    - `removeacct keepdns=1` keeps the zone, which blocks the domain.
    - Every refusal happens before anything changes.
    - A token's `whitelist_ips` is enforced (the seeded tokens allow 127.0.0.1).
    - A request without `api.version=1` gets an API 0-style body with no metadata envelope, as WHM would answer.
- **Control actions.** `add_account` (someone else's account), `issue_token` (ACLs, expiry), `set_licence`, `set_usage`.
- **Using it from the app.** Add a cPanel server with host `127.0.0.1`, the scenario's port and a token. Set `OUTBOUND_ALLOW_LOOPBACK=true` (development only). Run Test connection, then "Trust this certificate".
- **Recording.** `simulate:record whm` makes read-only calls against a real WHM:
    - credentials come from `WHM_RECORD_HOST` and `WHM_RECORD_TOKEN`, and optionally `WHM_RECORD_PORT`, `WHM_RECORD_USER`, `WHM_RECORD_ACCOUNT` and `WHM_RECORD_CA_BUNDLE`;
    - anonymisation is deny-by-default:
        - a string is kept only under a known-safe key (WHM's reason, versions, statuses, flags, sizes, ACL names, dates);
        - in WHM's free text (the reason), any email, URL, address or host name is replaced;
        - every other string becomes a placeholder of its kind (IPv4, IPv6, email, URL, host name, `0` for a numeric string, `example`);
        - a key that could be data (a domain, an address, anything with a dot, @ or space) is renamed `key1`, `key2`, …;
    - each recording notes which login made it (`context.login`: root or reseller), so its replay uses the same;
    - everything is written to a private staging directory and scanned, and the files move to `whm/recordings/` (`source: live`) only when the whole run completes and passes, so a failed or leaking run saves nothing;
    - each recording keeps the shape of the answer taken before masking, so masking never hides a change of type.
- **Drift.** `tests/Whm/WhmDriftTest.php` replays every live recording against the simulator with the same call, and checks the shape and the refusal kind. It is skipped while no live recording exists.
- **Docs-sourced shapes.** Until a live recording exists, `tests/Fixtures/Cpanel` (written from the reference) is the `source: docs` set the conformance tests check.

### `stripe`: Stripe in test mode, for the PaymentsStripe driver

- **API (`/v1`).** `account`, `checkout/sessions` (create, retrieve, expire), `payment_intents`, `charges`, `refunds` (create, retrieve) and `disputes`, with `expand[]` along dotted paths.
    - Every call needs `Bearer StripeSimulator::SECRET_KEY`.
    - A `Stripe-Version` must be one the simulator knows.
    - A POST with an `Idempotency-Key` replays its first answer, and the same key with other parameters gets `idempotency_error`.
    - A request that fails parameter validation before it starts executing saves no idempotent result, as at Stripe, so the corrected retry with the same key runs. A refusal after it started (`charge_already_refunded`, `charge_disputed`) is saved and replayed.
    - Parameters are checked as Stripe checks them: three-letter currencies, at most 50 metadata keys of up to 40 characters with values up to 500, and `expand[]` only on properties that hold a related object.
    - Errors use Stripe's envelope (`resource_missing`, `charge_already_refunded`, `charge_disputed`, `amount_too_small`, `api_key_invalid`).
    - A session past its `expires_at` expires on its own when next read, and emits `checkout.session.expired`.
- **Hosted page.** `GET /c/pay/<session>` on the same TLS port, with three forms:
    - **Pay** creates the PaymentIntent, charge and balance transaction (fee 2.9% + 30), completes the session, and redirects to `success_url`. It runs under the lock, so a double click charges once;
    - **Decline** leaves the session open with `card_declined`;
    - **Back** redirects to `cancel_url`.
- **Webhooks.** Every change is an event (`checkout.session.completed` and `expired`, `payment_intent.succeeded` and `payment_failed`, `refund.created`, `charge.refunded`, `charge.dispute.created`). Each is queued for the URL set with the `webhook_url` action and signed `t=…,v1=…` with `WEBHOOK_SECRET` at delivery. The outbox gives replay, out of order, stale and wrong-secret variants.
- **Control actions.** `webhook_url` and `dispute`.
- **Using it from the app.** Set the printed `INTEGRATION_ENDPOINTS` (`stripe.api` and `stripe.checkout`), `INTEGRATION_CA_BUNDLE` and `OUTBOUND_ALLOW_LOOPBACK=true`. Turn on the Stripe gateway's test mode with the simulator's secret key and webhook secret.
- **Redaction.** It is a `RedactsPayments` simulator.
- **Drift.** `StripeRecording` is one scripted sequence: account; create, retrieve and expire a session; a missing session; an amount too small; a PaymentIntent paid with the test card (on the simulator, a session paid on its hosted page); retrieve it; a partial refund, a retrieve, the rest, and a refund too many.
    - Steps refer to earlier answers (`{create_session.id}`) and use relative times (`+2100`).
    - What is not drift is normalised: ids (prefix kept), times, secrets, account and customer values, and key order.
    - A recording **stores** only the paths the driver reads (`DRIVER_PATHS`, applied by `project()`), with their values normalised, so it holds no account or customer data to begin with. The secret scan stays as a backstop.
    - Drift is checked on those paths, and an extra live field is not drift. On error steps, a different `error.type` or `error.code` is drift.
    - `simulate:record stripe` runs it against Stripe's test mode with `STRIPE_RECORD_KEY` (a restricted test key; live keys are refused).
    - `tests/Stripe/StripeDriftTest.php` replays the sequence against the simulator for every live recording. A file that does not parse fails it.
    - Recording is deferred (CTO decision, #113): it needs a long-lived restricted test key as a GitHub or Codespaces secret from the owner, so the live check is skipped until then. Its script must rename `metadata` keys as well as values: the merchant chooses those keys, and they can name a customer.

## The end-to-end scenario

`php artisan simulate:e2e` runs `tests/Simulators` (ADR 0016 §4) against both simulators:

- it creates a throwaway database `billing_e2e_<random>` on PostgreSQL (`--connection=pgsql`, the default) or as a temporary SQLite file (`--connection=sqlite`);
- it runs the scenario within a time budget (`--budget`, 360 s by default);
- it drops the database afterwards, unless `--keep`.

The harness (`tests/Simulators/SimulatorHarness.php`) refuses any other database. It drains Stripe's outbox as Stripe would, retrying any non-2xx answer (at once, in tests) until nothing retryable is left. The app runs in process, the simulators run as real TLS processes, the customer's browser is an HTTP client that trusts the scenario CA, and webhooks are posted to the app with the bytes and signature the simulator made.

The main CI job never runs `tests/Simulators`. The `E2E simulators` job does, on its own PostgreSQL service, with a 10-minute limit.

## Recordings and the secret scan

`simulate:scan` checks every `*/recordings/*.json` file in this package, keys as well as values, for:

- credentials: WHM, Basic or Bearer authorization values, cPanel tokens and `cpsess` session paths, Stripe keys, private keys, JWTs, AWS keys, and unmasked values under sensitive keys;
- personal data: emails and host names outside the example and test domains, and public IPv4 or IPv6 addresses.

`simulate:record` deletes a recording that fails the scan. The framework tests run the same scan in CI. To run it before each commit, add it to `.git/hooks/pre-commit`:

```sh
cd billing && php artisan simulate:scan
```

## Adding a provider

1. Implement `Contracts\Simulator` in `src/Providers/<Name>/`:
    - `routes()` lists one `Http\Route` per operation, named after the operation in the provider's reference;
    - `seed()` writes the state a fresh account holds;
    - `appConfiguration()` returns the env lines and notes the app needs.
2. Add the optional contracts it needs:
    - `SignsCallbacks` for webhooks;
    - `HasControlActions` for actions such as raising a dispute;
    - `RecordsLive` for `simulate:record`.
3. List the class in `SimulatorsServiceProvider::simulators()`.
4. Write the responses from the public reference, in our own words and shapes. Never copy SDK or documentation text.
5. Add conformance tests in `tests/` that replay the driver's fixtures as the expected shapes.
6. Keep recordings in `<key>/recordings/`, tagged `source: docs` or `source: live`; `Recorder::differences()` compares
   them with what the simulator serves.

The fixture in `tests/Fixtures/EchoSimulator.php` is the smallest working example.

## Callbacks and redirects

- **Server-to-server callbacks** (webhooks, inbound mail) go through the outbox: `$scenario->outbox()->enqueue(url, body, headers)`, then `deliver()` with an optional `stale`, `wrong_secret` or `unsigned` variant.
    - Only a 2xx answer completes a delivery. Any other answer is retried, as Stripe retries for up to three days: on a compressed backoff (`CallbackDispatcher::BACKOFF_SECONDS`, 0, 1, 2, 4 and 8 s), up to `MAX_ATTEMPTS`. A scenario can set its own schedule with `retryPolicy()`.
    - `drain()` delivers until nothing retryable is left, and `exhausted()` lists the deliveries that used every attempt.
- **Browser redirects** carry the provider's signed answer in the return URL's query (Khalti and eSewa returns, S5). They are not outbox deliveries. A `HasControlActions` action (for example `pay`) changes the state and returns `['redirect' => <return_url with its signed query>]`. The test or the E2E runner then follows that URL into the app as the browser would, and tampered or unsigned variants are separate actions. S5 adds these actions; the framework needs nothing more.

## Limits

- The TLS front frames requests by `Content-Length` only: no `Transfer-Encoding: chunked` and no `Expect: 100-continue`. Laravel's HTTP client (Guzzle with string bodies) never sends those.
- The request log applies the kernel `Redactor` default rule, then its payment rule (card numbers, CVCs, expiry, and Luhn-valid digits inside text). It also masks `cpsess` paths and `session=` values.
- Recordings apply the payment rule only for a simulator that implements `RedactsPayments` (Stripe, Khalti, eSewa), so keys such as `expires_at` keep their values elsewhere.
- `StateStore::transaction()` nests: inner calls run in savepoints.
- In a recording's shape check, a masked value matches any type.
