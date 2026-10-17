# Affiliates — Product Spec (v1)

Status: draft for engineering · Owner: Affiliates workstream · Refines FEATURE_MAP §8
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

Lets clients become affiliates, attributes new orders to them (link/cookie/code), calculates commissions on paid service invoices,
holds them pending for a clearing delay, reverses them on refunds, and pays out balances (account credit or manual payout).
Per-product commission overrides live here (Catalog explicitly defers them). Money in integer minor units of the affiliate's currency.

## 2. Entities & key fields

### affiliates

`id, client_id (unique), code (unique, 8 chars [a-z0-9], regenerable by staff), status (active|disabled), currency (client currency at activation; immutable once a commission exists),
commission_type (null|percent|fixed), commission_value (null|int: basis points for percent, minor units for fixed), payout_mode (null|recurring|one_time),
visitors_count (cached), activated_at, disabled_at, timestamps`

- `null` override fields fall back to product rule, then global settings. **MUST**

### affiliate_product_rules

`id, product_id (unique), commission_type (default|percent|fixed|none), commission_value, payout_mode (default|recurring|one_time)` **MUST**

### affiliate_clicks

`id, affiliate_id, landing_path, referrer_host, ip_hash (sha256 + app key), ua_hash, created_at` — unique visitor counting: same affiliate + ip_hash within 24 h counts once. Retention `affiliates.click_retention_days: 365`. **MUST**

### affiliate_referrals

`id, affiliate_id, referred_client_id, order_id, service_id (unique), source (cookie|link|code|manual), status (active|removed), created_by (staff id|null), timestamps`

- One referral per service; addons/domains never referenced. **MUST**

### affiliate_commissions

`id, affiliate_id, referral_id (nullable for manual), service_id, invoice_id, invoice_item_id (unique when not null), base_amount + base_currency (line net of discount, ex tax),
rate_type (percent|fixed|manual), rate_value, amount (affiliate currency), exchange_rate (snapshot), status (pending|cleared|void|reversed), clears_at, cleared_at,
voided_at / reversed_at, reason, description, created_by, timestamps`

### affiliate_ledger_entries (source of truth for balances)

`id, affiliate_id, type (bonus|commission|reversal|withdrawal_hold|withdrawal_release|withdrawal_paid|adjustment), amount (signed), commission_id|withdrawal_id (nullable), note, created_by, created_at`

- `available_balance = Σ ledger amounts`; `pending_balance = Σ pending commissions`; `withdrawn_total = −Σ withdrawal_paid`. Cached columns on `affiliates` recomputed in the same transaction. **MUST**

### affiliate_withdrawals

`id, affiliate_id, amount, status (requested|paid|rejected|cancelled), payout_method (account_credit|manual_payment|record_only), payout_reference, ticket_id (nullable),
requested_at, processed_at, processed_by, notes, timestamps` **MUST**

## 3. Settings (defaults)

`affiliates.enabled: false` · `affiliates.client_self_signup: true` · `affiliates.default_commission_type: percent` · `affiliates.default_commission_value: 1000` (10.00 % in bp) ·
`affiliates.default_payout_mode: recurring` · `affiliates.clearing_delay_days: 30` (0 = clear immediately) · `affiliates.minimum_payout: 5000` (in default currency, converted) ·
`affiliates.cookie_lifetime_days: 90` · `affiliates.attribution: last_click` (last_click|first_click) · `affiliates.referral_scope: any_order` (any_order|first_order_only) ·
`affiliates.allow_code_at_checkout: true` · `affiliates.bonus_deposit: 0` · `affiliates.include_setup_fee: true` · `affiliates.require_active_service_to_clear: true` ·
`affiliates.payout_methods: [account_credit, manual_payment]` · `affiliates.withdrawal_ticket_department_id: null` · `affiliates.link_snippets: []` (staff-authored HTML, sanitised).

## 4. Status & transitions

- Affiliate: `active ⇄ disabled`. Disabled: no new referrals/commissions, link redirects without cookie, pending commissions still clear, withdrawals blocked until re-enabled. Hard delete not offered (audit). **MUST**
- Commission: `pending → cleared` (clearing job) · `pending → void` (service not active at clearing / refund before clearing / staff void) · `cleared → reversed` (refund/chargeback after clearing). Terminal: void, reversed. **MUST**
- Withdrawal: `requested → paid | rejected | cancelled (by client before processing)`. **MUST**

## 5. Business rules

### 5.1 Activation

