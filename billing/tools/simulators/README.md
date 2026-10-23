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
- **Webhooks.** Every change is an event (`checkout.session.completed` and `expired`, `payment_intent.succeeded` and `payment_failed`, `refund.created`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `charge.dispute.closed`). Each is queued for the URL set with the `webhook_url` action (only the types in its optional `enabled_events`, as a Stripe event destination subscribes) and signed `t=…,v1=…` with `WEBHOOK_SECRET` at delivery. The outbox gives replay, out of order, stale and wrong-secret variants.
- **Control actions.** `webhook_url`, `dispute`, `escalate_dispute` and `close_dispute`.
    - `dispute` raises a chargeback by default: status `needs_response`, one withdrawal balance transaction (the amount plus the 15.00 dispute fee), then `charge.dispute.created` and `charge.dispute.funds_withdrawn`. With `inquiry: true` it raises an inquiry (`warning_needs_response`, no balance transaction, only `charge.dispute.created`, the charge still refundable).
    - `escalate_dispute` turns an open inquiry into a chargeback on the same dispute: status `needs_response`, a withdrawal balance transaction, then `charge.dispute.funds_withdrawn`.
    - `close_dispute` closes an inquiry as `warning_closed`, or a chargeback as `lost` or `won` (a reinstating balance transaction and `charge.dispute.funds_reinstated` first), then emits `charge.dispute.closed`.
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

### `khalti`: Khalti KPG-2 web checkout, for the PaymentsNepal driver

- **API.** `POST /api/v2/epayment/initiate/` and `POST /api/v2/epayment/lookup/` with `Authorization: Key KhaltiSimulator::SECRET_KEY` (anything else is a 401), and `POST /api/merchant-transaction/{transaction_id}/refund/` (no body refunds the rest; `amount` refunds part, in paisa).
    - Amounts are paisa integers. Initiate needs `return_url`, `website_url`, `amount` (at least 1,000), `purchase_order_id` and `purchase_order_name`; a refusal is a 400 keyed by field with `error_key: validation_error`.
    - Initiate answers `pidx`, `payment_url` (on this TLS port), `expires_at` in Nepal time and `expires_in` (1,800 s). A session left open past `expires_at` turns `Expired` on its own.
    - Lookup answers `pidx`, `total_amount`, `status`, `transaction_id`, `fee` (3% here) and `refunded`. `Expired` and `User canceled` come back as HTTP 400 with the same body, and an unknown pidx as 404.
