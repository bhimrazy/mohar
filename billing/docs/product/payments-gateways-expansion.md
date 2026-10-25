# Payments — gateway expansion: connectIPS, Fonepay, cards in Nepal, more wallets, India (design, v1)

Status: research and design; the Razorpay driver (R1) is built against our simulator; owner and CTO answers to §10.5 recorded 2026-10-25 (§10.6) · Owner: Billing (payments) · Extends `payments-nepal.md` (Khalti,
eSewa built) and `payments-stripe.md` (Stripe built) · Public docs read 2026-10-20 and re-checked 2026-10-25 (§14)

Clean-room: written from the providers' public developer docs, fee schedules and news, in our own words. No SDK,
plugin or doc text is copied. Field names are quoted only where a driver must send them exactly. Where only
community code or plugins describe a provider, this spec says so and treats the shape as unverified.

## 1. Purpose and scope

The owner's storefront advertises "Payment options we accept": Khalti, eSewa, connectIPS, Fonepay, Stripe, bank
transfer, and Mastercard/Visa cards. Khalti, eSewa, Stripe, bank transfer and manual payments are built. This spec
covers the rest, so the storefront promise is true for an NPR customer, and sets the order of work:

1. **connectIPS** (NCHL): direct bank-account payments, for every Nepali bank customer.
2. **Fonepay**: QR and bank-app payments from the F1Soft network.
3. **Visa/Mastercard for NPR**: how a Nepali host can take cards at all.
4. **More wallets** (IME Pay, Prabhu Pay, CellPay, Hamro Pay) and the aggregators that could reach several at once.
5. **India** (Razorpay is already planned; Cashfree, PayU, PhonePe PG, UPI), only to set its priority against Nepal.

For each provider: the integration style and how it maps onto our gateway contracts, security points, whether we
can verify it without the owner (public sandbox or not), refunds and settlement, fees and onboarding, and risks.
§10 gives the recommended order, PR estimates, simulator needs and what the owner must provide.

**Out of scope:** building any driver (this is a spec), the saved-card and auto-capture work (`payments-stripe.md`
§7) and Indian recurring mandates that depend on it, PayPal (planned separately; it cannot pay out to Nepali
merchants, `payments-nepal.md` §1), mobile-app SDK flows (eSewa Intent, Khalti app deeplinks), in-person POS QR,
automatic settlement import (§9.6, later), and a dedicated driver per small wallet (§7).

## 2. Key facts that shape the design

- **Every Nepal provider is redirect-and-verify.** None of them pushes a signed server-to-server result for web
  checkout that we could rely on alone. Each new Nepal driver is a `HostedPaymentDriver` whose `lookup()` is the only
  thing that can mark an attempt paid, plus the existing reconciliation poller. This is already built and proven by
  Khalti and eSewa; the new drivers add no new settlement path.
- **Most of it cannot be verified without the owner.** Only eSewa (published UAT keys) and Khalti (self-serve
  test-admin) have public sandboxes. connectIPS UAT credentials come from NCHL on request, Fonepay's spec and
  credentials come from a partner bank, and card acquiring needs a bank contract. So the order is driven as much by
  what the owner can obtain as by value (§10).
- **Card acquiring in Nepal is a bank relationship, not a sign-up form.** Stripe and Razorpay do not onboard Nepali
  entities. A Nepali host takes Visa/Mastercard through a Nepali bank's hosted payment page (the bank contracts a
  processor such as Cybersource or 2C2P) or through a licensed payment aggregator that has done this
  already (§6).
- **Khalti already reaches more than its wallet.** Khalti's hosted page offers the Khalti wallet, partner-bank
  e-banking, mobile banking, connectIPS and SCT/Visa cards, and IME Pay is now part of IME Khalti. So our built
  Khalti driver already lets a customer pay from a bank account or some cards. A direct connectIPS driver is still
  worth it for the fee (a flat NPR 2 to 8 against a wallet percentage) and for money landing straight in the bank.
- **QR is not one network yet.** Nepal has three merchant QR schemes (Fonepay, NCHL's NepalPay QR, SmartQR) with
  partial interoperability: in 2026 reporting, eSewa scans only Fonepay QRs and NepalPay does not interoperate with
  Fonepay, while Khalti scans all three. One QR integration does not reach every app (§5.3, §7).
- **NPR only.** Every Nepal provider settles in NPR. USD or INR invoices use these gateways only through the gateway's
  `convert_to_currency = NPR`, with the stored rate, as Khalti and eSewa already do.
- **Our attempt reference is 26 characters.** connectIPS `TXNID` allows 20 and Fonepay `PRN` 25, so both drivers need
  a short provider reference per attempt (`payments-nepal.md` §8.4). §9 gives the Billing change this needs.

## 3. Comparison

| Provider                                                                           | Integration style                                                                                       | Fits our contracts                                                                      | Request auth                                                                        | Browser return         | Server status check                                                     | Refunds                                      | Public sandbox                                                                        | Onboarding                                                     | Fees (public)                                                     | Verify without owner?                              |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ---------------------- | ----------------------------------------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------- |
| **connectIPS** (NCHL)                                                              | Browser form POST to NCHL, bank login and OTP, redirect back with `TXNID`                               | `HostedPaymentDriver`, `FormPost`, `lookup()` via validatetxn (+ gettxndetail)          | RSA SHA-256 signature with the merchant's private key (from .pfx)                   | Unsigned, only `TXNID` | `validatetxn` (Basic auth + signed token); `gettxndetail` for fee, bank | None by API ("cannot be reversed"); bank     | No. UAT account by email to NCHL support                                              | Through the merchant's bank (KYC), then NCHL enrolment         | NPR 2 / 4 / 8 per payment by amount slab; banks may add their own | Signing and flow: yes (simulator). Live: no        |
| **Fonepay** web                                                                    | GET redirect with HMAC-SHA512 `DV`, signed return, verify call                                          | `HostedPaymentDriver`, `RedirectInstruction`, `lookup()`                                | HMAC-SHA512 (hex) with merchant secret                                              | Signed (`DV`)          | Merchant verification call; reported to need the return's `UID`         | Not public; reported manual                  | No. Dev host and credentials from the bank                                            | Through a Fonepay partner bank                                 | Not public (one reseller claims NPR 25,000 + VAT; unconfirmed)    | No: no official public spec                        |
| **Fonepay** dynamic QR (third-party v2)                                            | Server creates a QR, customer scans it in a Fonepay-network bank or wallet app, we poll status by `PRN` | `HostedPaymentDriver`, `QrPayload`, `lookup()`                                          | Username/password + RSA-signed requests (community)                                 | None (no redirect)     | Status by `PRN`                                                         | Not public                                   | No                                                                                    | Through a partner bank ("billing API" request)                 | Not public                                                        | No                                                 |
| **NepalPay QR** (NCHL) (dropped)                                                   | Server asks NCHL for a dynamic NepalQR; scan from any interoperable app                                 | `HostedPaymentDriver`, `QrPayload`, `lookup()` via transaction report                   | Basic auth + RSA SHA-256 signed token (.pfx)                                        | None                   | Merchant transaction report by trace id                                 | Report shows refund rows; process not public | No. Same NCHL route as connectIPS                                                     | Through NCHL and the acquiring member; merchant access unclear | Not public for QR                                                 | Signing and flow: yes (public spec). Live: no      |
| **Cards: NIC Asia** (Cybersource Secure Acceptance Hosted Checkout)                | Browser form POST of HMAC-signed fields to Cybersource's hosted page; signed POST back                  | `HostedPaymentDriver`, `FormPost`, `lookup()` via Cybersource REST (REST keys required) | HMAC-SHA256 over `signed_field_names` (profile id, access key, secret key)          | Signed (same scheme)   | REST transaction query (REST keys required, §6.2)                       | REST refund (full, partial)                  | Yes: free self-serve Cybersource evaluation account (generic, not the bank's profile) | NIC Asia current account and service agreement                 | Bank tariff (MDR not public)                                      | Mostly: public spec, simulator, evaluation account |
| **Cards: other banks** (HBL 2C2P PACO, Nabil TranzAxis, NCHL-hosted bank gateways) | Bank-specific hosted pages; HBL uses JOSE-encrypted, RSA-signed API calls                               | `HostedPaymentDriver`                                                                   | Bank-specific                                                                       | Bank-specific          | Bank-specific                                                           | Mostly bank portal                           | No                                                                                    | Bank agreement                                                 | Not public                                                        | No                                                 |
| **NPS OnePG** (aggregator)                                                         | Hosted page with the payer's choice of bank, wallet, connectIPS (cards unconfirmed)                     | `HostedPaymentDriver`                                                                   | Merchant id, API username/password and secret key (from an official client package) | Not public             | Not public                                                              | Not public                                   | No (docs not public)                                                                  | Aggregator merchant agreement                                  | Not public                                                        | No                                                 |
| **Razorpay** (India)                                                               | Server order, hosted Checkout, signed callback and webhooks                                             | `HostedPaymentDriver` + `WEBHOOK` (`attemptUpdated`), `REFUND`, `PARTIAL_REFUND`        | Basic auth key id + secret; HMAC-SHA256 payment signature                           | Signed                 | Fetch order / payments                                                  | API, full and partial                        | Yes: test mode with test keys                                                         | Indian entity, KYC for live                                    | Public pricing                                                    | Yes, in test mode                                  |

Detailed rows for the India providers are in §8, and the extra wallets are in §7.

## 4. connectIPS (NCHL)

connectIPS is Nepal Clearing House's payment system. Its gateway lets a customer pay a merchant directly from a bank
account: the customer logs in to connectIPS, picks a linked account, confirms with an OTP, and the money moves
between banks over NCHL's real-time rail. It reaches the customers of every member bank, which makes it the closest
thing Nepal has to a universal online debit.

### 4.1 Flow

