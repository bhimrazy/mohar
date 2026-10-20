# Integration test matrix

Status: payment gateways done (2026-10-20). Control panels and registrars use the same layout later (see
"Other integration families"). Owner: the CTO session; every integration PR updates it.

This page answers three questions for every third-party integration:

1. Which behaviours must every integration of this kind get right (the **standard cases**)?
2. For each gateway, which automated test proves each case, and what did the last **live sandbox** run show?
3. Where are the gaps (cases with no test) and where is test effort wasted (duplicate, overstated or low-value
   tests)?

It is the checklist a reviewer uses on an integration PR, and the place where live sandbox results are recorded.

## Words used on this page

| Term             | Meaning                                                                                                                                                                                                                       |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hosted gateway   | The customer pays on the provider's own page (Stripe Checkout, eSewa, Khalti). The driver implements `HostedPaymentDriver`: `initiate()` sends the customer there, `lookup()` asks the provider what happened.                |
| Attempt          | A `payment_attempts` row Billing creates before sending the customer away: our reference, the exact amount and currency, the provider's session id and its expiry. Settlement always checks the provider's answer against it. |
| Lookup           | A server-side status request to the provider. It is the only thing that can mark a hosted payment paid (ADR 0016 §3, `payments-nepal.md` §4).                                                                                 |
| Return           | The customer's browser coming back from the provider. It is a hint only and always ends in a lookup.                                                                                                                          |
| Webhook          | A signed server-to-server message from the provider (Stripe; the sandbox card driver). eSewa and Khalti have none.                                                                                                            |
| Poller           | `billing:reconcile-payments` (also the `billing.payment_reconciliation` automation task): looks up open attempts whose customer never came back.                                                                              |
| Staff review     | The attempt status `ambiguous`: nothing is booked, the reason is stored, and the admin dashboard shows a "payment review" to-do.                                                                                              |
| Simulator        | A local, stateful fake of the provider's API that the unchanged driver talks to over a real TLS socket (ADR 0016). Not the same as `Http::fake` fixtures.                                                                     |
| Live sandbox run | A manual run against the provider's own test environment (Stripe test mode, eSewa UAT, Khalti `dev.khalti.com`). Evidence is the gateway log in the preview app.                                                              |

## How to read the matrix