- **Hosted page.** `GET /?pidx=` with Pay, "Pay (bank confirms later)" (`Pending`) and Cancel (`User canceled`). Each redirects to `return_url` with Khalti's unsigned query (`pidx`, `transaction_id`, `amount`, `total_amount`, `mobile`, `status`, `purchase_order_id`, ...). A second Pay charges nothing and gives the same redirect.
- **Control actions.** `pay` (optional `total_amount` for an amount mismatch), `pending` and `cancel` return the `redirect` the browser follows; `complete` and `expire` are what Khalti later does to an open or Pending session; `refund` is a refund made in the merchant portal (all, or `amount` paisa).
- **Using it from the app.** Set the printed `INTEGRATION_ENDPOINTS` (`khalti.api`, `khalti.refund`, `khalti.pay`), `INTEGRATION_CA_BUNDLE` and `OUTBOUND_ALLOW_LOOPBACK=true`. Use the Khalti gateway in sandbox mode with the simulator's key.
- **Shapes.** Written from docs.khalti.com only; there is no Khalti sandbox capture yet (#208). The driver's own fixtures (`tests/Feature/PaymentsNepal/KhaltiDriverTest.php`) use the same key paths.

### `esewa`: eSewa ePay v2, for the PaymentsNepal driver

- **Form.** `POST /api/epay/main/v2/form` (form fields, as the customer's browser posts them).
    - The signature is base64 HMAC-SHA256 over `name=value` pairs of `signed_field_names`, which must cover `total_amount,transaction_uuid,product_code`. A wrong one, or an unknown product code, is a 400 with `code: ES104`.
    - `total_amount` must be the sum of `amount`, `tax_amount` and both charges, in rupees.
    - A `transaction_uuid` already booked for the product code is a 400 with `code: 0`.
    - A booking redirects (302) to the payment page and stays payable for 50 minutes, as in the UAT captures.
- **Payment page.** `GET /epay/booking/{id}` with Pay, "Go back without paying" (the booking stays `PENDING`) and Cancel. Pay redirects to `success_url?data=` with base64 JSON signed over `transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names`, `total_amount` written as eSewa writes it (`"1,130.5"`). The other two go to `failure_url`, unchanged.
- **Status check.** `GET /api/epay/transaction/status/?product_code&total_amount&transaction_uuid`, matched on all three. It answers `PENDING` for a booked session, `COMPLETE` with a `ref_id` once paid, and `NOT_FOUND` for a cancelled or expired booking, an unknown uuid or another amount. `total_amount` is a JSON number (`100.0`).
- **Control actions.** `pay` (optional `settled_amount` in rupees: the payment settles for another amount, so the status check finds it only under that amount and answers `NOT_FOUND` for ours, and `variant` `wrong_secret`, `tampered` or `unsigned` for a forged return), `leave` and `cancel` return the `redirect`. `settle` sets what eSewa later reports: `COMPLETE`, `EXPIRED`, `AMBIGUOUS`, `NOT_FOUND` (the status check stops finding a pending, halted or paid booking), `FULL_REFUND` or `PARTIAL_REFUND`. eSewa has no refund API, so refunds are only an action.
- **Using it from the app.** Set the printed `INTEGRATION_ENDPOINTS` (`esewa.form`, `esewa.status`), `INTEGRATION_CA_BUNDLE` and `OUTBOUND_ALLOW_LOOPBACK=true`. Use the eSewa gateway in sandbox mode with product code `EPAYTEST` and `EsewaSimulator::SECRET_KEY`.
- **Shapes.** The UAT captures of 2026-10-21 (`tests/Fixtures/Esewa`) are the expected shapes; `tests/Esewa/EsewaDriverAgainstSimulatorTest.php` checks the status answers against them.

### `resellerclub`: ResellerClub (LogicBoxes) HTTP API, for the registrar driver

- **API (`/api/…json`).** Every call carries `auth-userid` (`ResellerClubSimulator::RESELLER_ID`) and `api-key` (`API_KEY`); anything else, or any call while `ip_allowed` is off, is refused "Access Denied. You are not allowed to perform this action." Reads must be GET and changes POST.
    - Refusals are HTTP 500 with `{"status": "ERROR", "message": …}` and change nothing. There are no error codes, so the driver classifies by text; the wording is ours. Field checks answer one message as a map, `{telnocc=…, country=…}`.
    - Lists are repeated names without brackets (`ns=a&ns=b`, `tlds=com&tlds=net`, `options=OrderDetails&options=NsDetails`), read with `Request::values()` (from the form body when it carries the name, otherwise the query string; multipart is refused). Extra order details are numbered pairs (`attr-name1`, `attr-value1`).
    - Values come back as strings, times as epoch seconds. Calls that return one value (signup, contact add, order id, validate-transfer, submit-auth-code) answer a bare JSON value.
- **Account.** `customers/v2/signup` (username is an email, unique), `customers/details?username=`, `contacts/add` (a contact belongs to one customer and is never edited), `contacts/details`.
- **Orders.** `domains/register`, `transfer`, `transfer/submit-auth-code`, `validate-transfer`, `renew` (`exp-date` must equal the current expiry, so a second renewal with the old date is refused), `restore` (only from Pending Delete Restorable), `details-by-name` (option groups `All`, `OrderDetails` with `domsecret`, `ContactIds`, `NsDetails`, `DomainStatus`), `orderid`, `search` (`no-of-records` 10–500, `page-no`; rows `"1"`, `"2"`, … with dotted columns), `modify-ns`, `enable-`/`disable-theft-protection`, `locks`, `modify-privacy-protection` (refused for extensions without privacy, such as `.in`), `modify-contact` (a gTLD registrant change adds `sixtydaylock`).
    - Mutating calls answer `entityid`, `eaqid`, `actionstatus` (`Success`, or `PendingExecution` while the registry has not answered) and `actionstatusdesc`. With `invoice-option=NoInvoice` the price comes off the reseller balance (US cents in state) and no invoice fields are returned.
    - Orders are InActive, Active, Suspended, Pending Delete Restorable, Deleted or Archived.
    - Product keys (`classkey` in availability, `productkey`, `entitytype.entitytypekey`) are the LogicBoxes keys from its public Product Keys list (`domcno` for .com, `domorg` for .org, `thirdleveldotin` for .co.in, …); `.dev` is not on that list, so `dotdev` is assumed. A client should take the extension from the domain name, never from these keys.
- **Scenarios.**
    - Availability: `available`, `regthroughus` (an order in this account), `regthroughothers` (held at another registrar: `FOREIGN_DOMAIN` and `example.com` are seeded), `unknown` (an extension the registry does not sell, or one set `registry_down`). An SLD starting `premium-` is a premium name with a `costHash`; registering it needs the attribute `premium=true`.
    - An SLD starting `slow-` registers asynchronously (`PendingExecution`, InActive) until `settle_order`.
    - A transfer without its auth code waits (`"status": "NoError"`, `PendingExecution`) for `submit-auth-code`, then for the registry (`settle_order`). `.ca` and `.com.au` need the code with the order.
    - `.com.au` needs `id-type`, `id`, `policyReason` and `isAUWarranty=true`; `.dev` needs `tnc=y`. `.co.uk` has no admin, tech or billing contact and `.ca` no billing contact: those take `-1`.
    - Insufficient funds: `set_balance` below the price. A timeout after commit and a rate limit are the framework faults `commit_then_hang` and `rate_limit` on any operation.
- **Control actions.** `settle_order` {domain, outcome `active`|`failed`; a failure refunds the order's charge and frees the name}, `set_status` {domain, status}, `add_foreign_domain` {domain, auth_code, expires_in_days}, `set_balance` {cents}, `registry_down` {tld, down}, `ip_allowed` {allowed}, `next_actionstatus` {value} (the next change answers that actionstatus, an unknown one say, and still runs), and `reset`, which wipes the account, its id counters and the fault plan and reseeds it, as the demo account is wiped daily. An action that refuses its input answers HTTP 400.
- **Using it from the app.** Set the printed `INTEGRATION_ENDPOINTS` (`resellerclub.api` and `resellerclub.check`, both this port), `INTEGRATION_CA_BUNDLE` and `OUTBOUND_ALLOW_LOOPBACK=true`. Use the ResellerClub registrar in test mode with the demo reseller id and API key. The ResellerClub driver reads these keys (`tests/ResellerClub/ResellerClubDriverAgainstSimulatorTest.php` runs it against the simulator through the Domains host).
- **Shapes.** Written from the public HTTP API reference only (no demo-account capture); `tests/ResellerClub/ResellerClubSimulatorTest.php` covers each scenario over the TLS port.

### Wallet scenarios and where each is proved

`tests/Khalti/KhaltiDriverAgainstSimulatorTest.php` and `tests/Esewa/EsewaDriverAgainstSimulatorTest.php` run the unchanged drivers through the app's routes:

| Scenario                | Khalti                                                 | eSewa                                                                                                                               |
| ----------------------- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| Success, in paisa       | Pay on the page, 1,130.55 booked with the fee          | Form post, Pay, 1,130.55 booked with the `ref_id`                                                                                   |
| User cancel             | `User canceled`: the attempt fails                     | `NOT_FOUND` after the cancel return: the attempt ends                                                                               |
| Pending, then completed | `pending`, then `complete`, booked by the poller       | `leave`, then `settle COMPLETE`, booked by the poller                                                                               |
| Pending, then expired   | `pending`, then `expire`: the attempt expires          | `leave`, then `settle EXPIRED`: the attempt expires                                                                                 |
| Amount mismatch         | `pay` with another `total_amount`: staff review        | `pay` with another `settled_amount`: `NOT_FOUND` for our amount, so the attempt is abandoned after 5 minutes, nothing booked (#323) |
| Bad signature or forged | A forged return query pays nothing                     | Forged, wrong-secret, tampered and unsigned returns; ES104 form                                                                     |
| Duplicate callback      | Return twice plus a second Pay: one payment, no credit | The same                                                                                                                            |
| Lookup timeout          | `commit_then_hang` on `lookup`: left to the poller     | `commit_then_hang` on `transaction_status`: left to the poller                                                                      |
| Refunded or partial     | A full refund through the API, a partial in the portal | `FULL_REFUND` and `PARTIAL_REFUND` look up as such                                                                                  |

## The end-to-end scenario

`php artisan simulate:e2e` runs `tests/Simulators` (ADR 0016 §4) against the `whm`, `stripe`, `khalti`, `esewa` and `resellerclub` simulators: the Stripe lifecycle, the NPR lifecycle through eSewa and through Khalti, and a domain ordered, paid with Khalti, registered and renewed at ResellerClub (`tests/Simulators/DomainLifecycleTest.php`):

- it creates a throwaway database `billing_e2e_<random>` on PostgreSQL (`--connection=pgsql`, the default) or as a temporary SQLite file (`--connection=sqlite`);
- it runs the scenario within a time budget (`--budget`, 360 s by default, at least 60);
- it drops the database afterwards, unless `--keep`, which keeps the last test's state (choose it with `--filter`);
- if the run is interrupted (Ctrl-C, SIGTERM), a detached guard stops the scenario and drops the database. `scripts/pg.sh --prune` also drops idle `billing_e2e_*` databases.

The harness (`tests/Simulators/SimulatorHarness.php`) refuses any database but `billing_e2e_*` or SQLite in memory. It judges the connection Laravel really opens, so a `DB_URL` cannot point it elsewhere.

The app runs in process, the simulators run as real TLS processes, and the customer's browser is an HTTP client that trusts the scenario CA. Webhooks are posted to the app with the bytes and signature the simulator made, draining Stripe's outbox as Stripe would. The server, product and gateway rows are fixtures; the rest goes through the app's routes.

The main CI job never runs `tests/Simulators`. The opt-in `E2E simulators` job does: on manual dispatch, on pushes to `main` and on PRs labelled `e2e`, with its own PostgreSQL. It is not a required check.

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
- **Browser redirects** carry the provider's answer in the return URL's query (Khalti and eSewa returns). They are not outbox deliveries. A `HasControlActions` action (for example `pay`) changes the state and returns `['redirect' => <return_url with its query>]`. The test or the E2E runner then follows that URL into the app as the browser would. Forged eSewa returns are a `variant` of `pay`.

## Limits

- The TLS front frames requests by `Content-Length` only: no `Transfer-Encoding: chunked` and no `Expect: 100-continue`. Laravel's HTTP client (Guzzle with string bodies) never sends those.
- The request log applies the kernel `Redactor` default rule, then its payment rule (card numbers, CVCs, expiry, and Luhn-valid digits inside text). It also masks `cpsess` paths and `session=` values.
- Recordings apply the payment rule only for a simulator that implements `RedactsPayments` (Stripe, Khalti, eSewa), so keys such as `expires_at` keep their values elsewhere.
- `StateStore::transaction()` nests: inner calls run in savepoints.
- In a recording's shape check, a masked value matches any type.