1. **Initiate (no provider call).** The driver builds the payment form and signs it. `initiate()` returns a
   `FormPost` to `https://{base}/connectipswebgw/loginpage` with these fields (maximum lengths from the merchant
   interface page):
    - `MERCHANTID` (number, 20): issued by NCHL at enrolment.
    - `APPID` (15) and `APPNAME` (30): one app per settlement bank account; a merchant can have several.
    - `TXNID` (20): unique per app and per request. This is our short provider reference (§9.1).
    - `TXNDATE` (10): `DD-MM-YYYY`, the date in Nepal time (Asia/Kathmandu), not the company time zone.
    - `TXNCRNCY` (3): `NPR`.
    - `TXNAMT` (number, 20): amount in **paisa**, our minor unit, so no conversion.
    - `REFERENCEID` (20), `REMARKS` (50), `PARTICULARS` (100): we send the invoice number, a short remark and the
      brand name, each truncated to its limit and stripped to safe characters.
    - `TOKEN` (512): the signature (§4.2).
2. **Customer pays at connectIPS.** Bank login, account choice, OTP.
3. **Return.** NCHL redirects to the success or failure URL **registered with NCHL** (they are static, not per
   request) and appends only `TXNID`. The return is unsigned and carries no status. `parseReturn()` reads `TXNID` into
   `ReturnHint::$providerRef` with `reference = null`, and Billing finds the attempt by gateway and provider
   reference (§9.1). The same `TXNID` can arrive more than once; the route is already idempotent.
4. **Lookup.** `lookup()` calls `POST https://{base}/connectipswebws/api/creditor/validatetxn` with HTTP Basic auth
   (user = `APPID`, password = the app password NCHL emails) and a JSON body of `merchantId`, `appId`,
   `referenceId` (our `TXNID`), `txnAmt` (paisa, from the stored attempt, never from the request) and `token`.
    - `SUCCESS` → succeeded, but only after the echoed `referenceId`, `merchantId`, `appId` and `txnAmt` match the
      stored attempt.
    - `FAILED` → failed.
    - `ERROR` "TRANSACTION NOT FOUND" (the customer never paid) → `not_found`; the attempt expires after its TTL plus
      grace, as with eSewa.
    - `ERROR` "TRANSACTION INCOMPLETE" (left before the OTP) → pending until the TTL, then expired.
    - Anything else, or a body for another transaction → `ambiguous` (staff review, never auto-failed).
    - HTTP 401 → our credentials are wrong: ambiguous with a clear reason, and the connection test reports it.