- Each case has an ID (`PG-01` …). Gateway cases start with `PG`; control panels will use `CP` and registrars `RG`.
- Each automated test has a short code (`SC-02`, `ES-03` …) listed in the [test index](#test-index), with its
  file and exact test name. A cell lists the codes of the tests that prove the case for that gateway.
- **Shared** holds tests of Billing's own hosted-payment code (attempts, settlement, the poller, the locks). They use
  the sandbox card driver or a test-double wallet, and they prove the behaviour for every hosted gateway, because the
  drivers only answer `initiate()` and `lookup()`.
- Cell markers:
    - `n/a`: the case does not apply to this gateway (the reason is in the case catalogue or a footnote);
    - `GAP`: the case applies but no automated test proves it (see [missing tests](#3-missing-tests));
    - `—` in the Shared column: no Billing-level test; the case is proven per gateway;
    - `(shared)` in a gateway column: proven by the Shared column's tests, which run the same Billing code;
    - `sim #113`: covered by a simulator scenario in an open PR that is not merged yet;
    - `Live PASS` / `FAIL` / `PENDING` / `NOT RUN`: the latest live sandbox result, detailed with date and evidence in
      the [live results log](#live-sandbox-results-log).
- Bank transfer is an offline gateway: almost every hosted case is `n/a`, and its own cases are PG-41. The
  `manual` driver is the same kind of offline driver and would show the same column.
- The sandbox card driver can run in two modes: an inline tokenised card form (`capture`) and a hosted page
  (`checkout_mode = hosted`). Its column covers both.

## 1. Standard case catalogue (payment gateways)

Applies to:

- **H**: hosted gateways (`HostedPaymentDriver`, which replaced the `REDIRECT` and `LOOKUP` flags under ADR 0012);
- **W**: gateways that receive webhooks (`WEBHOOK` flag);
- **R**: gateways with a refund API (`REFUND`, and `PARTIAL_REFUND` when enabled);
- **C**: inline card capture and saved cards (`CAPTURE`, `TOKENIZE`);
- **O**: offline gateways (bank transfer, manual);
- **All**: every gateway.

"Source" names the spec that requires the case: `stripe` = `docs/product/payments-stripe.md`, `nepal` =
`docs/product/payments-nepal.md`, `billing` = `docs/product/billing.md`, `std` = `docs/handoff/product-standards.md`,
`sec` = `docs/security/standards.md`, `owner` = the owner's list of 2026-10-20.

### A. Checkout outcomes

| ID    | Case                              | Expected behaviour                                                                                                                                             | Applies to | Source                     |
| ----- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | -------------------------- |
| PG-01 | Happy path                        | Paying on the provider page marks the invoice paid once, only after a lookup confirms it; the transaction id is the provider's id and its fee is recorded.     | H, C       | owner; stripe AC1-2        |
| PG-02 | Start request is exact            | The provider gets the exact amount in its own units, our reference and its signature or idempotency key; an amount it can't express exactly is refused.        | H          | stripe AC1, AC10; nepal §2 |
| PG-03 | Redirect target is pinned         | The customer is only ever sent to the provider's own host (or a form posting to its origin); anything else closes the attempt.                                 | H          | stripe §3.1; nepal §4      |
| PG-04 | Decline                           | A declined card or wallet leaves the invoice unpaid, the attempt failed with the reason (or open for a retry on the provider page), and payable again.         | H, C       | owner; stripe §3.2         |
| PG-05 | Customer cancels on provider page | The attempt closes at once (Stripe also expires the session at Stripe), so the invoice can be paid another way without waiting.                                | H          | owner; stripe AC14         |
| PG-06 | Abandoned session or expiry       | The invoice unlocks at the provider's own session expiry; an attempt the provider never saw expires after TTL plus grace; a provider "pending" goes to review. | H          | owner; nepal §3.2          |
| PG-07 | Customer never returns            | The poller (or a webhook) settles the payment without the customer's browser.                                                                                  | H          | nepal §0, §5               |
| PG-08 | Payment still processing          | A paid-but-settling payment stays pending with no expiry and keeps the invoice locked until it resolves or staff resolve it.                                   | H, C       | stripe §3.2                |
| PG-09 | Part-paid invoice                 | A hosted payment on a part-paid invoice charges only the remaining balance.                                                                                    | H, C       | owner; nepal §3.2          |
| PG-10 | 3-D Secure / SCA challenge        | A card that needs a bank challenge completes it on the provider page and then settles like PG-01.                                                              | H (cards)  | stripe §1, §7              |

### B. Integrity and forgery

| ID    | Case                                  | Expected behaviour                                                                                                                                  | Applies to         | Source                         |
| ----- | ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ------------------------------ |
| PG-11 | Amount, currency or merchant mismatch | A provider answer whose amount, currency, reference, merchant or mode differs from the stored attempt books nothing and goes to staff review.       | H, W               | owner; stripe AC3; nepal §4    |
| PG-12 | Forged return                         | An unsigned, badly signed or tampered return never marks paid; the lookup uses the provider id and amount stored on the attempt, not the browser's. | H                  | owner; nepal §4                |
| PG-13 | Signed fake with a known test key     | A return correctly signed with a key an attacker can know (the public UAT key) still waits for the status check and never marks paid on its own.    | H (signed returns) | owner                          |
| PG-14 | Forged webhook                        | A bad, missing, test-scheme-only or stale signature, or an event from the other mode, is refused (403/422) before anything is retrieved or stored.  | W                  | owner; stripe AC4; billing AC9 |
| PG-15 | Webhook body never trusted            | The driver retrieves the object from the provider and settles at the attempt's stored amount and exchange rate, never from the posted body.         | W                  | stripe §4                      |
| PG-16 | Bound to client and gateway           | A reference settles only attempts of its own gateway; a client can't start, view or settle another client's payment.                                | H, W               | nepal §4, §5                   |

### C. Idempotency and concurrency

| ID    | Case                           | Expected behaviour                                                                                                                                                                                  | Applies to | Source                           |
| ----- | ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | -------------------------------- |
| PG-17 | Replays and duplicates         | Repeated returns, duplicate or replayed webhooks, and a return racing the poller and webhooks give one transaction and one `InvoicePaid`.                                                           | H, W       | owner; stripe AC4-5; billing AC8 |
| PG-18 | Second checkout blocked        | While a started payment can still complete at the provider, a second start (same or other method, other tab) is refused; it is allowed once the first one ends.                                     | H          | owner; std Payments              |
| PG-19 | Late payment on an old session | A success the provider reports for an attempt we already closed (expired, failed, replaced) is booked once (as credit if the invoice was paid meanwhile), never lost.                               | H          | owner; billing §4                |
| PG-20 | Continue or switch             | With a session still open, the customer can continue it or choose another method (which first closes the old session); after expiry "Pay a different way" appears with a "don't pay again" warning. | H          | owner (#123); std Payments       |

### D. After payment: refunds and disputes

| ID    | Case                                           | Expected behaviour                                                                                                                                                                                         | Applies to | Source                          |
| ----- | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------------------------------- |
| PG-21 | Refund from admin                              | Full and partial refunds go through the API, keyed by our refund row; a refused or unanswered refund leaves the payment refundable. Without an API: refund in the portal, then record it (the UI says so). | R, H, O, C | owner; stripe §6; nepal §3.4    |
| PG-22 | Refund made at the provider                    | A refund made in the provider's dashboard or portal is booked once (webhook) or raises a staff to-do (lookup-only gateways).                                                                               | W, H       | owner; stripe AC7; nepal §3.5   |
| PG-23 | Refunded before we saw the payment             | A lookup that finds the payment already refunded books nothing and goes to staff review.                                                                                                                   | H          | nepal §3.5                      |
| PG-24 | Dispute or chargeback                          | A dispute is booked as a chargeback once, the invoice moves to Collections and `PaymentReversed` fires; disputes of payments made by other integrations are acknowledged without booking.                  | W          | owner; stripe AC8; billing AC30 |
| PG-25 | Dispute or refund before the payment is booked | The event is refused with 409 so the provider sends it again, then booked once after the payment exists.                                                                                                   | W          | owner (#124); billing §6        |

### E. Resilience

| ID    | Case                               | Expected behaviour                                                                                                                                                  | Applies to | Source              |
| ----- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------------------- |
| PG-26 | Provider unreachable or timing out | The attempt stays pending; the waiting customer costs one quick try; the poller retries; a webhook answers non-2xx so the provider sends it again.                  | H, W, R    | owner; nepal §4     |
| PG-27 | Provider refuses to start          | The attempt is closed as failed, the customer sees a plain message and can use another method, and the reason is in the gateway log without secrets.                | H          | nepal §8.1          |
| PG-28 | Lookup ambiguous                   | An unknown status, a rejected key or the provider's own "ambiguous" sends the attempt to staff review with the reason and a dashboard to-do; it settles once fixed. | H          | owner; nepal §8.5   |
| PG-29 | Bounded timeouts                   | Calls use short timeouts: about 3 s connect and 6 s total while a customer waits, 5 s and 15 s otherwise.                                                           | H, R       | stripe §8; nepal §4 |

### F. Eligibility

| ID    | Case                        | Expected behaviour                                                                                                                             | Applies to | Source                     |
| ----- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | -------------------------- |
| PG-30 | Currency not supported      | A gateway that can't take the invoice currency is not offered (or shown disabled with the reason), and a start is refused, unless it converts. | H, C       | owner; nepal §0            |
| PG-31 | Currency conversion         | A converting gateway shows the converted amount and rate before the customer leaves, charges exactly that, and settles at the stored rate.     | H, C, W    | std Payments; billing AC31 |
| PG-32 | Minimum and maximum amounts | An amount outside the provider's limits is shown disabled with the reason, never preselected, and a start is refused.                          | H          | owner; std Payments        |

### G. Configuration and operations

| ID    | Case                               | Expected behaviour                                                                                                                                                  | Applies to         | Source                     |
| ----- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | -------------------------- |
| PG-33 | Secrets never exposed              | Keys and signing secrets never appear in pages, Inertia props, flashed input, the gateway log, stored lookup data, the audit log or command output.                 | All with secrets   | owner; sec §6; stripe AC11 |
| PG-34 | Test connection                    | "Test connection" reports accepted, rejected and wrong-mode credentials, without creating anything that can be charged.                                             | ConnectionTester   | owner; stripe AC12         |
| PG-35 | Sandbox mode refused in production | Sandbox or test mode can't be activated, offered or charged in production, and an open sandbox attempt fails closed.                                                | All with a sandbox | owner; stripe AC9          |
| PG-36 | Required settings                  | A gateway can't be activated until its required settings are filled.                                                                                                | All                | nepal §8.1                 |
| PG-37 | Capabilities declared once         | A driver declares `REDIRECT` exactly when it implements `HostedPaymentDriver`, and the admin refund options follow `REFUND`/`PARTIAL_REFUND`.                       | All                | ADR 0012 §4                |
| PG-38 | Abuse limits                       | Pay requests are throttled per user, the anonymous return route looks an attempt up at most once a minute, references can't be guessed, oversized webhooks get 413. | H, W               | nepal §4; stripe §4        |

### H. What customers and staff see

| ID    | Case                        | Expected behaviour                                                                                                                                         | Applies to | Source       |
| ----- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------------ |
| PG-39 | Payment in progress visible | An unconfirmed payment stays on the client and admin invoice with provider, amount, status, review reason and a "check status" action.                     | H          | std Payments |
| PG-40 | Names, wording and logos    | The pay panel uses the staff-chosen name, the hint names where the customer goes (not the method label), and the official logo shows with a text fallback. | All        | std; #121    |

### I. Gateway kinds without a hosted page

| ID    | Case            | Expected behaviour                                                                                                                                                                                 | Applies to | Source           |
| ----- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ---------------- |
| PG-41 | Offline gateway | Instructions name the invoice; the gateway is never charged online or auto-captured; staff record the payment; refunds are record-only; deactivating it can move unpaid invoices to a replacement. | O          | billing §6       |
| PG-42 | Inline card     | Tokens belong to their client and card data is never stored or flashed; a decline shows a plain error; a pending capture waits for its webhook; auto-capture retries and then disables the card.   | C          | billing §4.2, §6 |

## 2. Matrix

Live results here are from 2026-10-20 unless the [log](#live-sandbox-results-log) says otherwise.

| ID    | Case                             | Shared (Billing)                                     | Stripe                                                        | eSewa                                              | Khalti               | Bank transfer                            | Sandbox card                                                         |
| ----- | -------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------- | -------------------------------------------------- | -------------------- | ---------------------------------------- | -------------------------------------------------------------------- |
| PG-01 | Happy path                       | HP-07                                                | SC-02, SC-14, SW-07, SS-01 · sim #113 · **Live PASS**         | ES-03, ES-04 · **Live PENDING**                    | KH-02 · Live NOT RUN | n/a (PG-41)                              | HP-01, CB-02                                                         |
| PG-02 | Start request is exact           | —                                                    | SC-01, SC-06                                                  | ES-01, ES-02, NA-01, NA-02 · **Live PASS**         | KH-01                | n/a                                      | HP-01                                                                |
| PG-03 | Redirect target is pinned        | HP-07 (form-action CSP)                              | SC-07                                                         | ES-01 (form action and CSP)                        | KH-10, KH-11         | n/a                                      | n/a (own route)                                                      |
| PG-04 | Decline                          | —                                                    | SC-13, SW-08 · sim #113 · **Live PASS**                       | ES-09 ¹                                            | KH-06 ¹              | n/a                                      | CB-03, PS-05                                                         |
| PG-05 | Cancel on provider page          | —                                                    | SC-05 · Live NOT RUN                                          | ES-09 · **Live PENDING**                           | KH-06                | n/a                                      | **GAP** (hosted page cancel)                                         |
| PG-06 | Abandoned or expired             | HP-06                                                | SC-04, SW-09 · sim #113 · **Live PASS**                       | ES-10                                              | KH-06, CS-02, CS-03  | n/a                                      | HP-06 (shared)                                                       |
| PG-07 | Customer never returns           | HP-03, HP-04                                         | SC-02, SW-07                                                  | ES-04                                              | KH-02                | n/a                                      | HP-03, HP-04                                                         |
| PG-08 | Still processing                 | HP-06                                                | SC-04, SC-12, SW-09                                           | ES-09                                              | KH-02, KH-22         | n/a                                      | WH-08, WH-10, AU-03                                                  |
| PG-09 | Part-paid invoice                | **GAP**                                              | **GAP** · **Live PASS**                                       | **GAP**                                            | **GAP**              | n/a                                      | **GAP**                                                              |
| PG-10 | 3-D Secure                       | —                                                    | **GAP** ² · Live NOT RUN                                      | n/a                                                | n/a                  | n/a                                      | n/a                                                                  |
| PG-11 | Mismatch                         | HP-05                                                | SC-03, SW-04                                                  | ES-08                                              | KH-05 ³              | n/a                                      | HP-05, WH-03                                                         |
| PG-12 | Forged return                    | HP-02, HP-09                                         | SC-02 (browser session id ignored)                            | ES-06, ES-07 · **Live PASS**                       | KH-02, KH-03         | n/a                                      | HP-02                                                                |
| PG-13 | Signed fake, known key           | —                                                    | n/a ⁴                                                         | ES-03 · **Live PASS**                              | n/a ⁴                | n/a                                      | n/a ⁴                                                                |
| PG-14 | Forged webhook                   | SB-06                                                | SW-01, SW-02 · sim #113 · **Live PASS** (forged and unsigned) | n/a (no webhooks)                                  | n/a (no webhooks)    | n/a                                      | WH-02, WH-11                                                         |
| PG-15 | Webhook body never trusted       | —                                                    | SW-03, SW-05, SW-10                                           | n/a                                                | n/a                  | n/a                                      | n/a ⁵                                                                |
| PG-16 | Bound to client and gateway      | HP-09, HP-10                                         | SW-16                                                         | ES-08 (other transaction)                          | KH-03                | n/a                                      | HP-10, CB-04                                                         |
| PG-17 | Replays and duplicates           | PY-01                                                | SW-06, SR-01 · sim #113 · **Live PASS**                       | ES-03, ES-14                                       | KH-04                | n/a                                      | HP-01, WH-01, WH-05                                                  |
| PG-18 | Second checkout blocked          | HP-08, CS-01 · **GAP** (race)                        | SC-12 · **Live PASS**                                         | CS-01 (as second method)                           | CS-01, CS-02         | n/a                                      | PS-01, PS-02 (combined payments)                                     |
| PG-19 | Late payment, old session        | **GAP** ⁶ (PY-07, PS-03 cover the booking rule only) | **GAP**                                                       | **GAP**                                            | **GAP**              | n/a                                      | **GAP**                                                              |
| PG-20 | Continue or switch               | CS-01 (after expiry)                                 | SC-05, SC-13 · **Live FAIL** (#123)                           | CS-01 · **Live PASS** (after expiry)               | CS-01                | n/a                                      | (shared)                                                             |
| PG-21 | Refund from admin                | PY-03, PY-04, AI-02, MI-01, MI-05, SB-05, CS-08      | SR-01, SR-03, SR-05, SS-01 · sim #113 · Live NOT RUN          | ES-12 (no API: record), CS-08                      | KH-14, KH-15, KH-16  | PY-06, AI-02                             | PY-05                                                                |
| PG-22 | Refund made at the provider      | —                                                    | SW-12, SW-13, SR-04 · **Live PASS**                           | **GAP** (#27)                                      | **GAP** (#27)        | n/a                                      | WH-07, MI-04                                                         |
| PG-23 | Refunded before we saw it        | —                                                    | SC-03                                                         | ES-09                                              | KH-06                | n/a                                      | n/a                                                                  |
| PG-24 | Dispute or chargeback            | MI-02, MI-03                                         | SW-14, SW-15 · sim #113 · **Live PASS**                       | n/a ⁷                                              | n/a ⁷                | n/a                                      | WH-05, WH-06                                                         |
| PG-25 | Dispute or refund before payment | **GAP** (dispute)                                    | SR-02 (refund) · **GAP** (dispute, #124) · **Live PASS**      | n/a                                                | n/a                  | n/a                                      | WH-07 (refund only)                                                  |
| PG-26 | Provider unreachable             | —                                                    | SC-04, SW-11, SR-03, SR-05                                    | ES-11                                              | KH-07                | n/a                                      | MI-05                                                                |
| PG-27 | Provider refuses to start        | —                                                    | SC-07, SC-08 · **GAP** (5xx or timeout on create)             | n/a (no server call on start)                      | KH-09                | n/a                                      | n/a (no provider call)                                               |
| PG-28 | Lookup ambiguous                 | HP-05, HP-06, CS-05                                  | SC-03, SC-04                                                  | ES-09, ES-16                                       | KH-06, KH-08         | n/a                                      | HP-05                                                                |
| PG-29 | Bounded timeouts                 | —                                                    | SC-15                                                         | KH-07 ⁸                                            | KH-07                | n/a                                      | n/a                                                                  |
| PG-30 | Currency not supported           | HP-07                                                | **GAP** (SC-06 covers units only)                             | **GAP** · **Live PASS** (not offered for USD, INR) | KH-12                | n/a                                      | WH-03                                                                |
| PG-31 | Currency conversion              | HP-07, CS-07, WH-09, MI-01, MI-02                    | SW-05                                                         | HP-07 (shared)                                     | CS-07                | n/a                                      | WH-09                                                                |
| PG-32 | Minimum and maximum              | CS-06 · **GAP** (maximum)                            | **GAP** (minimum 50)                                          | **GAP** (minimum)                                  | KH-13, CS-06         | n/a                                      | (shared)                                                             |
| PG-33 | Secrets never exposed            | HP-11, AS-01, AS-02, AS-05, SB-06, SB-07             | SC-09, SS-01, SS-02                                           | ES-01, ES-05, ES-13                                | KH-09, KH-20, KH-23  | n/a (no secrets)                         | AS-01, AS-02, CB-06, CB-07                                           |
| PG-34 | Test connection                  | —                                                    | SC-09, SC-10                                                  | ES-13                                              | KH-20                | n/a                                      | n/a                                                                  |
| PG-35 | Sandbox refused in production    | —                                                    | SC-08, SS-02                                                  | ES-15 ⁹                                            | KH-17, KH-18         | n/a                                      | SB-01, SB-02, SB-03                                                  |
| PG-36 | Required settings                | KH-19 (shared rule)                                  | KH-19 (shared rule)                                           | KH-19 (shared rule)                                | KH-19                | n/a                                      | n/a                                                                  |
| PG-37 | Capabilities declared once       | **GAP**                                              | **GAP**                                                       | CS-08 (refund options only)                        | CS-08, KH-14         | **GAP** (M-10)                           | **GAP** (M-10)                                                       |
| PG-38 | Abuse limits                     | HP-08, SB-04, SB-08                                  | SW-17                                                         | (shared)                                           | (shared)             | n/a                                      | (shared)                                                             |
| PG-39 | Payment in progress visible      | CS-01, CS-04, CS-05                                  | (shared)                                                      | (shared)                                           | CS-01                | n/a                                      | (shared)                                                             |
| PG-40 | Names, wording, logos            | —                                                    | SC-09 (logo), SC-11 (name) · **Live FAIL** (#121)             | KH-21 (logo)                                       | KH-21 (logo)         | KH-21 (text fallback)                    | **GAP** (#121)                                                       |
| PG-41 | Offline gateway                  | —                                                    | n/a                                                           | n/a                                                | n/a                  | CB-01, CB-05, PS-04, AI-01, PY-06, AS-03 | n/a                                                                  |
| PG-42 | Inline card                      | —                                                    | n/a (saved cards: second Stripe PR)                           | n/a                                                | n/a                  | n/a                                      | CB-02, CB-03, CB-04, CB-06, CB-07, WH-10, AU-01, AU-02, AU-03, PS-05 |

Footnotes:

1. eSewa and Khalti handle a declined wallet or bank payment on their own page; we only see the end result, which
   arrives as a cancel (`CANCELED`, `User canceled`), so PG-05's tests also prove the decline outcome.
2. Not automatable here: the challenge runs inside Stripe's page, and ADR 0016 "Limits" says the simulator can't
   prove 3-D Secure. It needs a live run with one of Stripe's authentication test cards.
3. Khalti's lookup carries no currency (Khalti settles only in NPR), and its key binds the merchant, so only the
   amount can mismatch.
4. The return carries no signature to forge (Stripe, Khalti, the sandbox page), so there is no "known key" to sign
   with; PG-12 covers these returns. A Stripe webhook secret is per endpoint and not public.
5. The sandbox card driver uses the push model of `billing.md` §6: its signed webhook body is the source of truth by
   design. It is a test gateway, refused in production (PG-35).
6. `SettlePaymentAttempt::handle()` returns at once for an attempt that is no longer open, and a webhook for one
   answers 200 "attempt expired". So a success the provider reports after we closed the attempt is logged but not
   booked. For Stripe this needs a paid session on an attempt we expired, which Stripe prevents when we expire the
   session first (PG-05); the eSewa and Khalti paths (attempt expired after TTL plus grace) are not proven either
   way. This needs a product decision, then a test (M-01).
7. Disputes on eSewa and Khalti are handled with the provider offline; no dispute signal reaches us.
8. eSewa shares `ProviderHttp` (timeouts and retries) with Khalti, so KH-07 proves eSewa's timeouts too.
9. ES-15 proves the fail-closed attempt. Refusing activation in production is the shared `SandboxPolicy`, proven
   for Stripe and Khalti (SC-08, KH-17); no eSewa-specific test exists.

### Coverage summary

| Status                                                                  | Cases | IDs                                                           |
| ----------------------------------------------------------------------- | ----- | ------------------------------------------------------------- |
| Covered: every gateway it applies to has an automated test              | 29    | all others                                                    |
| Partly covered: at least one applicable gateway or sub-case has no test | 9     | PG-05, PG-18, PG-20, PG-22, PG-25, PG-27, PG-30, PG-32, PG-40 |
| Not covered by any automated test                                       | 4     | PG-09, PG-10, PG-19, PG-37                                    |
| **Total**                                                               | 42    |                                                               |

Two of the uncovered cases passed live (PG-09 on Stripe) or can only be proven live (PG-10). Live sandbox runs
exist for Stripe and eSewa only; Khalti has none.

### Live sandbox results log

Add a row for every live run; never edit an old row (a later run gets its own row). Evidence is where a reviewer
can check the result.

| Date       | Gateway | Case         | Result                                                                                                                                                              | Evidence                     | Run by | Issue                          |
| ---------- | ------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------ | ------------------------------ |
| 2026-10-20 | Stripe  | PG-01        | PASS: paid on Checkout, invoice paid once                                                                                                                           | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-04        | PASS: declined card leaves the invoice unpaid                                                                                                                       | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-06        | PASS: `checkout.session.expired` webhook frees the invoice                                                                                                          | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-09        | PASS: a part-paid invoice charges only the balance                                                                                                                  | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-14        | PASS: forged webhook 403; unsigned webhook 403                                                                                                                      | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-17        | PASS: replayed event de-duplicated                                                                                                                                  | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-18        | PASS: second checkout blocked                                                                                                                                       | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-22        | PASS: refund made in the Stripe Dashboard booked once (`refund.created` plus `refund.updated`)                                                                      | Preview gateway log          | CTO    |                                |
| 2026-10-20 | Stripe  | PG-25, PG-24 | PASS: dispute (card 4000 0000 0000 0259) arrived before the payment was booked: 409, then booked as chargeback on resend, invoice to Collections                    | Preview gateway log          | CTO    | #124 (regression test missing) |
| 2026-10-20 | Stripe  | PG-20        | FAIL (UX gap): declined, then back on the invoice before expiry, the client sees only "Payment in progress… Check status": no way to continue or use another method | Preview, client invoice page | CTO    | #123                           |
| 2026-10-20 | Stripe  | PG-40        | FAIL (wording): the hint names the display label ("You'll be taken to Card")                                                                                        | Preview, client invoice page | CTO    | #121                           |
| 2026-10-20 | Stripe  | PG-10        | NOT RUN: the 3-D Secure challenge runs in an iframe and was not exercised                                                                                           | —                            | CTO    |                                |
| 2026-10-20 | eSewa   | PG-02        | PASS: signed form accepted by UAT                                                                                                                                   | Preview gateway log          | CTO    |                                |
| 2026-10-20 | eSewa   | PG-13        | PASS: a COMPLETE return signed with the public UAT key was not trusted; the status API said PENDING; invoice unpaid                                                 | Preview gateway log          | CTO    |                                |
| 2026-10-20 | eSewa   | PG-12        | PASS: a bad-signature return was ignored                                                                                                                            | Preview gateway log          | CTO    |                                |
| 2026-10-20 | eSewa   | PG-20        | PASS: after expiry the page offers "Pay a different way" with a "don't pay again" warning                                                                           | Preview, client invoice page | CTO    |                                |
| 2026-10-20 | eSewa   | PG-30        | PASS: not offered for USD or INR invoices                                                                                                                           | Preview, client invoice page | CTO    |                                |
| 2026-10-20 | eSewa   | PG-01, PG-05 | PENDING: happy path and cancel need a person to solve eSewa's CAPTCHA; owner run due                                                                                | —                            | Owner  |                                |
| —          | Khalti  | all          | NOT RUN: no live run yet                                                                                                                                            | —                            | —      |                                |

Live runs still owed: eSewa PG-01 and PG-05 (owner); Khalti PG-01, PG-04 to PG-06 and a partial refund that proves
the refund unit (#27); Stripe PG-05 (cancel), PG-10 (3-D Secure) and `payments:stripe-smoke` against the sandbox.

## Test index

Every test in the gateway test files is listed, plus the Billing tests the matrix cites. File paths are relative to
`billing/`. Datasets are named in brackets.

### Stripe

`SC` = `tests/Feature/PaymentsStripe/StripeCheckoutTest.php`

| Code  | Test name                                                                                                                                                                                        | Cases                      |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------- |
| SC-01 | starting a payment creates a Checkout Session with our reference, exact amount, pinned version and idempotency key                                                                               | PG-02                      |
| SC-02 | a return pays nothing until the session retrieve confirms it, then the poller settles it with the fee                                                                                            | PG-01, PG-07, PG-12        |
| SC-03 | a retrieve that disagrees with the attempt records nothing and goes to staff review [amount, currency, reference, metadata reference, live object for a test gateway, refunded before we saw it] | PG-11, PG-23, PG-28        |
| SC-04 | session states map to the attempt [open, expired, complete but unpaid, processing, not found, key rejected, Stripe outage]                                                                       | PG-06, PG-08, PG-26, PG-28 |
| SC-05 | cancelling on Stripe's page expires that session so the invoice can be paid another way at once                                                                                                  | PG-05, PG-20               |
| SC-06 | amounts are sent in Stripe units for two- and zero-decimal currencies and refused when inexact                                                                                                   | PG-02                      |
| SC-07 | a Checkout url that is not on checkout.stripe.com is never handed to the customer                                                                                                                | PG-03, PG-27               |
| SC-08 | test mode is never usable in production and keys must match the mode                                                                                                                             | PG-35, PG-27               |
| SC-09 | staff can test the saved keys without them ever reaching the page [accepted, rejected]                                                                                                           | PG-34, PG-33, PG-40        |
| SC-10 | the connection test catches keys from the other mode without calling Stripe [3 key mixes]                                                                                                        | PG-34                      |
| SC-11 | the pay panel lists the staff-chosen name and names the provider the customer is sent to                                                                                                         | PG-40                      |
| SC-12 | a paid session still processing keeps the invoice locked after the session expired                                                                                                               | PG-08, PG-18               |
| SC-13 | a completed session whose payment failed fails the attempt with the reason, so the customer can pay again                                                                                        | PG-04, PG-20               |
| SC-14 | the Stripe fee is booked only when its balance transaction is in the charge currency                                                                                                             | PG-01 (fee)                |
| SC-15 | calls to Stripe carry short timeouts: quick for the waiting customer, bounded otherwise                                                                                                          | PG-29                      |

`SW` = `tests/Feature/PaymentsStripe/StripeWebhookTest.php`

| Code  | Test name                                                                                                                                                                                                                                 | Cases        |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| SW-01 | a webhook is processed only with a valid, fresh signature, before anything is retrieved or stored [valid, tampered body, wrong secret, test-scheme signature only, missing header, stale, from the future, live event for a test gateway] | PG-14        |
| SW-02 | one of several v1 signatures may match while a secret is being rolled                                                                                                                                                                     | PG-14        |
| SW-03 | the payment is recorded from the retrieved PaymentIntent, never from the posted body                                                                                                                                                      | PG-15        |
| SW-04 | a PaymentIntent whose amount or currency differs from the attempt records nothing and goes to staff review                                                                                                                                | PG-11        |
| SW-05 | a webhook settles a converted payment at the rate stored on the attempt, even after the rate changed                                                                                                                                      | PG-15, PG-31 |
| SW-06 | replays, duplicate events, the return route and the poller record the payment once                                                                                                                                                        | PG-17        |
| SW-07 | the session webhook settles a payment whose customer never came back                                                                                                                                                                      | PG-01, PG-07 |
| SW-08 | events that move no money are acknowledged and logged [payment_intent.payment_failed, charge.refunded]                                                                                                                                    | PG-04        |
| SW-09 | session events settle the attempt by lookup, whatever state it is in [expired, delayed method processing]                                                                                                                                 | PG-06, PG-08 |
| SW-10 | a session event for a reference we never issued is acknowledged                                                                                                                                                                           | PG-15        |
| SW-11 | Stripe being unreachable during the retrieve makes Stripe deliver the event again                                                                                                                                                         | PG-26        |
| SW-12 | a refund made in the Stripe Dashboard is recorded once from refund.created                                                                                                                                                                | PG-22        |
| SW-13 | refund webhooks for refunds made from our admin, or not yet succeeded, book nothing [pending, of a payment not made here]                                                                                                                 | PG-22        |
| SW-14 | a dispute is recorded as a chargeback and flags the invoice for staff                                                                                                                                                                     | PG-24        |
| SW-15 | a dispute of a payment made by another integration on the account is acknowledged, not retried                                                                                                                                            | PG-24        |
| SW-16 | an attempt reference only settles attempts of the gateway the webhook came in on                                                                                                                                                          | PG-16        |
| SW-17 | webhook bodies over 256 KB are refused before any signature work                                                                                                                                                                          | PG-38        |

`SR` = `tests/Feature/PaymentsStripe/StripeRefundTest.php`, `SS` = `tests/Feature/PaymentsStripe/StripeSmokeTest.php`

| Code  | Test name                                                                                                                      | Cases               |
| ----- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------- |
| SR-01 | staff refund part of a Stripe payment and then the rest, keyed and tagged by our refund rows                                   | PG-21, PG-17        |
| SR-02 | a refund webhook arriving while the refund action is still recording it is retried, then deduplicated                          | PG-25               |
| SR-03 | a staff refund that timed out but went through at Stripe is booked from its webhook, once                                      | PG-21, PG-26        |
| SR-04 | a refund from the admin that failed at Stripe books nothing and is logged for staff                                            | PG-22               |
| SR-05 | a refund Stripe refuses or never answers leaves the payment refundable [refused, no answer, failed]                            | PG-21, PG-26        |
| SS-01 | the smoke command pays and refunds in test mode without printing the key                                                       | PG-01, PG-21, PG-33 |
| SS-02 | the smoke command takes the key from a hidden prompt or the environment, never an option, and refuses live keys and production | PG-33, PG-35        |

### eSewa and Khalti

`ES` = `tests/Feature/PaymentsNepal/EsewaDriverTest.php`

| Code  | Test name                                                                                                                                                                | Cases                             |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------- |
| ES-01 | initiate signs total_amount, transaction_uuid and product_code over rupee strings                                                                                        | PG-02, PG-03, PG-33               |
| ES-02 | signatures match our fixed vectors for whole-rupee requests and comma-formatted responses                                                                                | PG-02                             |
| ES-03 | a validly signed COMPLETE return still waits for the status check, then pays exactly once                                                                                | PG-01, PG-13, PG-17               |
| ES-04 | the poller settles a payment whose customer never came back                                                                                                              | PG-01, PG-07                      |
| ES-05 | a genuine success return verifies and names the attempt                                                                                                                  | PG-12 (control), PG-33            |
| ES-06 | tampered or foreign-signed returns are flagged and never settle on their own [amount, status, transaction code changed after signing; another key; unknown signed field] | PG-12                             |
| ES-07 | the status check always carries the stored amount, not the one from the return                                                                                           | PG-12                             |
| ES-08 | a COMPLETE status for a different amount or transaction goes to staff review [amount, transaction, merchant]                                                             | PG-11, PG-16                      |
| ES-09 | eSewa statuses map to attempt outcomes [CANCELED, PENDING, NOT_FOUND, AMBIGUOUS, FULL_REFUND, PARTIAL_REFUND]                                                            | PG-04, PG-05, PG-08, PG-23, PG-28 |
| ES-10 | an attempt eSewa never saw expires after its TTL plus the grace period                                                                                                   | PG-06                             |
| ES-11 | an eSewa outage keeps the attempt open for the next poll                                                                                                                 | PG-26                             |
| ES-12 | eSewa payments are refunded in the portal, not through the API                                                                                                           | PG-21                             |
| ES-13 | the connection test books a signed UAT session to check the code and key [accepted, rejected]                                                                            | PG-34, PG-33                      |
| ES-14 | the provider form is served once, so a refresh never re-posts the signed form                                                                                            | PG-17 (form re-post)              |
| ES-15 | a sandbox attempt opened before switching to production fails closed instead of serving the UAT form                                                                     | PG-35                             |
| ES-16 | a refused status check sends the attempt to staff review                                                                                                                 | PG-28                             |

`KH` = `tests/Feature/PaymentsNepal/KhaltiDriverTest.php`

| Code  | Test name                                                                                                                                              | Cases                             |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------- |
| KH-01 | initiate sends the amount in paisa with the key header and redirects to Khalti                                                                         | PG-02                             |
| KH-02 | a return claiming Completed pays nothing until the lookup confirms it, then the poller settles it                                                      | PG-01, PG-07, PG-08, PG-12        |
| KH-03 | the lookup uses the pidx stored on the attempt, never the one in the return query                                                                      | PG-12, PG-16                      |
| KH-04 | repeated returns and the poller record the payment once                                                                                                | PG-17                             |
| KH-05 | a lookup reporting a different amount records nothing and goes to staff review                                                                         | PG-11                             |
| KH-06 | Khalti end states close the attempt without paying, including the HTTP 400 ones [Expired, User canceled, Refunded, Partially refunded, unknown status] | PG-04, PG-05, PG-06, PG-23, PG-28 |
| KH-07 | an unreachable Khalti costs the waiting customer one quick try and leaves retries to the poller                                                        | PG-26, PG-29                      |
| KH-08 | a rejected key during lookup sends the attempt to staff review, and it settles once the key is fixed                                                   | PG-28                             |
| KH-09 | a refused initiate fails the attempt and shows a safe message                                                                                          | PG-27, PG-33                      |
| KH-10 | a payment_url outside khalti.com is never followed [7 hostile URLs]                                                                                    | PG-03                             |
| KH-11 | a payment_url on khalti.com or a subdomain is followed whatever its case                                                                               | PG-03                             |
| KH-12 | Khalti is offered for NPR invoices only and other currencies are refused                                                                               | PG-30                             |
| KH-13 | Khalti is offered for NPR invoices but refuses amounts below its minimum                                                                               | PG-32                             |
| KH-14 | partial refunds stay off by default while full refunds go through the API                                                                              | PG-21, PG-37                      |
| KH-15 | with partial refunds turned on, the refund amount is sent in paisa                                                                                     | PG-21                             |
| KH-16 | a declined refund leaves the payment refundable                                                                                                        | PG-21                             |
| KH-17 | live mode talks to khalti.com and sandbox mode cannot be activated in production                                                                       | PG-35                             |
| KH-18 | a gateway left in sandbox mode is hidden from clients in production                                                                                    | PG-35                             |
| KH-19 | activation requires the secret key                                                                                                                     | PG-36                             |
| KH-20 | staff can test the saved key without it ever reaching the page [accepted (not found), accepted (validation error), rejected]                           | PG-34, PG-33                      |
| KH-21 | checkout shows the official logos with an accessible name and other gateways fall back to their name                                                   | PG-40                             |
| KH-22 | an Initiated payment stays pending until Khalti reports an outcome                                                                                     | PG-08                             |
| KH-23 | the payer mobile number from the return is masked to its last three digits                                                                             | PG-33                             |

`CS` = `tests/Feature/PaymentsNepal/CheckoutStatesTest.php`, `NA` = `tests/Unit/PaymentsNepal/NprAmountTest.php`

| Code  | Test name                                                                                      | Cases               |
| ----- | ---------------------------------------------------------------------------------------------- | ------------------- |
| CS-01 | a started payment stays on the invoice and blocks a second one until its session ends          | PG-18, PG-20, PG-39 |
| CS-02 | the invoice stays locked until the session expiry Khalti reports, not a fixed hour             | PG-06, PG-18        |
| CS-03 | an implausible session expiry from Khalti falls back to the configured lifetime                | PG-06               |
| CS-04 | a confirmed payment clears the in-progress state                                               | PG-39               |
| CS-05 | staff see the unconfirmed payment on the admin invoice with the review reason                  | PG-28, PG-39        |
| CS-06 | a method that cannot take the amount is shown disabled with the reason and never preselected   | PG-32               |
| CS-07 | a converting gateway shows the amount it will charge, and the payment uses exactly that amount | PG-31               |
| CS-08 | staff see which refunds go through the provider and which gateways are in sandbox mode         | PG-21, PG-37        |
| NA-01 | paisa become the rupee strings eSewa expects                                                   | PG-02               |
| NA-02 | provider rupee amounts parse to exact paisa                                                    | PG-02               |

### Billing (shared, bank transfer, sandbox card)

`HP` = `tests/Feature/Billing/HostedPaymentsTest.php`. HP-01 to HP-05 run the sandbox card driver in hosted mode;
HP-06 to HP-09 use `FakeNprWalletDriver`, a test double defined in the file.

| Code  | Test name                                                                                      | Cases                      |
| ----- | ---------------------------------------------------------------------------------------------- | -------------------------- |
| HP-01 | the sandbox hosted flow pays only after the lookup, and a replayed return is a no-op           | PG-01, PG-02, PG-17        |
| HP-02 | a return claiming success is not trusted when the provider says otherwise                      | PG-12                      |
| HP-03 | the poller settles an attempt whose customer never returned                                    | PG-07                      |
| HP-04 | the reconciliation task runs in the daily pipeline                                             | PG-07                      |
| HP-05 | an amount mismatch records nothing and raises a staff todo                                     | PG-11, PG-28               |
| HP-06 | unseen attempts expire after the grace period while provider-pending ones go to review         | PG-06, PG-08, PG-28        |
| HP-07 | NPR-only gateways require NPR or a configured conversion, and use form posts                   | PG-01, PG-03, PG-30, PG-31 |
| HP-08 | clients get 429 after too many pay requests a minute, on single and batch pay                  | PG-18, PG-38               |
| HP-09 | the provider form page is bound to the client and to an open attempt                           | PG-12, PG-16               |
| HP-10 | clients cannot start payments on other clients invoices and returns are bound to their gateway | PG-16                      |
| HP-11 | every hosted driver honours the contract [sandbox_card, fake_wallet, khalti, esewa, stripe]    | PG-33                      |

`WH` = `tests/Feature/Billing/WebhookTest.php` (sandbox card webhooks), `SB` = `tests/Feature/Billing/SandboxSafetyTest.php`

| Code  | Test name                                                                          | Cases        |
| ----- | ---------------------------------------------------------------------------------- | ------------ |
| WH-01 | a signed payment webhook pays the invoice exactly once                             | PG-17        |
| WH-02 | an invalid signature records nothing and is logged                                 | PG-14        |
| WH-03 | webhooks for inactive gateways, unknown invoices and other currencies are rejected | PG-11, PG-30 |
| WH-04 | an under-payment webhook is a partial payment                                      | (push model) |
| WH-05 | a chargeback moves the invoice to collections and reverses the payment             | PG-24, PG-17 |
| WH-06 | a chargeback on a deposit removes the credit even below zero                       | PG-24        |
| WH-07 | a refund webhook before its payment is rejected for retry, then recorded once      | PG-22, PG-25 |
| WH-08 | a failed settlement webhook returns a pending invoice to unpaid                    | PG-08        |
| WH-09 | a converting gateway charges round(balance × rate) and stores both amounts         | PG-31        |
| WH-10 | a pending capture settles through the webhook                                      | PG-08, PG-42 |
| WH-11 | a replayed callback older than five minutes is rejected                            | PG-14        |
| SB-01 | the sandbox gateway is disabled in production unless explicitly forced             | PG-35        |
| SB-02 | a disabled sandbox is not registered and cannot be activated                       | PG-35        |
| SB-03 | the seeder never creates the sandbox gateway outside local and testing             | PG-35        |
| SB-04 | payment attempt references are random and unguessable                              | PG-38        |
| SB-05 | money-moving and security staff actions require a recent password confirmation     | PG-21        |
| SB-06 | unverified webhook bodies are never stored, only fingerprinted                     | PG-14, PG-33 |
| SB-07 | the gateway log caps payloads and masks card numbers inside free text              | PG-33        |
| SB-08 | the anonymous return route looks an attempt up at most once a minute               | PG-38        |

Other Billing tests cited (one file per prefix):

| Code  | File                                           | Test name                                                                           | Cases        |
| ----- | ---------------------------------------------- | ----------------------------------------------------------------------------------- | ------------ |
| PY-01 | `tests/Feature/Billing/PaymentsTest.php`       | the same gateway transaction is recorded once and InvoicePaid fires once            | PG-17        |
| PY-03 | `tests/Feature/Billing/PaymentsTest.php`       | a record-only partial refund keeps the invoice paid                                 | PG-21        |
| PY-04 | `tests/Feature/Billing/PaymentsTest.php`       | a refund larger than the refundable remainder is rejected                           | PG-21        |
| PY-05 | `tests/Feature/Billing/PaymentsTest.php`       | gateway refunds go through the driver                                               | PG-21        |
| PY-06 | `tests/Feature/Billing/PaymentsTest.php`       | offline gateways cannot refund automatically                                        | PG-21, PG-41 |
| PY-07 | `tests/Feature/Billing/PaymentsTest.php`       | a payment on a cancelled invoice is kept as account credit                          | PG-19 (rule) |
| PS-01 | `tests/Feature/Billing/PaymentSafetyTest.php`  | a combined payment cannot be charged twice                                          | PG-18        |
| PS-02 | `tests/Feature/Billing/PaymentSafetyTest.php`  | a combined payment that is already in progress is refused                           | PG-18        |
| PS-03 | `tests/Feature/Billing/PaymentSafetyTest.php`  | money a gateway already took is recorded but held as credit when payment is refused | PG-19 (rule) |
| PS-04 | `tests/Feature/Billing/PaymentSafetyTest.php`  | auto-capture never charges invoices assigned to an offline gateway                  | PG-41        |
| PS-05 | `tests/Feature/Billing/PaymentSafetyTest.php`  | customer-initiated declines do not consume the auto-capture retry budget            | PG-04, PG-42 |
| CB-01 | `tests/Feature/Billing/ClientBillingTest.php`  | the invoice page offers active gateways and bank instructions                       | PG-41        |
| CB-02 | `tests/Feature/Billing/ClientBillingTest.php`  | clients pay with a new sandbox card and may save it                                 | PG-01, PG-42 |
| CB-03 | `tests/Feature/Billing/ClientBillingTest.php`  | a declined card shows a friendly error and leaves the invoice unpaid                | PG-04, PG-42 |
| CB-04 | `tests/Feature/Billing/ClientBillingTest.php`  | clients cannot pay with another clients saved card or a forged token                | PG-16, PG-42 |
| CB-05 | `tests/Feature/Billing/ClientBillingTest.php`  | offline gateways cannot be charged online                                           | PG-41        |
| CB-06 | `tests/Feature/Billing/ClientBillingTest.php`  | the sandbox vault tokenises valid cards without storing them                        | PG-33, PG-42 |
| CB-07 | `tests/Feature/Billing/ClientBillingTest.php`  | card data is never flashed into the session                                         | PG-33, PG-42 |
| AS-01 | `tests/Feature/Billing/AdminSetupTest.php`     | a failed gateway save never flashes a secret setting into the session               | PG-33        |
| AS-02 | `tests/Feature/Billing/AdminSetupTest.php`     | gateways can be configured without exposing stored secrets                          | PG-33        |
| AS-03 | `tests/Feature/Billing/AdminSetupTest.php`     | deactivating a gateway can move unpaid invoices to a replacement                    | PG-41        |
| AS-05 | `tests/Feature/Billing/AdminSetupTest.php`     | gateway, tax, refund and credit changes are audit-logged without secret values      | PG-33        |
| AI-01 | `tests/Feature/Billing/AdminInvoicesTest.php`  | staff record payments with decimal amounts                                          | PG-41        |
| AI-02 | `tests/Feature/Billing/AdminInvoicesTest.php`  | staff refund a payment through the transactions endpoint                            | PG-21        |
| MI-01 | `tests/Feature/Billing/MoneyIntegrityTest.php` | gateway refunds of converted payments are sent in the charged currency              | PG-21, PG-31 |
| MI-02 | `tests/Feature/Billing/MoneyIntegrityTest.php` | refund and chargeback webhooks are converted back to the invoice currency           | PG-24, PG-31 |
| MI-03 | `tests/Feature/Billing/MoneyIntegrityTest.php` | unknown callback types are ignored, not booked as chargebacks                       | PG-24        |
| MI-04 | `tests/Feature/Billing/MoneyIntegrityTest.php` | a refund of money that went to credit is acknowledged for staff review              | PG-22        |
| MI-05 | `tests/Feature/Billing/MoneyIntegrityTest.php` | a failed gateway refund keeps its log and restores the refundable amount            | PG-21, PG-26 |
| AU-01 | `tests/Feature/Billing/AutomationTest.php`     | auto-capture charges the default card the day before the due date                   | PG-42        |
| AU-02 | `tests/Feature/Billing/AutomationTest.php`     | declined captures retry after 3 and 7 days and disable the card after 3 failures    | PG-42        |
| AU-03 | `tests/Feature/Billing/AutomationTest.php`     | a pending capture moves the invoice to payment pending                              | PG-08, PG-42 |

### Simulator scenarios (ADR 0016)

On `develop` only the WHM simulator exists (S2). The Stripe simulator is in draft PR #113 (S3, not merged); the
eSewa and Khalti simulators are planned for S5. The matrix shows these as `sim #113`.

| Scenario (PR #113, `tools/simulators/tests/Stripe/StripeDriverAgainstSimulatorTest.php`)                     | Cases        |
| ------------------------------------------------------------------------------------------------------------ | ------------ |
| a customer pays on the hosted page; signed webhooks and lookup settle it once, whatever the order or repeats | PG-01, PG-17 |
| a forged or stale webhook is refused before anything is retrieved or booked                                  | PG-14        |
| a staff refund goes to Stripe once, and its webhook books nothing more                                       | PG-21        |
| a dispute raised at Stripe is recorded as a chargeback, once                                                 | PG-24        |
| a declined card and then an abandoned checkout leave the invoice unpaid, with the closed attempt on record   | PG-04, PG-06 |

## 3. Missing tests

Proposed names and files only; none of these is written yet. "Blocked" means the behaviour must be built or decided
first. Ordered by risk: money taken but not booked, or charged wrongly, first.

| #    | Case         | Risk   | Proposed test (file :: name)                                                                                                                                                     | Note                                                              |
| ---- | ------------ | ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| M-01 | PG-19        | High   | `tests/Feature/Billing/HostedPaymentsTest.php` :: a success the provider reports for an attempt we already closed is booked once, as credit when the invoice was paid meanwhile  | Blocked: decide the behaviour first (footnote 6)                  |
| M-02 | PG-09        | High   | `tests/Feature/Billing/HostedPaymentsTest.php` :: a hosted payment on a part-paid invoice asks the provider for the remaining balance only                                       | Live PASS on Stripe; no automated proof for any driver            |
| M-03 | PG-25        | High   | `tests/Feature/PaymentsStripe/StripeWebhookTest.php` :: a dispute that arrives before its payment is booked is refused for retry, then booked as a chargeback once               | #124; also as a simulator scenario in S3                          |
| M-04 | PG-18        | High   | `tests/Feature/Billing/HostedPaymentsTest.php` :: an attempt opened between the in-progress check and the insert still blocks a second start (invoice lock held)                 | Proves the lock, not only the sequential refusal; PostgreSQL gate |
| M-05 | PG-20        | High   | `tests/Feature/PaymentsNepal/CheckoutStatesTest.php` :: while a session is open the customer can continue it, or switch after the old session is closed at the provider          | Blocked: #123 not built                                           |
| M-06 | PG-12, PG-30 | Medium | `tests/Feature/Billing/HostedPaymentsTest.php` :: every hosted driver refuses a currency it does not list, and a tampered return never settles without its lookup (extend HP-11) | The contract items `payments-nepal.md` §5 lists                   |
| M-07 | PG-27        | Medium | `tests/Feature/PaymentsStripe/StripeCheckoutTest.php` :: Stripe failing or timing out on session create closes the attempt and shows a safe message                              |                                                                   |
| M-08 | PG-30        | Medium | `tests/Feature/PaymentsNepal/EsewaDriverTest.php` :: eSewa is offered for NPR invoices only and other currencies are refused                                                     | Live PASS; Khalti has KH-12                                       |
| M-09 | PG-22        | Medium | `tests/Feature/PaymentsNepal/KhaltiDriverTest.php` and `EsewaDriverTest.php` :: a refund made in the portal after payment raises a staff to-do                                   | Blocked: #27 (daily re-lookup not built)                          |
| M-10 | PG-37        | Medium | `tests/Feature/Billing/RegistriesTest.php` :: every registered gateway declares REDIRECT exactly when it is a hosted payment driver                                              | Required by ADR 0012 §4                                           |
| M-11 | PG-30        | Medium | `tests/Feature/PaymentsStripe/StripeCheckoutTest.php` :: an invoice in a currency Stripe can't charge doesn't offer Stripe and a start is refused                                | SC-06 only covers the unit conversion                             |
| M-12 | PG-25        | Low    | `tests/Feature/Billing/WebhookTest.php` :: a chargeback before its payment is rejected for retry, then recorded once                                                             | Shared path; WH-07 covers refunds only                            |
| M-13 | PG-32        | Low    | `tests/Feature/PaymentsStripe/StripeCheckoutTest.php` and `EsewaDriverTest.php` :: an amount below the provider's minimum is shown disabled and refused                          | No driver sets a maximum today                                    |
| M-14 | PG-05        | Low    | `tests/Feature/Billing/HostedPaymentsTest.php` :: cancelling on the sandbox hosted page closes the attempt at once                                                               |                                                                   |
| M-15 | PG-40        | Low    | `tests/Feature/Billing/ClientBillingTest.php` :: the hosted-payment hint names the destination, not the method's display label                                                   | Blocked: #121                                                     |

Not a test but owed: the live runs listed under the results log, including Stripe 3-D Secure (PG-10), which can't be
automated here.

## 4. Redundant, overstated or low-value tests

Nothing below has been changed or deleted: project rule, no test deletion without approval. Test run times were not
measured for this audit (the full suite was not run), so no test is flagged for being slow.

| #    | Test(s)                                         | Finding                                                                                                                                                                                                                                                                             | Suggestion                                                                                                 |
| ---- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| R-01 | ES-04                                           | Duplicate. ES-03 already settles a COMPLETE payment through the poller, and HP-03 proves the shared poller. ES-04 adds only a status response that is already faked in ES-03.                                                                                                       | Remove after approval.                                                                                     |
| R-02 | KH-13 vs CS-06                                  | Duplicate. Both start a Khalti payment below the Rs 10 minimum and assert the refusal and zero attempts; CS-06 also checks the disabled reason and the preselection.                                                                                                                | Remove KH-13, or keep only its `sandbox` flag assertion inside CS-06.                                      |
| R-03 | KH-04, ES-03 (repeat part), HP-01 (replay part) | Same assertion three times: repeated returns plus the poller give one transaction and one `InvoicePaid`. The idempotency lives in Billing's `SettlePaymentAttempt` and `ApplyPayment`, not in the drivers, which only answer `lookup()`.                                            | Fold into one dataset over all hosted drivers in HP-11. Keep SW-06: Stripe adds a webhook and return race. |
| R-04 | SC-08 (first part), KH-17 (second part), SB-02  | Same rule three times: `ConfigureGateway` refuses sandbox mode in production with the same message. eSewa, which uses the same rule, has no such test.                                                                                                                              | One dataset over every driver with a sandbox setting; it would also close the eSewa gap (footnote 9).      |
| R-05 | KH-21                                           | Misplaced and low value for a driver test: it lives in the Khalti file but checks the eSewa and bank transfer options too, and asserts that three image files exist.                                                                                                                | Move to a shared client pay-panel test.                                                                    |
| R-06 | HP-11                                           | The name overstates the test. It checks that `currencies()` is not empty, the limits allow 5,000, two lookups against an always-pending fake agree, and no secret is in `raw`. The contract in `payments-nepal.md` §5 also lists unsupported-currency refusal and tampered returns. | Strengthen (M-06) rather than rename.                                                                      |
| R-07 | SW-13                                           | The name says "refunds made from our admin" but its datasets are a pending refund and a refund of a payment made elsewhere; the admin case is asserted in SR-01.                                                                                                                    | Rename to match the datasets.                                                                              |
| R-08 | SW-14                                           | The name says the invoice is "flagged for staff", but the test asserts the Collections status and one `PaymentReversed`; no staff flag or to-do is checked.                                                                                                                         | Rename, or assert the staff-facing signal.                                                                 |
| R-09 | SC-15                                           | Restates configuration: it asserts the exact numbers 5/15 and 3/6. The rule that matters is "short for a waiting customer, bounded otherwise"; a changed default breaks the test without a behaviour change.                                                                        | Keep; consider asserting upper bounds.                                                                     |

Overlaps checked and kept (each covers a different layer): PY-01 and WH-01 (payment service and webhook route); HP-04
and `RegistriesTest` "billing automation tasks are registered in pipeline order" (HP-04 runs a payment through
`automation:run`); the per-driver connection tests SC-09, KH-20 and ES-13 against AS-02 (each driver has its own
secret fields); ES-05 as the positive control for ES-06.

## 5. Keeping this page current

- **Every integration PR updates its column.** A PR that adds or changes a driver, or Billing's hosted-payment code,
  adds its new tests to the test index with codes and cases, and updates the matrix cells. A PR that removes or
  renames a test updates its row. A new gateway adds a column.
- **A new case needs a source.** Add a `PG-xx` row only when a spec, ADR, security standard, owner correction or
  incident requires it, and name the source. Numbers are never reused.
- **Every live sandbox run adds dated rows** to the results log (date, gateway, case, PASS / FAIL / PENDING /
  NOT RUN, evidence, issue), and the matrix cell shows the latest result. Old rows stay.
- **The reviewer checks the matrix.** On an integration PR, the review board or QA reviewer confirms that every
  standard case that applies to the gateway has a test or a `GAP` entry with a row under missing tests, and that
  tests cited in the matrix exist with those exact names. A `GAP` on a High-risk case blocks go-live, not the PR.
- **Gaps close by moving rows.** When a missing test is written, remove its `M-` row and put the test code in the
  matrix. When a flagged test is removed or renamed with approval, remove its `R-` row.

## Other integration families

The same layout will hold control panels and registrars on their own pages (`control-panel-test-matrix.md`,
`registrar-test-matrix.md`) or as new sections here, whichever stays shorter:

- **Control panels (`CP-`)**, starting with cPanel/WHM. Likely cases from `provisioning-cpanel.md` and security
  standard §7: create, suspend, unsuspend, terminate; timeout after commit, adopted only with proof; a foreign
  account with the same name never adopted; a rotated certificate refused; reseller scoping; SSO links single-use.
  The WHM simulator (S2, `tools/simulators/tests/Whm/`) already covers several.
- **Registrars (`RG-`)**: register, renew, transfer in, nameserver and contact updates, lock and EPP code, an
  asynchronous action status, a refused IP allow-list.

Columns are the drivers (one per provider), plus a "Shared" column for the module's own code, exactly as above.