- Client activates in client area (if self-signup) or staff activates. Requires client status active. Creates code, ledger `bonus` entry if `bonus_deposit > 0` (converted to affiliate currency). Emits `AffiliateActivated`. **MUST**

### 5.2 Referral tracking

- Link: `/r/{code}` and `?ref={code}` on any public page. Valid active code → record click, set cookie `aff_ref` (signed; payload code + first_seen) for `cookie_lifetime_days`; redirect to `?to=` path only if relative same-host (open-redirect guard), else home. Deep links to product group/product/cart allowed. **MUST**
- `last_click`: a newer valid click overwrites the cookie; `first_click`: existing unexpired cookie wins. **MUST**
- Checkout: code typed at checkout (if allowed) beats cookie. Orders module passes `referralCode` on `OrderPlaced` (existing field). **MUST**
- Referral is created per eligible service line on `OrderPlaced`, unless: affiliate disabled; affiliate.client_id == ordering client; ordering user is a user of the affiliate's client account (self-referral via other account); `first_order_only` and client already has a paid order. **MUST**
- Staff can manually assign/remove an affiliate on an order's services; removal stops future commissions only. **MUST**

### 5.3 Eligibility

- Eligible: product services (base price + configurable options + setup fee when `include_setup_fee`). **MUST**
- Not eligible: domains, product addons, upgrade/downgrade invoices, manual/custom invoice lines, add-funds/credit invoices, late fees, usage/overage lines, tax. **MUST**
- Product rule `none` disables commissions for that product. **MUST**

### 5.4 Rate resolution & calculation

- Rate: affiliate override → product rule → global. Payout mode: `one_time` if **either** affiliate or product says `one_time`, else first non-default value, else global. **MUST**
- Percent: `amount = round_half_up(base_amount × rate_bp / 10000)` in invoice currency, then converted to affiliate currency with the current exchange rate (snapshot stored). Discounts reduce the base (a 100 %-off first month earns 0). **MUST**
- Fixed: `commission_value` defined in the default currency, converted to affiliate currency; not reduced by discounts; at most the line's paid net amount (never pay more than collected) — `affiliates.cap_fixed_at_line_amount: true`. **SHOULD**
- One-time: only the first paid invoice of that service generates a commission. Recurring: every paid renewal invoice of a referred service, using the line amount actually invoiced (follows upgrades; no commission on the upgrade invoice itself). **MUST**
- Zero-amount results create no commission row. **MUST**

### 5.5 Creation, clearing, reversal

- On `InvoicePaid` (fully paid only): for each eligible line whose service has an active referral → create `pending` commission with `clears_at = paid_at + clearing_delay_days`. Idempotent by `invoice_item_id`. Emits `AffiliateCommissionCreated`. **MUST**
- Clearing job (daily + immediately when delay 0): pending with `clears_at ≤ now` → if service status is `active` (or setting off) → `cleared`, ledger `commission`, emit existing `AffiliateCommissionEarned`; else → `void` reason `service_not_active`. **MUST**
- `InvoiceRefunded(fullRefund=true)` or a chargeback (`PaymentReversed`, billing.md): all commissions for that invoice → pending: `void`; cleared: `reversed` with negative ledger entry (balance may go negative). **MUST**
- Partial refund: reverse only when the refund action sets `reverse_affiliate_commission=true` (requires adding this flag to `InvoiceRefunded`); reversal is always the whole commission of the affected line. **MUST**
- Manual commission (staff): amount + description (+ optional referral); goes straight to `cleared` unless staff picks "pending". **MUST**

### 5.6 Withdrawals / payouts

- Client may request when `available_balance ≥ minimum_payout` and no open request exists; amount defaults to the full available balance, may be lower but ≥ minimum. Request writes `withdrawal_hold` (negative) so the amount can't be double-requested. **MUST**
- If `withdrawal_ticket_department_id` set, a support ticket is opened on behalf of the client referencing the request. Emits existing `AffiliateWithdrawalRequested`. **SHOULD**
- Staff processes: `account_credit` → Billing credit ledger add (client currency = affiliate currency), `manual_payment` → Billing expenditure transaction record with reference, `record_only` → no billing effect. Ledger: release hold + `withdrawal_paid`. **MUST**
- Reject/cancel → `withdrawal_release` restoring the balance. **MUST**
- Staff can also create a payout directly (no client request) subject to available balance. **SHOULD**
- Negative balance blocks requests until positive again. **MUST**

## 6. Admin capabilities