5. **Details (fee, bank).** After a `SUCCESS`, `lookup()` also calls `.../gettxndetail` (same request and token) to
   read NCHL's own `txnId` (stored as `providerTxnId`), `debitBankCode`, `chargeAmt` and `chargeLiability`.
   `chargeAmt` is recorded as the fee only when `chargeLiability = MN` (merchant pays). `creditStatus` of `000`,
   `999` or `DEFER` means the credit leg is done; any other value with a `SUCCESS` validation goes to staff review.
   ~~If gettxndetail fails, the payment still settles on validatetxn and the fee stays 0 with a note; the poller does
   not retry a settled attempt just for the fee.~~
   **Amendment (#491), 2026-10-25:** nothing settles without gettxndetail, since the credit leg is part of the
   rule. A timeout, connection error or 5xx after a validatetxn SUCCESS makes the attempt processing: no expiry, so
   the invoice stays locked, the poller books it once gettxndetail answers, and staff review it after the grace
   period (an attempt already in review keeps its reason). Any other failure (4xx, a refused token, no status, a
   non-SUCCESS answer) sends it to staff review as "credit leg unconfirmed". An attempt in review (for example `creditStatus` F01) is never booked by a later failed call.

### 4.2 Signing and the certificate

- **Payment token:** build one line of 11 `NAME=value` pairs joined by commas, in this order, with no whitespace;
  the last pair is the literal text `TOKEN=TOKEN`:

    ```text
    MERCHANTID=…,APPID=…,APPNAME=…,TXNID=…,TXNDATE=…,TXNCRNCY=…,TXNAMT=…,REFERENCEID=…,REMARKS=…,PARTICULARS=…,TOKEN=TOKEN
    ```

    Sign it with RSA PKCS#1 v1.5 over SHA-256 using the merchant's private key, and base64 the signature. The values
    must be byte for byte the ones in the form.

- **Pitfall, still open:** the page's template shows a comma plus a space between some pairs, while its worked
  example (and every community integration) has no spaces. We follow the example and prove it in UAT. The driver
  keeps the canonical string in one place so a correction is a one-line change.
- **Validation token:** `MERCHANTID=…,APPID=…,REFERENCEID=<TXNID>,TXNAMT=<paisa>`, signed the same way. The page
  does not name the algorithm for this one; we assume the same SHA-256 RSA signature and prove it in UAT.
- **The key.** NCHL supplies the merchant's signing key as a PKCS#12 file (`.pfx`) with a passphrase; the test one is
  named `CREDITOR.pfx` in the docs. We never keep the file. Staff upload it once in the gateway form with its
  passphrase; the server reads it in memory (`openssl_pkcs12_read`), keeps the private key as PEM in the encrypted
  gateway settings, and discards the file and the passphrase. The form then shows the certificate's subject, serial
  and expiry date, never the key.
- **Our approach:** keep the key encrypted in the database rather than as a file path on the server, show its expiry,
  and warn staff 30 days before (§9.2), so a lapsed certificate is not discovered at checkout.
- NCHL does not sign its responses (the response `token` is null), so we rely on TLS to the pinned host and on the
  echoed fields matching the stored attempt.

### 4.3 Environments and credentials

- **Hosts are not in the public docs.** The pages say `{base_url}`. Community integrations use `uat.connectips.com`
  for UAT and `login.connectips.com` for live. The driver pins hosts per mode in `payments_nepal.connectips` config,
  as Khalti and eSewa do, and the owner confirms them at onboarding (Q2).
- **UAT credentials:** the exception-handling page says to email connectIPS technical support for a test account.
  There are no published test credentials, so nothing beyond the simulator can be checked until the owner (or a
  developer on the owner's behalf) asks NCHL. Localhost return URLs are accepted in test, per the merchant interface
  page.
- **Return URLs** must be sent to the connectIPS integration team. The admin gateway form shows the exact return URL
  to register (§9.3). Without it, NCHL answers 403 "session expired" after the OTP.

### 4.4 Refunds, settlement and reconciliation

- **Refunds:** the connectIPS FAQ says a payment cannot be stopped or reversed through the system. There is no refund
  API. A merchant refunds by bank transfer (for example a connectIPS transfer from the merchant's own account) and
  records it. The driver does not declare `REFUND`; the admin refund dialog shows "Refund by bank transfer, then
  record it here", the same pattern as eSewa.
- **Settlement:** the money is credited to the bank account linked to the app, in real time in the normal case.
  Failed or timed-out credits are resolved by the banks after reconciliation, normally the next working day. This is
  why `creditStatus` matters: a `DEFER` is treated as paid by NCHL's own rule, but a credit that later fails is a
  bank-level exception the merchant sees in its bank statement, not something we can detect by API.
- **Reconciliation:** the NCHL merchant portal has reports; there is no public report API for the gateway. Staff
  reconcile from the portal export against our transactions by `TXNID` and NCHL `txnId`, both of which we store.
- **Limits:** the FAQ lists NPR 2,000,000 per web transaction and per day per bank, lower if the customer's bank
  sets lower. `limits()` returns min 100 paisa and max 200,000,000 paisa by default (config), and the pay panel's
  existing "can't take this invoice" reason covers anything over.

### 4.5 Fees and onboarding

- **Fees:** NCHL's fee schedule (effective 30 May 2025) charges creditor/merchant payments NPR 2 up to NPR 500, NPR 4
  above 500 up to 5,000, and NPR 8 above 5,000, reported as VAT-exempt under the 2082 finance ordinance (not confirmed in the schedule we read: to verify). When the creditor bears
  the fee the customer pays nothing. Member banks may add their own charges. This is far cheaper than wallet or card
  rates for a typical NPR 3,000 to 30,000 hosting invoice.
- **Onboarding:** the merchant applies through its own bank, which does KYC and asks NCHL to list it as a creditor.
  NCHL then emails the merchant id and password, issues the app id(s) and the signing certificate, and enables the
  merchant portal. NCHL publishes no document checklist; expect the bank's usual company KYC (registration
  certificate, PAN/VAT, board resolution, signatories). Timeline not public.

### 4.6 Risks and unknowns

- The token string format (spaces or none) and the validation token's algorithm: prove in UAT (Q3).
- `txnAmt` units: validatetxn shows `"500"` and gettxndetail shows `1000.00` in the docs. We compare only
  validatetxn's amount, normalised from either form, and treat a value that is neither the paisa nor the rupee form of
  the stored amount as a mismatch.
  **Amendment (#491), 2026-10-25:** both answers are compared, by form: an integer or bare digits are paisa, and a
  value with a decimal point is rupees. So `"1130"` never matches Rs 1,130.00 (it would be Rs 11.30).
- The 403 "multiple gateway payments" for a reused `TXNID`: every retry is a new attempt with a new `TXNID`.
- Hosts and the certificate's validity period are not public.
- The connection test (`ConnectionTester`): validatetxn for a random `TXNID`. HTTP 401 means wrong app id or
  password; "TRANSACTION NOT FOUND" means credentials and token are accepted. What NCHL answers for a bad token is not
  documented; the test reports any other answer as "could not confirm" until UAT shows the real reply. It never moves
  money and books nothing.

## 5. Fonepay and NepalQR

Fonepay (F1Soft) is a payment network run with its partner banks. Its QR is printed in most Nepali shops, and the
bank apps of its partner banks can pay a Fonepay QR. For online merchants it has three products, none with public
developer docs:

### 5.1 Fonepay web payment (redirect)

The shape below is from community packages and plugins (§14) and is **unverified**. A partner bank supplies the real
spec with the merchant credentials.

- `initiate()` returns a `RedirectInstruction` to `https://clientapi.fonepay.com/api/merchantRequest` (a `dev-` host
  for test) with the merchant code `PID`, mode `MD=P`, our short reference `PRN` (3 to 25 characters), `AMT` in
  rupees with two decimals, `CRN=NPR`, `DT` (`MM/DD/YYYY`), two remarks `R1`, `R2`, the return URL `RU`, and `DV`: the
  hex HMAC-SHA512 of the values in that order, joined by commas, keyed with the merchant secret.
- The return carries `PRN, PID, PS, RC, UID, BC, INI, P_AMT, R_AMT` and a `DV` over them. `parseReturn()` checks the
  `DV` (case-insensitive hex, constant time) and that `PRN` and `PID` are ours. As with eSewa, a valid signature only
  makes the hint trustworthy; it never settles.
- `lookup()` calls the merchant verification endpoint with `PRN`, `PID`, the bank code, the amount from the stored
  attempt, `UID` and a `DV` over them; it reportedly answers XML with a success flag and a response code.
- **Problem:** the verification call is reported to need the `UID` (Fonepay's transaction id) that only arrives on the
  return. An attempt whose customer never came back could then not be checked by the poller. This needs either a
  status-by-`PRN` call (the QR API has one) or the contract change in §9.4. Confirm with the bank spec (Q5).

### 5.2 Fonepay dynamic QR and "Checkout by Fonepay"

- **Dynamic QR (third-party API v2):** a community Laravel package for it uses a dev host
  `dev-external-gateway-new.fonepay.com`, a merchant username, password and terminal id, RSA-signed requests, a
  "generate QR" call that returns the QR string and the `PRN`, and a "payment status by `PRN`" call. Fonepay's own blog
  says merchants get Dynamic QR through their bank. A reseller describes the same thing as the "Fonepay billing API",
  requested from the bank for billing or POS software.
- This fits `QrPayload` (already in the contract and rendered by the client checkout page): `initiate()` returns the
  QR string and its expiry, the page shows the QR and polls our own status endpoint, and Billing settles only through
  `lookup()` by `PRN`. Fonepay's websocket notification is a browser convenience; we never settle from it.
- **Checkout by Fonepay** (launched 8 July 2026): customers approve the payment inside their bank app without leaving
  the merchant's checkout, through 11 partner banks so far (among them Nabil, Prabhu, Sanima, Laxmi Sunrise, Rastriya
  Banijya). No public integration details; one to ask the bank about.
- **Our approach:** a QR on the invoice page lets a customer on a laptop pay with the phone they already bank on,
  with no login on our side and no redirect.

### 5.3 NepalPay QR (NCHL): dropped from the plan (owner decision Q6, 2026-10-25)

**Decision:** dropped. Research on 2026-10-25 found no web platform, hosting company or plugin that takes NepalPay QR through a merchant API; the public spec is for banks and payment operators (§10.6). The rest of this section stays as the record of what was read, and is not a build candidate.

NCHL publishes a full NepalPay QR specification (doc.connectips.com, NepalPAY-QR section). It is the national
interoperable QR built to Nepal Rastra Bank's NepalQR framework, settled through NCHL with other QR schemes.

- **Generate:** `POST /qr/generateQR` with Basic auth and a token signed SHA256withRSA with a `.pfx` key (the test key
  is `NPI.pfx`), the same scheme as connectIPS. A dynamic QR (`pointOfInitialization` 12) carries the amount and our
  bill number; the reply gives the EMV QR string and NCHL's 20-character `validationTraceId`.
- **Status:** a merchant transaction report call, by `validationTraceId` or date range, returns each attempt with
  `debitStatus` and `creditStatus`; final when debit is `000` and credit is `000`, `999` or `DEFER`, the same rule as
  connectIPS. One trace id can return a failed and a later successful row; `lookup()` must pick the successful one.
- **Push:** NCHL can call a payment-confirmation endpoint the participant exposes, signed with RSA. We would treat it
  as `CallbackEvent::attemptUpdated()` (a nudge to look up), never as settlement.
- **Unknown, probably not for us:** the spec is written for participants (banks and PSPs) on NCHL's National
  Payments Interface, and its reporting call takes an acquirer or issuer id. A hosting company most likely gets a
  NepalPay QR through its acquiring bank, not direct API access (Q6).
- **Reach:** NepalPay QR is interoperable with SmartQR but, per 2026 reporting, not with Fonepay, and eSewa does not
  scan it. So it does not replace Fonepay.
- **Why it was kept, and why it is now dropped:** it would share the connectIPS signing code, but nothing shows a
  merchant can reach the API. Reopen only if a bank or NCHL offers a hosting merchant direct access in writing.

### 5.4 Fonepay onboarding and fees

**Owner decision (2026-10-25):** keep Fonepay as "build when the partner bank supplies the spec". Research on 2026-10-25 again found no official public developer documentation; every web integration we found (community Laravel, Flutter and Python packages, WordPress plugins) uses merchant code plus secret with an HMAC-SHA512 redirect, or username, password, terminal id and RSA signing for the QR API, and plugin support threads show "verification failure" when sandbox and live credentials are mixed. This matches §5.1 and §5.2 and changes nothing in the design (§10.6).

- Through a Fonepay partner bank: a registered business, a business account with that bank, then a request to enable
  web payment or Dynamic QR. Credentials come from the bank: merchant code and secret for web; username, password and
  terminal id (and keys) for the QR API.
- Fees are set by the bank agreement and are not public. One reseller states a direct integration typically costs
  NPR 25,000 plus VAT; treat that as a claim to confirm.

## 6. Visa and Mastercard for NPR

### 6.1 How Nepali merchants actually take cards

- There is no self-serve card acquirer for a Nepali business. Stripe does not list Nepal, Razorpay and Cashfree need
  an Indian entity, and PayPal does not pay out to Nepali accounts. A Nepali merchant signs a service agreement with a
  Nepali acquiring bank, holds a current account there, and the bank provisions a hosted card page.
- Which processor depends on the bank:
    - **NIC Asia Bank:** Cybersource (Visa Acceptance) since 2020, for domestic and international cards, with 3-D
      Secure, multi-currency, pay-by-link and a virtual terminal. Off-the-shelf shop plugins use Secure Acceptance
      Hosted Checkout with a profile id, access key and secret key issued by the bank. Apple Pay and Google Pay were
      added on 2 October 2026. Fees follow the bank's tariff; no MDR is published.
    - **Himalayan Bank:** 2C2P, international cards since 2015 and domestic since 2020. A public plugin guide shows
      the bank issuing an office id, API key and encryption key id, while the merchant creates two RSA key pairs:
      2C2P's "PACO" API with JOSE encryption and signing. Much heavier than an HMAC form.
    - **Nabil Bank:** in-house acquiring on Compass Plus TranzAxis with a hosted card page; one plugin says the store
      currency must match the merchant currency, NPR or USD. No public spec.
    - **Global IME, Siddhartha and NMB banks (2025):** card gateways run with NCHL's EFT card services (switching,
      e-commerce gateway, 3-D Secure). NCHL reported 17 member institutions in September 2026, six offering the
      e-commerce service to over 115 merchants. The integration protocol is not public.
- **What Nepali hosts show today:** the hosts we could read (Nest Nepal, WebsNP, Domain Nepal) offer eSewa, Khalti,
  Fonepay, connectIPS and bank transfer, sometimes PayPal for USD. None names a card gateway. Cards are the exception
  in this market, not the norm.
- **Cost and effort (unverified):** third-party guides quote 1.5 to 4 % MDR, NPR 10,000 to 50,000 setup, T+1 to T+5
  settlement and 4 to 8 weeks to get a merchant id. No bank publishes these numbers.

### 6.2 Recommended card driver: Cybersource Secure Acceptance Hosted Checkout (NIC Asia)

It is the only Nepal card route with a public specification and a self-serve test environment, and the bank that
uses it already ships plugins for it, so its onboarding is routine. It fits our contracts with no kernel change.

**Built (V1, 2026-10-25):** module `app/Modules/PaymentsCybersource`, gateway key `cybersource`, verified against the
simulator only. Choices made in the build: the cancel is posted to our cancel URL (`override_custom_cancel_page`);
REST has no record of a cancelled session, so a lookup that finds nothing ends the attempt early only after
Cybersource signed a CANCEL for it; USD is an operator switch (`CYBERSOURCE_CURRENCIES=NPR,USD`), because a
driver's currency list cannot depend on staff settings.

- **Initiate:** `initiate()` returns a `FormPost` to Cybersource's `/pay` endpoint (test host
  `testsecureacceptance.cybersource.com`, live `secureacceptance.cybersource.com`) with the bank-issued
  `access_key` and `profile_id`, a unique `transaction_uuid`, `signed_date_time` (UTC, ISO 8601),
  `transaction_type=sale`, `reference_number` (our attempt reference), `amount` (decimal string), `currency`
  (`NPR`, or `USD` if the agreement allows), `locale`, `signed_field_names`, `unsigned_field_names` (empty or listing only unsigned fields such as card entry on the hosted page, to verify) and `signature`: base64 HMAC-SHA256 over
  `name=value` pairs joined by commas in the order of `signed_field_names`, keyed with the secret key. This is the
  same scheme as eSewa's, so the signer and its tests are reused in shape.
- **Card data never touches us.** The customer types the card on Cybersource's page and passes 3-D Secure there. Our
  PCI scope stays that of a hosted redirect.
- **Return:** Cybersource posts the signed result fields to the profile's customer response URL (our return route
  accepts POST already). `parseReturn()` recomputes the signature from the received `signed_field_names` (rejecting
  any unknown or missing field, as for eSewa) and reads `decision` (ACCEPT, DECLINE, REVIEW, ERROR, CANCEL),
  `reason_code`, `req_reference_number`, `req_amount`, `req_currency` and `transaction_id` as hints.
- **Server-to-server notice:** the profile can also post the same signed fields to a merchant notification URL. We
  map it to `CallbackEvent::attemptUpdated()` (look it up now), never to settlement.
- **Lookup:** Cybersource's REST API (Transaction Search by our reference, then the transaction) with HTTP Signature
  authentication using a REST key id and shared secret created in the Business Center. Succeeded only for an
  authorised and captured sale whose amount, currency and reference match the stored attempt; REVIEW (Decision
  Manager) → `processing`, then staff review if it stays; DECLINE → failed; CANCEL → cancelled.
- **Decision (Q9, CTO, 2026-10-25): REST keys are required.** The driver does not settle from the signed notice and
  has no notice-only lookup. If the bank issues no REST keys, the gateway form says the driver cannot be activated
  until it does. Whether NIC Asia issues REST keys is still unknown and goes on the to-do list (§15).
- **Refunds:** REST follow-on refunds, full and partial (`REFUND`, `PARTIAL_REFUND`), since REST keys are required.
  The REST refund and transaction-search calls were read only in search summaries: to verify (§15).
- **Settlement:** to the merchant's NIC Asia account on the bank's schedule; Business Center reports for
  reconciliation, and Cybersource reporting APIs (a later `ProvidesSettlementReport`, §9.6).
- **Sandbox:** Cybersource offers a free self-serve evaluation account (a test Business Center), which exercises the
  same Secure Acceptance flow with our own test profile. The bank's real profile only comes with the agreement.
  **Decision (Q10, owner, 2026-10-25):** simulate now; a real evaluation account is a later to-do (§15).
- **Our approach:** confirm every payment from the server side and record the fee and transaction id, so a forged or
  replayed browser post cannot mark an invoice paid.

### 6.3 Other card routes

- **Himalayan Bank (2C2P PACO):** JOSE-encrypted, RSA-signed API calls and merchant-generated 4096-bit keys. Build
  only if the owner banks with HBL; it would need the certificate setting type (§9.2) for the key pairs. 2 to 3 PRs.
- **Nabil, Global IME, Siddhartha, NMB:** specs come from the bank. Treat like Fonepay: build from the bank's spec once
  the owner has an agreement.