Settings page; product rule editor (on product page via Affiliates tab component); affiliate list (visitors, signups, conversion %, pending, available, withdrawn) with filters;
affiliate detail: status, overrides, code regenerate, ledger with manual adjustment (reason required), referrals (add/remove/manual commission for a paid invoice),
commissions (filter by status; void/clear early with reason), withdrawals queue (approve with method, reject); activity log entries for every money action. **MUST**

## 7. Client-area capabilities (client permission `affiliates`, owner by default)

Join page (signup copy `affiliates.signup_content`); dashboard: referral link + code (copy), link snippets, stats (visitors, signups, conversion), pending/available/withdrawn,
commission list (date, product name, amount, status — no referred client identity), withdrawal request & history. **MUST**

## 8. Automation

- `affiliates:clear-commissions` daily — §5.5; row-locked, idempotent. **MUST**
- `affiliates:prune-clicks` daily. **SHOULD**
- `affiliates:monthly-summary` 1st of month (if `affiliates.monthly_summary: false` enabled). **COULD**

## 9. Events emitted

Existing: `AffiliateCommissionEarned` (on clear), `AffiliateWithdrawalRequested`.
New: `AffiliateActivated`, `AffiliateDisabled`, `AffiliateReferralRecorded(serviceId, source)`, `AffiliateCommissionCreated`, `AffiliateCommissionVoided(reason)`,
`AffiliateCommissionReversed`, `AffiliateWithdrawalProcessed(method)`, `AffiliateWithdrawalRejected`, `AffiliateBalanceAdjusted`.

## 10. Edge cases & pitfalls

- Self-referral through a second client account owned by the same login — check users, not just client ids.
- Refund after the affiliate already withdrew → negative balance (recover from future commissions, don't claw back automatically).
- Promo codes: percent commissions shrink with discounts; fixed don't (capped at collected amount to avoid paying more than received).
- Multi-currency: commission currency = affiliate currency; conversions snapshot the rate used.
- Service moved to another client or merged clients — referral stays tied to the service; commission continues unless staff removes it.
- Cookie lost (other device/browser) → staff manual assignment is the remedy; code at checkout reduces misses.
- Commission on an invoice paid by account credit that came from an affiliate payout — allowed (it's a real sale), but self-referral rules still apply.
- Deleting affiliate history destroys audit trail — disable instead.
- Open redirect via `?to=` on the referral link — only relative paths.

## 11. Acceptance criteria

1. Given affiliates disabled globally, When `/r/{code}` is visited, Then no cookie is set and the visitor is redirected home.
2. Given a valid code, When visited twice from the same IP within 24 h, Then visitors_count increases by 1.
3. Given `last_click` and cookies from A then B, When the visitor orders, Then the referral belongs to B; with `first_click`, to A.
4. Given a checkout code for C and cookie for B, Then the referral belongs to C.
5. Given the affiliate orders through their own link (same client or a user linked to their account), Then no referral is created.
6. Given an order with a product, an addon and a domain, Then exactly one referral (the product service) is created.
7. Given default 10 % and a paid first invoice line of 20.00 + setup 5.00, Then a pending commission of 2.50 is created with clears_at = paid_at + 30 days.
8. Given product rule fixed 15.00 and affiliate override percent 20 %, Then the affiliate override (20 %) applies.
9. Given product rule `one_time`, When the renewal invoice is paid, Then no commission is created.
10. Given an `InvoicePaid` replay, Then no duplicate commission exists for the same invoice item.
11. Given a pending commission whose service is suspended at clears_at, When clearing runs, Then it becomes `void` and the balance is unchanged.
12. Given a cleared commission of 2.50, When the invoice is fully refunded, Then status = reversed and available balance decreases by 2.50.
13. Given a partial refund without `reverse_affiliate_commission`, Then commissions are unchanged.
14. Given available 40.00 and minimum 50.00, Then the client sees no request button and the API returns 422.
15. Given available 60.00, When the client requests 60.00, Then available = 0.00 and a second request is rejected.
16. Given staff pays a 60.00 request as account credit, Then the client credit balance increases by 60.00 and withdrawn_total = 60.00.
17. Given staff rejects the request, Then available returns to 60.00.
18. Given a `?to=https://evil.example` parameter, Then the redirect target is the home page.
19. Given an affiliate whose client currency is EUR and a USD invoice, Then the commission is stored in EUR with the exchange-rate snapshot.

## 12. Out of scope for v1

Multi-tier/MLM commissions, automated payouts (PayPal/bank APIs), affiliate-specific promo codes, banner asset hosting, tax forms/KYC for affiliates,
click-fraud detection, commissions on domains/addons, per-affiliate landing pages, affiliate API.