- **Khalti as the interim card route:** Khalti's hosted page already accepts SCT cards and lists Visa card holders
  among its payers, so the storefront's card logos are partly true today through the built Khalti driver. Whether it
  takes foreign-issued Visa/Mastercard is not stated (verify in Khalti's sandbox, which needs no owner action).

### 6.4 NPS OnePG (aggregator)

- Nepal Payment Solution is an NRB-licensed payment system operator connected to NCHL's National Payments Interface.
  It claims connections to 40+ banks and 20+ wallets and has sold a "one link" checkout offering e-banking, mobile
  banking, connectIPS and wallets.
- Its OnePG developer docs are not public: the site renders no content to a reader and the developer host does not
  resolve. An official .NET client package shows a merchant id, API username and password, a secret key and gateway
  and API URLs, and an instrument-list call. A user-uploaded guide mentions signatures, a process id and a service
  charge. Card acquiring, sandbox, refunds and fees are all unknown.
- **Assessment:** potentially one integration for many banks and wallets, but nothing we can verify or simulate
  faithfully without NPS's spec under agreement. On request only.

## 7. More wallets: IME Pay, Prabhu Pay, CellPay, Hamro Pay

- **IME Pay:** merged into IME Khalti Limited (NRB final approval June 2025; integrated operations from about July
  2025). The app keeps the Khalti brand and IME Pay users migrate to it. Khalti covers them: no IME Pay driver.
- **Prabhu Pay, CellPay, Hamro Pay, Namaste Pay, MoCo:** none publishes official merchant web-payment docs. Only
  unaffiliated plugins and SDKs exist: Prabhu Pay (a WooCommerce plugin), Hamro Pay (Laravel and Flutter packages
  with UAT hosts and HMAC-SHA512 webhooks; one community library says self-service UAT keys exist), CellPay (an SDK
  mentioning T+1 settlement). Nothing for Namaste Pay or MoCo.
- **Reaching them without a driver each:** Fonepay is the dominant merchant QR network (around 80 % share in 2026
  reporting) and has on-boarded wallets such as Namaste Pay; Khalti scans all three QR schemes. A customer of a
  smaller wallet can usually pay a Fonepay QR or use connectIPS or their bank's app. With Khalti, eSewa, connectIPS
  and Fonepay a host covers nearly every Nepali payer.
- **Recommendation:** no dedicated wallet drivers. Hamro Pay is the first candidate if a customer asks, because its
  sandbox appears self-serve; confirm its official docs first.
- **Our approach:** add rails that reach many wallets and keep one settlement path for all of them.

## 8. India (to set priority)

None of these onboards a Nepali entity: Cashfree states the business must be registered in India, and the others
need Indian KYC or a cross-border agreement through sales. They matter for Indian hosts who use the product, not for
the owner's own storefront. All four have hosted checkouts, server-side status, signed webhooks and refund APIs, so
they fit `HostedPaymentDriver` plus `WEBHOOK` (pushes become `attemptUpdated`) plus `REFUND`/`PARTIAL_REFUND`.

|                      | Razorpay                                                      | Cashfree                                                   | PayU                                                    | PhonePe PG (v2)                              |
| -------------------- | ------------------------------------------------------------- | ---------------------------------------------------------- | ------------------------------------------------------- | -------------------------------------------- |
| Start                | Create order (or a Payment Link), then hosted Checkout        | Create order → `payment_session_id` → hosted checkout (JS) | SHA-512 hashed form POST                                | OAuth token → create payment → `redirectUrl` |
| Amount unit          | paise integer                                                 | rupee decimal (2 places)                                   | rupee decimal string                                    | paise integer (min 100)                      |
| Return check         | HMAC-SHA256 hex over order id and payment id joined by a pipe | none (look up the order)                                   | SHA-512 reverse hash                                    | none (look up the order)                     |
| Status               | fetch order / its payments                                    | get order (`PAID`) / payments                              | `verify_payment` command                                | order status                                 |
| Webhook auth         | HMAC-SHA256 of raw body; event id header for dedupe           | base64 HMAC-SHA256 of timestamp + raw body                 | reverse hash; form and JSON bodies                      | SHA-256 of username:password, or HMAC        |
| Webhook retries      | exponential for 24 h, then disabled                           | 3 by default (2, 10, 30 min; to verify), configurable      | not documented                                          | not documented                               |
| Refunds              | API, full and partial, normal or instant                      | API, full and partial, within 6 months (to verify)         | API (`cancel_refund_transaction`), partial for captured | API, up to the original amount               |
| Settlement report    | settlements and combined recon API                            | settlement recon API                                       | settlement APIs listed                                  | not found                                    |
| Self-serve test mode | yes, before KYC                                               | yes, before KYC                                            | yes, after sign-up                                      | through the integration team or dashboard    |
| Domestic fee         | 2 % + GST                                                     | 1.95 % + GST                                               | 2 % + GST                                               | about 1.99 % (search result only)            |
| WHMCS module         | official, from Razorpay                                       | official, from Cashfree                                    | third-party ones                                        | third-party ones                             |

- **Razorpay first** (already planned): self-serve test mode with published test cards and UPI ids
  (`success@razorpay`), paise integers like ours, a clear webhook scheme, refunds and a settlement recon API. It is the
  one India gateway whose test mode is self-serve, so it is the first to verify for real; for now it is simulated (Q7, §15). Design
  choice for its PR: Payment Links are a pure redirect and keep third-party script off our pages (our CSP allows only
  what each checkout needs); Orders plus Standard Checkout needs Razorpay's script on our checkout page. Prefer Payment
  Links unless the driver PR finds a gap.
- **Cashfree second:** official WHMCS module, so Indian hosts expect it. Watch the rupee-decimal amounts and the
  documented ambiguity in the webhook signature string (with or without a dot between timestamp and body): prove it in
  its sandbox.
- **PayU and PhonePe later**, on request. PayU's pipe-joined hash strings are easy to get wrong; PhonePe's sandbox is
  less self-serve.
- **UPI (to verify, secondary source):** NPCI ended merchant "collect" requests (the customer typing a UPI id) from 28 February 2026, with narrow
  exceptions. Hosted checkouts already offer intent on mobile and QR on desktop, so we build no UPI id entry of our
  own.
- **Recurring (later; to verify, secondary summary, RBI text unread):** RBI's e-mandate framework (consolidated April 2026) allows recurring debits up to ₹15,000
  without extra authentication, after an authenticated first payment and with a pre-debit notice; card-on-file
  tokenisation forbids storing card data. Renewals in India therefore go through the gateway's own tokens and
  mandates, which belongs with the saved-methods contract (`payments-stripe.md` §7), not this spec.
- **Priority against Nepal:** Razorpay can run in parallel with connectIPS because it needs nothing from NCHL or a
  bank, but it adds no value to the owner's own Nepali storefront. If the near-term goal is the owner's storefront,
  connectIPS and cards come first; Razorpay follows as soon as an engineer is free.

## 9. Contract and Billing changes this spec implies

Each item says where it lives. Kernel items (`app/Contracts`, `app/Data`, `app/Events`, `app/Support`) are made
centrally by the CTO session (ADR 0010 rules: additive, trailing defaulted fields, no breaking change).

### 9.1 Find an attempt by provider reference (Billing, no contract change)

- `payment_attempts` gets a unique index on `(gateway, provider_ref)` (nullable).
- connectIPS and Fonepay drivers generate a short random reference in `initiate()` (20 lowercase base32 characters,
  about 100 bits) and return it as `FormPost::$providerRef` / `RedirectInstruction::$providerRef`; Billing stores it,
  and a collision on the unique index retries once with a new one.
- `GatewayReturnController`: when `ReturnHint::$reference` is null and `$providerRef` is set, load the attempt by
  gateway and provider reference. Same throttle and one-lookup-per-minute rule as today.
- `PaymentAttemptRef::$providerRef` already carries it to `lookup()`.

### 9.2 Certificate settings and expiry warnings (kernel)

- A new setting field type `certificate` in `App\Enums\FieldType` (and the `settingsFields()` type union): the admin
  form takes a `.pfx`/`.p12` upload plus its passphrase, the server converts it to a PEM private key in memory, and
  only the PEM is stored, encrypted, like any secret. It is write-only (shown as "certificate set, expires
  2027-03-31") and an empty submit keeps the old one. Uploads are limited to 64 KB and parsed only by OpenSSL.
- The stored metadata (subject, serial, not-before, not-after) is kept beside the secret so the form and health
  check can show it without decrypting the key.
- A new event `App\Events\Billing\GatewayCredentialExpiring` (gateway key, what expires, when), raised by a daily
  check 30, 7 and 1 days before; Notifications sends the staff alert. The dashboard health check turns amber 30 days
  before and red after expiry. connectIPS uses it; card processors with certificates or expiring API
  passwords could too.
- Without this change a v1 driver could take a pasted PEM in a `secret_text` field, but staff would have to run
  OpenSSL themselves and nothing would warn before expiry. **Approved by the CTO (Q8, 2026-10-25).**

### 9.3 Show the URLs to register with the provider (Billing, no contract change)

The admin gateway form shows `webhook_url` only for `WEBHOOK` drivers today. It should also show the return URL
(`billing.gateways.return` for the gateway) for every `HostedPaymentDriver`, with a copy button and a note that
connectIPS needs it registered with NCHL. One static URL serves both success and failure: the lookup decides.

### 9.4 Return context for lookups (kernel, only if Fonepay needs it)

If the Fonepay spec confirms that verification needs values that only arrive on the return (`UID`, bank code):
`ReturnHint` gains a trailing `array $lookupContext = []` (at most 5 short string values, only when the signature is
valid), Billing stores it on the attempt, and `PaymentAttemptRef` gains a trailing `array $context = []`. We do this
only once the spec is in hand; if Fonepay offers status by `PRN`, no change is needed.

### 9.5 Webhook nudges for hosted drivers (none)

`CallbackEvent::attemptUpdated()` already lets a hosted driver turn a push (Razorpay webhook, a card
processor's notification) into "look this attempt up now". No change.

### 9.6 Settlement reports (later, kernel)

None of the Nepal providers offers a settlement report API that we could find; Razorpay, Cashfree and the card
processors do. A later optional interface `ProvidesSettlementReport` (fetch lines for a date range: provider txn id,
gross, fee, net, settled date) would let staff see which payments have reached the bank. Not part of this work; noted
so drivers keep `providerTxnId` and fee exact.

### 9.7 Events

- **Emitted (unchanged, by Billing):** `PaymentReceived`, `InvoicePaid`, `InvoicePartiallyPaid`, `PaymentPending`,
  `PaymentFailed`, `PaymentReversed`, `InvoiceRefunded`, `GatewayConfigurationChanged`. The new drivers emit nothing
  themselves; drivers never touch models or events.
- **New:** `GatewayCredentialExpiring` (§9.2), consumed by Notifications.
- **Consumed:** none new.

### 9.8 Entities and key fields

No new tables. Existing ones gain:

- `payment_attempts.provider_ref`: now unique per gateway (§9.1); holds connectIPS `TXNID`, Fonepay `PRN`, Cybersource `transaction_uuid`, Razorpay order or link id.
- `payment_attempts.raw` (redacted): connectIPS `debitBankCode`, NCHL `txnId` and `batchId`, `creditStatus`; card
  `decision` and `reason_code`; never the token, `DV`, signature or passphrase (the kernel `Redactor` already drops
  `token`, `signature`, `dv`, `hash` and `dataValidation`).
- `transactions.fee`: from connectIPS `chargeAmt` (merchant-borne only), Cybersource or Razorpay where reported.
- `gateways.config` (encrypted): the new drivers' settings, including the PEM key and certificate metadata (§9.2).

### 9.9 Automation

- The existing reconciliation poller covers every new hosted driver with no change; each driver's `attemptTtl()` sets
  how long it keeps looking (connectIPS 30 minutes by default, QR 15 minutes, cards 60 minutes; config values to
  confirm in UAT).
- New daily job: certificate and credential expiry check (§9.2), registered with the scheduler under ADR 0017.

## 10. Recommendation

### 10.1 Order

Scored on value to a Nepali host, effort, and whether we can verify without the owner.

| #   | Gateway                                                                                  | Value to Nepal hosts                                                                 | Effort                                       | Verifiable without owner                                           | When                                                                                             |
| --- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | -------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| 1   | **connectIPS**                                                                           | High: every bank customer, NPR 2–8 flat fee, on the storefront today                 | Medium (RSA signing, certificate, short ref) | Flow and signing yes (public spec, simulator); real UAT needs NCHL | Now, as an extension/driver module built and proven against our simulator; no NCHL UAT wait (Q1) |
| 2   | **Cards: Cybersource SA (NIC Asia)**                                                     | Medium: storefront promise, USD and foreign cards; cards are rare in this market     | Medium (HMAC form like eSewa, REST lookup)   | Mostly: public spec, simulator, free evaluation account (Q10)      | After connectIPS: NIC Asia Cybersource, built against our simulator (Q4, Q10)                    |
| 3   | **Fonepay** (web or QR, per bank)                                                        | High reach (dominant QR network) but eSewa, Khalti and connectIPS already cover most | Medium, but spec unknown                     | No: no public spec or sandbox                                      | When the owner has the partner bank's spec and dev keys                                          |
| 4   | **Razorpay** (India, planned)                                                            | None for Nepal; high for Indian hosts using the product                              | Medium (redirect, webhook, refunds)          | Yes, against the simulator now; a real test account later (§15)    | In parallel whenever an engineer is free; simulated for now (Q7)                                 |
| 5   | Cashfree (India)                                                                         | None for Nepal; Indian hosts expect it                                               | Medium                                       | Yes (self-serve sandbox)                                           | After Razorpay                                                                                   |
| 6   | NPS OnePG, Hamro Pay, other banks' card pages, PayU, PhonePe (NepalPay QR dropped, §5.3) | Low or unproven                                                                      | Varies                                       | No (except Hamro Pay possibly)                                     | On request only                                                                                  |

IME Pay: none (Khalti covers it). PayPal: planned separately, not for Nepali merchants.

### 10.2 PR estimates (each under 1,500 changed lines)

Line counts include tests. Driver PRs and simulator PRs are separate so each stays under 1,500 lines (ADR 0016 puts
a simulator at 1,000–2,000 lines; the larger ones are trimmed to the operations the driver uses).

| PR    | Scope                                                                                                                                                                                               | ≈ lines       | Depends on      |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------- | --------------- |
| K1    | Kernel (CTO): `certificate` setting type with in-memory PFX → PEM conversion and metadata; `GatewayCredentialExpiring` event; daily expiry check; health-check amber/red; Notifications staff alert | 800           | Approved (Q8)   |
| B1    | Billing: unique `(gateway, provider_ref)`; return route resolves by provider reference; return URL with copy button on hosted gateway forms                                                         | 350           | —               |
| C1    | connectIPS driver: form, RSA token, validatetxn and gettxndetail lookup, fee, limits, connection test, config hosts, logo, admin help text, refund note                                             | 1,100         | K1, B1          |
| C2    | connectIPS simulator (§10.3) and the driver run against it                                                                                                                                          | 1,300         | C1, ADR 0016 S1 |
| V1    | Cybersource Secure Acceptance driver: signed form, signed return, REST lookup with HTTP signature, REST refunds when keys exist, notice → `attemptUpdated`                                          | 1,400         | B1              |
| V2    | Cybersource simulator                                                                                                                                                                               | 1,400         | V1              |
| F1    | Fonepay driver from the bank's spec (web or QR); kernel return context (§9.4) only if the spec needs it, as a separate CTO change                                                                   | 1,000         | B1, bank spec   |
| F2    | Fonepay simulator from the same spec                                                                                                                                                                | 1,100         | F1              |
| R1    | Razorpay driver: Payment Link or order, lookup, webhooks, full and partial refunds, smoke command                                                                                                   | 1,400         | —               |
| R2    | Razorpay simulator (ADR 0016 S6 entry)                                                                                                                                                              | 1,400         | R1              |
| CF1–2 | Cashfree driver and simulator                                                                                                                                                                       | 1,300 + 1,300 | R1 (pattern)    |

Totals: connectIPS 4 PRs (K1, B1, C1, C2), cards 2, Fonepay 2, Razorpay 2, Cashfree 2.

Order change (2026-10-25): because nothing waits on NCHL, the simulator comes first. Build the simulator (C2) with or just ahead of the driver (C1) and keep the driver working against it. The driver ships as a self-contained extension/driver module behind the gateway driver registry, so it is integrated and working when the owner needs it, and switching to NCHL's test platform later is a change of host and credentials only (§15). The same applies to V1/V2 and R1/R2.

### 10.3 Simulators (ADR 0016)

Every simulator follows ADR 0016: stateful, TLS front, control plane, faults, conformance tests, recordings tagged
`source: docs` until a real account exists. Drivers reach it through `Endpoints::for()` with no simulator branch.

- **connectIPS:**
    - Login page that accepts the form POST, checks every field length and the RSA token against the scenario's
      certificate (the scenario CA issues a throwaway `.pfx` with a passphrase, so K1's upload path is exercised).
    - A hosted page with Pay, Decline, Leave before OTP and Abandon; redirects to the registered static URL with only
      `TXNID`; 403 "session expired" when no URL is registered; 403 duplicate on a reused `TXNID`.
    - `validatetxn` and `gettxndetail` with Basic auth, the four documented statuses, `txnAmt` as `"500"` and
      `1000.00` styles, `chargeLiability` MN and CG, `creditStatus` 000/999/DEFER/failed.
    - Faults: 401, 5xx, slow body, `commit_then_hang` on validatetxn.
- **Cybersource SA:** a hosted card page with test card numbers (approve, decline, review, 3-D Secure challenge
  stub), signed POST back to the response URL and to the notification URL (with replay, tampering and a wrong
  secret), REST transaction search and retrieve with HTTP Signature checking, refunds (full, partial, over-refund
  refused), and a missing-REST-keys case that must block activation (Q9). The REST calls are modelled from public docs, not from a real account (§15).
- **Fonepay:** built from the bank's spec, so its recordings are tagged `source: bank-spec`. Redirect with DV
  checking, signed return, verification (XML), or the QR generate and status-by-PRN calls; QR expiry; a payment made
  after the QR expires.
- **Razorpay:** order or Payment Link creation with idempotency, a hosted page with test cards and the
  `success`/`failure` UPI ids, signed callback, webhooks (signature over raw body, event id, out of order, retries,
  rotated secret), payments fetch, refunds with `speed`, settlements recon.
- **What simulators cannot prove** (ADR 0016 Limits): NCHL's real token format and replies, the bank's real
  Cybersource profile settings, Fonepay's real behaviour, real settlement. Go-live waits on a UAT or sandbox run for
  each.

### 10.4 What the owner must provide

| Gateway    | The owner provides                                                                                                                                                                                         |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| connectIPS | Creditor enrolment through the owner's bank; live enrolment when going live; the NCHL UAT account is not needed to build (§15); return URL registered with NCHL at go-live                                 |
| Cards      | Bank: NIC Asia (Q4); the service agreement; Secure Acceptance profile id, access key and secret key for test and live; REST key id and secret (required, Q9); NPR, and USD where the agreement allows (Q4) |
| Fonepay    | Which partner bank; the bank's integration spec; dev merchant code and secret (web) or username, password, terminal id and keys (QR); product choice (web, QR, Checkout by Fonepay)                        |
| Razorpay   | Nothing to build; a real Razorpay test account is a later to-do (§15)                                                                                                                                      |
| All        | Kernel change K1: approved by the CTO (Q8); owner informed                                                                                                                                                 |

### 10.5 Open questions and answers

Answered on 2026-10-25 by the owner and the CTO; Q2, Q3 and Q5 stay open for onboarding or UAT. The decisions are
written out in §10.6.

1. **Q1 (owner), answered:** do not wait on or request an NCHL UAT account. Build against our simulator (§10.6).
2. **Q2 (verify at onboarding):** the connectIPS UAT and live hosts (community code uses `uat.connectips.com` and
   `login.connectips.com`).
3. **Q3 (verify in UAT):** the connectIPS token string (no spaces, as in the example), the validation token's
   algorithm, and NCHL's reply to a bad token.
4. **Q4 (owner), answered:** support NIC Asia's Cybersource Secure Acceptance gateway, NPR, and USD where the
   agreement allows; simulator now.
5. **Q5 (verify with the bank spec):** Does Fonepay's verification need the return's `UID`, or is there a status
   check by `PRN`? Decides whether kernel change §9.4 is needed.
6. **Q6 (owner), answered:** NepalPay QR researched and dropped (§5.3).
7. **Q7 (owner), answered:** simulate Razorpay (and note Cashfree) for now; test accounts later (§15).
8. **Q8 (CTO), answered:** K1 approved.
9. **Q9 (CTO), answered:** require Cybersource REST keys; no notice-only lookup.
10. **Q10 (owner), answered:** simulate now; set up a real evaluation account later (§15).

### 10.6 Decisions recorded (2026-10-25)

- **Q1 connectIPS.** The owner does not want to wait on or request an NCHL UAT account. connectIPS is built as an
  extension/driver module, integrated and working when needed, and verified against our own simulator built from
  NCHL's public API (§10.3). If NCHL later provides its test platform, we run the driver against it (§15). Go-live
  still needs a UAT or sandbox run to prove the open points in §4.6 (ADR 0016 Limits).
- **Q4 cards.** Support NIC Asia's Cybersource Secure Acceptance gateway: NPR, and USD where the bank agreement
  allows. Same approach: simulator now (V2).
- **Q6 NepalPay QR.** Dropped. Public sources read on 2026-10-25 show NepalPay QR obtained through a bank, a spec
  for member institutions (merchant registration by bulk upload, a payment-confirmation endpoint the service
  provider exposes, reporting for network members), and no public sandbox. Searches for Nepali web platforms and
  plugins that use it found eSewa, Khalti, connectIPS and Fonepay only; the community multi-gateway packages we found
  cover those four. We found no merchant API route for a hosting company and no hosting company using it online.
  Not found is not proof of absence; the §5.3 reopen condition applies.
- **Q7 India.** Simulate Razorpay for now and note Cashfree after it. Real test platforms are set up later (§15).
- **Q10 Cybersource.** Simulate now; the real evaluation account is a later to-do (§15).
- **Q8 CTO.** K1 approved: the `certificate` setting type and the `GatewayCredentialExpiring` event (§9.2).
- **Q9 CTO.** REST keys are required for the Cybersource lookup (§6.2).
- **Fonepay.** No public spec found again; stays "build when the partner bank supplies the spec" (§5.4).

## 11. Admin and client capabilities

- **Admin, gateway form:** each new driver lists its settings with help text naming where each value comes from (for
  example "Merchant ID: emailed by NCHL after enrolment"); secrets and the certificate are write-only; the sandbox
  toggle follows `SandboxPolicy`; "Test connection" where the provider allows a harmless check; the return URL to
  register (§9.3); a one-line refunds note ("connectIPS has no refund API: refund by bank transfer, then record it").
- **Admin, transactions:** provider transaction id, fee where the provider reports it, the payer's bank (connectIPS
  `debitBankCode`), SANDBOX badge in test mode.
- **Client:** the pay panel lists each active method with its logo, limits and conversion line as today; connectIPS
  and Fonepay web are redirects; a QR method shows the QR on the checkout page with its expiry countdown, "I've paid,
  check now", and a fallback to another method once it expires.

## 12. Edge cases

- The customer pays twice in two tabs: each attempt has its own provider reference; the invoice lock refuses a second
  start while one is open (built); a second success after the first is booked goes to credit through the existing
  overpayment rule and raises a staff note.
- connectIPS `SUCCESS` with `creditStatus` other than `000`, `999` or `DEFER`: staff review, not paid.
- Return with a `TXNID` we never issued, or for another gateway: no match, the existing "couldn't match" message.
- The certificate expires mid-day: initiation fails with a safe message, the gateway is shown as needing attention,
  existing attempts can still be looked up only if NCHL accepts the old token (unknown), otherwise they go to review.
- A USD invoice paid through connectIPS: converted at the gateway's rate, the stored attempt amount is what is
  compared.
- `TXNDATE` at midnight: the date is taken once in Nepal time when the form is built and stored with the attempt.
- QR expired while the customer is paying in the bank app: the poller still looks the attempt up until TTL plus
  grace, so a late success is booked.
- The provider reports a refund on an attempt we have not booked: staff review (built, `payments-nepal.md` §8.5).

## 13. Acceptance criteria

**Shared (every new driver)**

1. MUST: An attempt is marked paid only from `lookup()`, after the provider's amount, currency and our reference
   (or provider reference) match the stored attempt; a browser return or a push, signed or not, never settles.
2. MUST: Given the return, the poller and any push for the same payment in any order, exactly one transaction exists
   and `InvoicePaid` fires once.
3. MUST: Secrets, certificate keys and passphrases never appear in Inertia props, logs, the gateway log, stored
   payloads or exceptions (the shared contract test greps every configured secret).
4. MUST: Sandbox mode follows `SandboxPolicy`: never activatable, offered or chargeable in production.
5. MUST: Provider hosts come from config per mode through `Endpoints::for()`, never from settings or responses.
6. MUST: Each driver passes the shared `HostedPaymentDriver` contract dataset and runs against its simulator in the
   `Simulators` suite.
7. SHOULD: The admin form shows the return URL to register for every hosted gateway, with a copy button.

**connectIPS**

8. MUST: Given an unpaid invoice of Rs 1,130.00, the form posts `TXNAMT=113000`, `TXNCRNCY=NPR`, `TXNDATE` in Nepal
   time as `DD-MM-YYYY`, a 20-character `TXNID` stored as the attempt's provider reference, and a `TOKEN` that
   verifies with the merchant's public certificate over the exact canonical string.
9. MUST: Given a return with only `TXNID`, the attempt is found by gateway and provider reference and settled by
   validatetxn; a `TXNID` we never issued shows the "couldn't match" message.
10. MUST: validatetxn `SUCCESS` with matching fields → paid; `FAILED` → failed; "NOT FOUND" → expired after TTL plus
    grace; "INCOMPLETE" → pending, then expired; a reply for another transaction or amount → staff review.
11. MUST: gettxndetail's `chargeAmt` is recorded as the fee only when `chargeLiability = MN`; a `creditStatus`
    other than `000`, `999` or `DEFER` sends the attempt to staff review.
12. MUST: Staff upload the `.pfx` with its passphrase; only the encrypted PEM key and the certificate metadata are
    kept; a wrong passphrase or a file without a private key is refused with a plain message.
13. MUST: The certificate's expiry is shown on the gateway form; `GatewayCredentialExpiring` is raised 30, 7 and 1
    days before, and the health check is amber within 30 days and red after expiry.
14. MUST: The driver declares no refund capability, and the admin refund dialog says to refund by bank transfer and
    record it.
15. SHOULD: "Test connection" reports wrong credentials (401) and accepted credentials, and moves no money.

**Cards (Cybersource Secure Acceptance)**

16. MUST: The form's signature is base64 HMAC-SHA256 over the fields named in `signed_field_names`, in that order;
    a return whose signature fails, or that signs an unknown field or omits a required one, is never a valid hint.
17. MUST: Settlement comes only from the REST lookup (REST keys are required; a signed notice never settles), with
    amount, currency and reference checked; DECLINE → failed, CANCEL → cancelled, REVIEW → processing and then staff review.
18. MUST: No card number or CVV is ever posted by us; the masked card number and expiry that Cybersource echoes back on
    the return and notice are never stored or logged.
19. MUST: Staff can refund in full or in part through the REST API, and the gateway cannot be activated without REST keys (the form says why).

The simulator models the REST transaction search, refund and HTTP Signature calls from public docs, some read only in search summaries. Criteria 17 and 19 must be re-verified against a real Cybersource evaluation account (§15) before go-live.

**Delivery (owner decisions, 2026-10-25)**

20. MUST: connectIPS and the Cybersource driver each ship as an extension/driver module and pass their conformance
    tests against our simulator, with no NCHL or Cybersource account needed to build or test.
21. MUST: NepalPay QR is not built.

**Fonepay**

22. MUST: Not built until the bank's spec is in hand; then the request and return `DV` (HMAC-SHA512) are tested with
    our own fixed vectors, compared in constant time and case-insensitively.
23. SHOULD: With the QR product, the checkout page shows the QR and its expiry, and a payment made after the QR
    expired but within TTL plus grace is still booked by the poller.

**Razorpay**

24. MUST: Amounts are sent in paise; the webhook signature is checked over the raw body with the webhook secret;
    duplicate event ids are processed once; every webhook leads to a lookup, never to settlement from the body.
25. MUST: Full and partial refunds go through the API and their webhooks don't book them twice.
26. SHOULD: A smoke command runs a test-mode payment and refund against a real Razorpay test account, refusing live
    keys, with the key read only from the environment or a hidden prompt.

## 14. Sources (read 2026-10-20; key and test-mode facts re-read 2026-10-25)

"Fetched" means the page was read. "Search only" means the fact came from a search-result summary and still needs
reading at the source before a build relies on it. "Verified (doc, 2026-10-25)" means the exact official page below was
fetched on that date and states the fact; the list is in the block added at the end of this section.

**connectIPS and NCHL (fetched)**

- Merchant interface: https://doc.connectips.com/docs/connectIPS-Gateway/merchant-interface
- Payment validation: https://doc.connectips.com/docs/connectIPS-Gateway/payment-validation
- Transaction details: https://doc.connectips.com/docs/connectIPS-Gateway/txn_details
- Response list: https://doc.connectips.com/docs/connectIPS-Gateway/validateTxn-getTxnDetail
- Exceptions: https://doc.connectips.com/docs/connectIPS-Gateway/exception
- Introduction and core module: https://doc.connectips.com/docs/connectIPS-Gateway/introduction ,
  https://doc.connectips.com/docs/connectIPS-Gateway/cips-core-module
- FAQ (limits, reversals, settlement): https://connectips.com/faq
- NCHL consolidated fee schedule, effective 30 May 2025:
  https://nchl.com.np/wp-content/uploads/2025/06/NCHL-consolidated-fee-and-charge_Updated_30-May-2025.pdf
- NepalPay QR: https://doc.connectips.com/docs/NepalPAY-QR/Merchant-QR-generation/introduction ,
  https://doc.connectips.com/docs/NepalPAY-QR/NEPALPAY%20API%20Specification/introduction ,
  https://doc.connectips.com/docs/NepalPAY-QR/NEPALPAY%20API%20Specification/API%20Authentication%20and%20Authorization ,
  https://doc.connectips.com/docs/NepalPAY-QR/NEPALPAY-QR-Websocket/api_specification ,
  https://doc.connectips.com/docs/NepalPAY-QR/Transaction-Report/transaction_report ,
  https://doc.connectips.com/docs/NepalPAY-QR/QR-Merchant-Bulk-Enrollment/payment_confirmation_api
- Community connectIPS references (unofficial): https://github.com/mukezhz/pay-np ,
  https://is.wordpress.org/plugins/pay-with-connectips/

**Fonepay (no official public developer docs; all community or press)**

- https://pub.dev/documentation/fonepay_flutter/latest/ (fetched)
- https://packagist.org/packages/mantraideas/laravel-fonepay (fetched)
- https://fonepay.com/blogs/fonepay-dynamic-qr-code (fetched)
- https://blanxer.com/resources/fonepay-billing-api-integration (fetched)
- https://blog.blanxer.com/introducing-blanxer-payment-service-seamless-online-payments-for-your-business.md (fetched)
- https://wordpress.org/plugins/?p=358946 and https://fair.pm/packages/plugins/webknit-payment-gateway-for-fonepay/ (fetched)
- https://www.sharesansar.com/newsdetail/fonepay-launches-checkout-by-fonepay-for-faster-online-payments-2026-07-08 (fetched)
- https://www.fonepay.com/ and https://github.com/pracharya2601/NPP (fetched)

**Cards in Nepal**

- NIC Asia Cybersource gateway: https://www.nicasiabank.com/nic-asia-payment-gateway-cybersource/ (fetched),
  https://helpdesk.nicasiabank.com/support/solutions/articles/35000164571-cybersource-internet-payment-gateway-ipg- (fetched),
  https://fair.pm/packages/plugins/nepal-payments-for-woocommerce/ (fetched),
  https://www.sharesansar.com/newsdetail/nic-asia-becomes-first-nepali-bank-to-accept-apple-pay-and-google-pay-payments-2026-10-02 (fetched)
- Himalayan Bank: https://himalayanbank.com/en/eecom/165-hbl-introduces-enhanced-e-commerce-payment-gateway (fetched),
  https://docs.wptravelengine.com/payment/himalayan-bank-payment-gateway (fetched), https://devzone.2c2p.com/docs/integration-1 (search only)
- Nabil: https://www.fintechfutures.com/?p=15102721 , https://fair.pm/packages/plugins/nepal-payments-hub-nabil/ (search only)
- NCHL-hosted bank gateways: https://www.sharesansar.com/newsdetail/global-ime-bank-launches-secure-global-payment-gateway-to-boost-ecommerce-in-nepal-2025-05-06 ,
  https://www.sharesansar.com/newsdetail/siddhartha-bank-launches-online-payment-gateway-to-empower-nepali-businesses-2025-05-14 ,
  https://www.sharesansar.com/newsdetail/nmb-bank-launches-secure-online-payment-gateway-to-boost-e-commerce-in-nepal-2025-12-01 ,
  https://www.sharesansar.com/newsdetail/nchl-adds-7-new-members-to-eft-card-switch-2026-09-29 (all fetched)
- Cybersource Secure Acceptance: https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-payment-txns.html ,
  https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-wm-api-fields.html ,
  https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-samples-scripting-languages.html ,
  https://developer.cybersource.com/hello-world/sandbox.html (fetched); REST refunds and transaction search (search only)
- Mastercard Gateway testing pages (search only): https://developer.mastercard.com/mastercard-gateway/documentation/testing/go-live-cl/hosted-checkout/
- Market guides (unverified figures): https://payatlas.com/countries/nepal-np , https://www.websnp.com/help/esewa-khalti-payment-integration-website (fetched)
- Hosts' payment pages: https://www.nestnepal.com/ , https://www.websnp.com/blog/how-to-buy-web-hosting-in-nepal-step-by-step-guide (fetched),
  https://domainnepal.com/hosting/ (search only)
- Stripe countries: https://stripe.com/global (fetched)

**Added 2026-10-25 (fetched or searched on that date)**

- NepalPay QR availability and merchant integration (search results only, no merchant-facing integration found):
  https://nchl.com.np/nepalpay-qr/ ,
  https://doc.connectips.com/docs/NepalPAY-QR/QR-Merchant-Bulk-Enrollment/payment_confirmation_api ,
  https://doc.connectips.com/docs/NepalPAY-QR/QR-Merchant-Bulk-Enrollment/api_for_bulk_merchant_upload
- Web-platform gateway coverage (search only; third-party): https://pypi.org/project/valoris-payment-gateway/ ,
  https://thulo.com/integrations , https://packagist.org/packages/mantraideas/laravel-fonepay
- Cybersource sandbox signup (fetched): https://developer.cybersource.com/hello-world/sandbox.html ; REST key creation
  (search only): https://developer.cybersource.com/docs/cybs/en-us/security-keys/user/all/ada/security-keys/keys-rest-create.html
- Razorpay test mode and key prefixes (search only): https://razorpay.com/docs/payments/quickstart.md
- Cashfree sandbox keys (search only): https://www.cashfree.com/docs/payments/quickstart-guide ,
  https://www.cashfree.com/docs/api-reference/authentication.md

**Verified (doc, 2026-10-25): keys, test mode and hosts.** Each page was fetched and states the fact in the §15 rows
marked verified.

- Cybersource: https://developer.cybersource.com/hello-world/sandbox.html (sandbox signup),
  https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-payment-txns.html (`/pay`
  hosts, required fields), https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-wm-api-fields.html
  (`signed_field_names`, `access_key`). Fetches of the REST key and REST authentication pages returned 404.
- Razorpay: https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/ (key generation),
  https://razorpay.com/docs/webhooks/validate-test/ (signature header), https://razorpay.com/docs/webhooks/setup-edit-payments/
  (webhook secret), https://razorpay.com/docs/payments/payments/test-card-upi-details/ (test cards),
  https://razorpay.com/docs/payments/payments/test-upi-details/ (test UPI ids)
- Cashfree: https://www.cashfree.com/docs/api-reference/authentication (headers, sandbox host, key location),
  https://www.cashfree.com/docs/payments/online/resources/sandbox-environment (test data only)
- connectIPS: https://doc.connectips.com/docs/connectIPS-Gateway/merchant-interface (merchant id, app id, app name,
  certificate), https://doc.connectips.com/docs/connectIPS-Gateway/payment-validation (Basic auth)
- Fonepay: https://www.fonepay.com/ (no developer documentation or sandbox linked); merchant enrolment page
  https://fonepay.com/business/merchant-enrollment and https://fonepay.com/contact-us (not fetched)

**Aggregators and wallets**

- NPS: https://nepalpayment.com/ (fetched, no content), https://www-0.nuget.org/packages/onepg.gateway.library (fetched),
  https://www.sharesansar.com/index.php/newsdetail/nepal-payment-solution-collaborates-with-nchl-digital-payment-ecosystem-of-nepal-to-receive-greater-boost-after-this-synergy-2020-10-20 (search only)
- Khalti payers and onboarding: https://docs.khalti.com/ , https://docs.khalti.com/khalti-epayment/ , https://khalti.com/payment-gateway/ (fetched)
- IME Pay and Khalti merger: https://thehimalayantimes.com/business/ime-pay-khalti-merger-gets-final-approval-from-nepal-rastra-bank (fetched)
- QR interoperability: https://www.gadgetbytenepal.com/business-qr-in-nepal/ , https://www.nepalitelecom.com/nepalpay-qr (fetched);
  https://www.sharesansar.com/index.php/newsdetail/fonepay-welcomes-namaste-pay-in-its-interoperable-payment-ecosystem-2023-06-29 (search only)
- Wallet community packages (search only): https://packagist.org/packages/sarojsardar/hamropay ,
  https://pub.dev/documentation/hamropay_flutter/latest/ , https://pub.dev/packages/cellpay_sdk ,
  https://fastly.jsdelivr.net/wp/plugins/wc-prabhu-pay/trunk/readme.txt

**India (fetched unless marked)**

- Razorpay: https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps/ ,
  https://razorpay.com/docs/api/orders/ , https://razorpay.com/docs/payments/payment-links/ , https://razorpay.com/docs/webhooks/ ,
  https://razorpay.com/docs/webhooks/validate-test/ , https://razorpay.com/docs/webhooks/best-practices/ ,
  https://razorpay.com/docs/webhooks/payments/ , https://razorpay.com/docs/webhooks/refunds/ ,
  https://razorpay.com/docs/api/refunds/create-normal/ , https://razorpay.com/docs/api/settlements/ ,
  https://razorpay.com/docs/api/settlements/fetch-recon/ , https://razorpay.com/docs/payments/quickstart/ ,
  https://razorpay.com/docs/payments/payments/test-card-upi-details/ , https://razorpay.com/docs/payments/recurring-payments/ ,
  https://razorpay.com/docs/payments/international-payments/ , https://razorpay.com/docs/announcements/upi-collect-migration/s2s-integration ,
  https://razorpay.com/docs/payments/payment-gateway/ecommerce-plugins/whmcs/ , https://razorpay.com/pricing/
- Cashfree: https://www.cashfree.com/docs/api-reference/payments/latest/orders/create ,
  https://www.cashfree.com/docs/payments/online/web/redirect , https://www.cashfree.com/docs/payments/online/webhooks/signature-verification ,
  https://www.cashfree.com/docs/payments/online/webhooks/overview , https://www.cashfree.com/docs/api-reference/payments/latest/refunds/create ,
  https://www.cashfree.com/docs/api-reference/payments/latest/settlements/settlement-reconciliation ,
  https://www.cashfree.com/docs/payments/online/resources/sandbox-environment , https://www.cashfree.com/docs/help/onboarding-related/onboarding-faqs ,
  https://www.cashfree.com/docs/payments/manage/payment-methods/upi-collect , https://www.cashfree.com/docs/plugins/whmcs ,
  https://www.cashfree.com/payment-gateway-charges/
- PayU: https://docs.payu.in/docs/generate-hash-payu-hosted , https://docs.payu.in/reference/verify_payment_api ,
  https://docs.payu.in/reference/refund_transaction_api , https://docs.payu.in/docs/webhook-events-and-sample-payloads.md ,
  https://docs.payu.in/docs/generate-test-merchant-key-and-salt.md , https://payu.in/pricing/
- PhonePe: https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/api-reference/authorization ,
  …/create-payment , …/order-status , …/refund , …/webhook ,
  https://developer.phonepe.com/payment-gateway/uat-testing-go-live/uat-sandbox ; pricing (search only)
- RBI e-mandate framework (secondary summary; the RBI PDF was not readable): https://ksandk.com/newsletter/rbi-e-mandate-framework/ ;
  card-on-file tokenisation and the payment aggregator direction (search only)
- WHMCS Marketplace listings: https://marketplace.whmcs.com/search?search=razorpay , …=cashfree , …=payu , …=phonepe

## 15. To do later: set up real test accounts

Nothing here blocks building. Each item turns a simulator-verified driver into one proven against the real service.
Only a human with a company identity can register; we never register on the owner's behalf. Keep keys in the gateway
form's write-only fields or the environment, never in the repo. "Verified" below means "verified (doc, 2026-10-25)"
against the official page in §14; "to verify" means the official pages we could fetch were silent or returned 404, so
the simulator must not treat it as fact. Provider facts are in our own words.

### 15.1 What each account gives (for the simulators)

**Cybersource (Secure Acceptance)**

- Verified: sandbox signup asks for an organisation id (6 to 25 characters; the page suggests a `_sandbox`/`_test`
  variant of the production id), company and contact details and a reCAPTCHA, then emails an invitation to create a
  user on the Test Enterprise Business Center.
- Verified: the Secure Acceptance form posts to `https://testsecureacceptance.cybersource.com/pay` (test),
  `https://secureacceptance.cybersource.com/pay` (live) or `https://secureacceptance.in.cybersource.com/pay` (live,
  India). Required on every request: `access_key`, `amount`, `currency`, `locale`, `profile_id`, `reference_number`,
  `signed_date_time`, `signed_field_names`, `transaction_type`, `transaction_uuid`. Sign every request field except
  `card_number`, `card_cvn` and `signature`.
- To verify: how the Secure Acceptance profile, its `access_key` and its secret key are created in the Business Center,
  and their formats; `unsigned_field_names` (not on the pages fetched); REST shared-secret key id and secret versus
  P12/JWT, where they are created (the Payment Configuration, Key Management path is from search results only),
  expiry, and the REST sandbox host. The REST key and authentication pages returned 404.
- To verify for the built driver (#492): whether `locale=en` is accepted (the documented examples use `en-us`); the
  full set of field names a real profile signs on the return and notice (the driver refuses names outside its list
  and logs them, e.g. `payment_token`); how long Transaction Search takes to index a new payment and a refund; the
  real transaction statuses, including `authorizedAmount` on a partial authorisation and the void and reversal
  statuses; and the refund response statuses.

**Razorpay**

- Verified: switch the dashboard to Test mode, then Account & Settings, API Keys, Generate Key. A key is a `key_id` and
  `key_secret` pair; test keys need no website; the secret is shown once and must be saved; only Owner and Admin roles
  see the page.
- To verify: the `rzp_test_` prefix and whether test mode needs KYC (the doc pages say nothing; a search result did).
- Verified: the webhook signature is in the `X-Razorpay-Signature` header, an HMAC-SHA256 of the raw body keyed with the
  webhook secret. The secret is typed into the Secret field when adding a webhook (Accounts & Settings, Webhooks); it
  is optional, recommended, and separate from the API key secret. Test-mode webhook edits ask for an OTP; the doc gives
  `754081`.
- Verified test data: success cards include Visa 4100 2800 0000 1007 and Mastercard 5555 5100 0008 1006 (any CVV, future
  expiry, a 4 to 10 digit OTP on the mock bank page); documented failure cards per error reason (for example Visa
  4100 2800 0008 0001 for insufficient funds); UPI `success@razorpay` and `failure@razorpay`. Cancelling in test mode
  still gives a successful payment.

**Cashfree**

- Verified: calls send `x-client-id`, `x-client-secret` and `x-api-version` (examples use `2025-01-01`); sandbox base
  `https://sandbox.cashfree.com/pg/`; keys are under Developers, API Keys, with a Switch to Test control; Payment
  Gateway test keys are created automatically; each product (Payments, Payouts) has its own pair.
- To verify: the `TEST`/`PROD` key prefixes (the authentication page does not mention them) and the production base URL.

**connectIPS (NCHL)**

- Verified: NCHL issues a numeric merchant id; a merchant may hold several app ids (each tied to one bank account or
  site), an app id example in the docs looks like `MER-550-APP-1`; the app name is a label up to 30 characters; the
  signing key is a `.pfx` supplied by NCHL (the test one is `CREDITOR.pfx`); the validation call uses HTTP Basic auth
  with the app id as user and an emailed password; success and failure URLs are registered in advance with the
  integration support team and localhost is allowed in test. No host names appear on these pages.
- To verify: the UAT and live hosts, the `.pfx` passphrase and its validity period, the token string spacing and the
  validation token algorithm (Q2, Q3).

**Fonepay**

- Verified: fonepay.com links no developer documentation or sandbox; merchants are enrolled through its business
  pages and the contact page. Everything in §5.1 and §5.2 stays community-sourced and unverified.

### 15.2 Registration to-do

| Account                | Where to register                                                                                                    | What to ask                                                                                                            | Keys that result                                                                                       | Then                                                                          |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| connectIPS UAT (NCHL)  | Email NCHL's connectIPS technical support (contact on doc.connectips.com), or through the owner's bank once enrolled | A UAT creditor account; the UAT and live hosts; the exact token string and validation algorithm; localhost return URLs | Merchant id, app id, app name, app password, a test `.pfx` and passphrase                              | Run the driver against UAT; align the simulator; tag recordings `source: uat` |
| Cybersource evaluation | https://developer.cybersource.com/hello-world/sandbox.html                                                           | Later, ask NIC Asia whether the live profile includes REST keys (Q9), USD, fees                                        | A Test Business Center, where we create a Secure Acceptance profile and a REST key (formats to verify) | Run the driver and REST refunds against it; re-verify criteria 17 and 19      |
| Razorpay test          | Razorpay dashboard sign-up, Test mode                                                                                | Nothing; live later needs an Indian entity                                                                             | `key_id` and `key_secret`, plus a webhook secret we choose                                             | Run the R1 smoke command                                                      |
| Cashfree test          | Cashfree merchant dashboard, Switch to Test                                                                          | Nothing; live needs an Indian entity                                                                                   | Sandbox client id and secret for Payments                                                              | After Razorpay, same checks for CF1                                           |
| Fonepay partner        | A Fonepay partner bank                                                                                               | The web and Dynamic QR specs; dev host; whether verification needs `UID` or works by `PRN` (Q5); fees; refunds         | Dev merchant code and secret (web) or username, password, terminal id and RSA keys (QR)                | Only then build F1 and F2                                                     |

Also for later: NIC Asia (REST keys, USD, fees, settlement time) and the owner's own bank for connectIPS enrolment.
