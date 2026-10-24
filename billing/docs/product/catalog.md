# Catalog — Product Spec (v1)

Status: draft for engineering · Owner: Catalog workstream · Refines FEATURE_MAP §1
Clean-room: behaviour derived from public category docs (WHMCS, Blesta, FOSSBilling); naming/schemas are ours.

## 1. Purpose & scope

The Catalog defines _what can be sold and at what price_. It owns product groups, products, prices
(currency × billing cycle), setup fees, configurable options, product custom fields, product addons,
upgrade paths, stock, provisioning binding and auto-setup policy. It does **not** own client-owned
instances (Services module) or the cart (Orders). Other modules read it only through `PriceBook`
(`app/Contracts/Catalog/PriceBook.php`).

## 2. Entities & key fields

### product_groups

`id, name, slug (unique, ≥1 letter), headline, tagline, description, sort_order, is_hidden, allowed_gateways (json|null = all), order_form_template (nullable), timestamps`

- Hidden groups are not listed in the store but remain reachable by direct slug link. **MUST**
- Lowest `sort_order` visible group is the default store landing group. **SHOULD**
- `allowed_gateways`: when a cart/invoice mixes groups, only the **intersection** of allowed gateways is offered. Admin save MUST warn if two visible groups share no gateway. **MUST**

### products

`id, product_group_id, type (shared_hosting|reseller_hosting|server|other), name, slug, tagline, short_description, description, sort_order,
payment_type (free|one_time|recurring), is_hidden, is_retired, is_featured, is_taxable (default false — see pitfalls), requires_domain,
stock_enabled, stock_quantity, quantity_mode (single|multiple_services|scaling), recurring_cycles_limit (0 = unlimited),
fixed_term_days (0 = off), fixed_term_email_key, welcome_email_key, upgrade_email_key, auto_setup (on_order|on_payment|on_accept|manual),
provisioning_driver (string, 'none' default), server_group_id (nullable), driver_config (json), prorata_enabled, prorata_day (1–31),
prorata_charge_next_month_day (0–32), free_domain (none|once|recurring), free_domain_cycles (json), free_domain_tlds (json),
suspend_days_override (nullable int), terminate_days_override (nullable int), timestamps, soft deletes`

- Hidden: not listed, still orderable via direct link. Retired: not orderable anywhere, hidden from admin "new order" pickers, existing services unaffected. **MUST**
- `type=server` makes the cart collect hostname, root password and NS prefixes. **SHOULD**
- `payment_type=free` → services get no next due date, never invoice, no client cancellation request link. **MUST**
- `quantity_mode`: `single` (qty fixed 1); `multiple_services` (qty N → N services, each priced at unit price);
  `scaling` (one service with `quantity=N`, recurring = unit × N). Products with configurable options or required custom fields cannot use `multiple_services`. **MUST** (single, multiple_services) / **SHOULD** (scaling)
- `recurring_cycles_limit=N`: after N renewal invoices have been generated, the service becomes `completed` and stops invoicing (Services). **SHOULD**
- `fixed_term_days`: terminate service N days after registration (trials). **SHOULD**

### prices (polymorphic, one table)

`id, priceable_type (product|addon|config_option_value), priceable_id, currency (char3), cycle (BillingCycle enum), enabled (bool), amount (int minor), setup_fee (int minor)`

- Unique `(priceable_type, priceable_id, currency, cycle)`. **MUST**
- `one_time` products/addons use the `onetime` cycle row only. Config-option values use the **same cycle** as their parent product cycle. **MUST**
- Quantity-type option values store a **unit** price (multiplied by chosen quantity). **MUST**
- Missing/disabled row for the client's currency ⇒ cycle not available in that currency (no silent conversion at sale time). **MUST**
- `catalog.auto_convert_prices: false` — COULD: a daily job regenerates non-default-currency rows from the default currency × exchange rate.

### config_option_groups / config_options / config_option_values

- `config_option_groups: id, name, description (admin only)`; pivot `config_option_group_product`.
- `config_options: id, group_id, name (label), module_key (value passed to driver), type (dropdown|radio|yes_no|quantity), quantity_min, quantity_max, quantity_step, is_hidden, sort_order, is_required`
- `config_option_values: id, config_option_id, label, module_value, is_hidden, sort_order` (+ prices rows)
- We store machine value and display label in separate columns instead of a `value|label` string. **MUST**
- `yes_no`: one value row; unchecked = 0 charge. `quantity`: one value row; min/max both set or both null (slider when both set). **MUST**
- Hidden option/value: not offered for new orders/upgrades, but existing selections keep pricing and display. **MUST**

### product_custom_fields

`id, product_id, name, module_key, type (text|link|password|dropdown|checkbox|textarea), options (json for dropdown), description, validation_regex, is_required, is_admin_only, show_on_order, show_on_invoice, sort_order`

- Password values encrypted at rest (Services stores values). Regex validated server-side with a timeout-safe matcher. **MUST**
- Client may set values at order time; afterwards they are read-only for the client, editable by staff. **MUST**
- `show_on_invoice` appends `Label: value` to the service line description. **SHOULD**

### addons (product addons)

`id, name, description, kind (independent|feature), payment_type, is_taxable, show_on_order, is_hidden, is_retired, suspend_parent_on_overdue,
welcome_email_key, weight, allow_quantity, prorata_with_parent, auto_setup, provisioning_driver, driver_config (json), timestamps`
pivot `addon_product` (applicable products).

- Billing cycle choice on purchase: if the addon has a price for the parent's cycle, use it; otherwise use the addon's shortest enabled cycle. **MUST**
- `prorata_with_parent`: first addon period ends on parent's `next_due_date` when cycles match; otherwise anniversary billing. **SHOULD**
- Visibility matrix: `show_on_order=false` hides it in new-order cart but still offered to existing services; `is_hidden` hides everywhere client-facing except direct link; `is_retired` hides for admins too. **MUST**

### product_upgrade_paths

`product_id, target_product_id` (directed; downgrade = a path to a cheaper product) + `products.allow_config_option_upgrades (bool)`. **MUST**

## 3. Business rules & calculations

1. **Money** is integer minor units (`App\Support\Money`); no floats in persistence. Display formatting from currency. **MUST**
2. **Price quote** (`PriceBook::quote`): `recurring = product.amount + Σ option values (unit × qty for quantity type)`; `setup = product.setup_fee + Σ option setup fees`. Client-group discount % (Core) applies to recurring and setup **after** options, rounded half-up per line. **MUST**
3. **Free cycle**: `cycle=free` only valid when `payment_type=free`. **MUST**
4. **Prorata (first period) calculation** — performed by Orders using Catalog settings. **SHOULD**
    - `anchor = next occurrence of prorata_day after order_date` (day clamped to month length; for non-monthly cycles the anchor is the prorata_day of the month after order, then the full cycle is added from the anchor).
    - `partial = round(recurring × days(order_date → anchor) / days(anchor − 1 month → anchor))` (actual calendar days).
    - If `order_date.day > prorata_charge_next_month_day` (0 = always, 32 = never): first invoice = partial + one full cycle, `next_due_date = anchor + cycle`; else first invoice = partial, `next_due_date = anchor`.
    - Setup fee is charged in full, never prorated.
    - Signup-anniversary prorata (anchor = client signup day) is **COULD**.
5. **Stock**: `reserveStock` decrements atomically (`UPDATE … SET stock_quantity = stock_quantity - 1 WHERE stock_quantity > 0`); 0 ⇒ product shows "out of stock" and cannot be added. Stock is restored when the order is cancelled/fraud **before** the service was ever activated. **MUST**
6. **Price changes are not retroactive**: existing services keep their stored `recurring_amount`. Staff can bulk "re-price services of product X" explicitly (SHOULD), with preview and activity log.
7. **Upgrade eligibility**: `canUpgrade(from, to)` true only if a path exists **and** target is not retired **and** target has a price in the service's currency for at least one cycle. **MUST**
8. **Auto-setup policy** (consumed by Orders/Services): `on_order` → provision right after checkout (only if fraud check passed); `on_payment` → on first `InvoicePaid` of the order invoice; `on_accept` → on staff Accept; `manual` → never automatically. **MUST**
    - Global override `orders.auto_provision_only_existing_clients: false` (when true, new clients' items fall back to `on_accept`). **SHOULD**
9. **Welcome email**: sent once when the service first reaches `active` (Services emits; Notifications sends key). Re-send action available to staff. **MUST**
10. **Free domain**: eligible when the chosen cycle ∈ `free_domain_cycles` and TLD ∈ `free_domain_tlds`; registration line priced 0; `once` → renewals at normal price, `recurring` → renewals free while the service is active. **SHOULD**
11. **Deleting** a product with services is forbidden (retire instead). Deleting an option value in use is forbidden (hide instead). **MUST**

Settings (Catalog):

- `catalog.default_auto_setup: on_payment`
- `catalog.default_taxable: true` (new products default taxable; avoid the "forgot to tick tax" pitfall)
- `catalog.auto_convert_prices: false`
- `catalog.show_monthly_breakdown: false` (store shows annual as "/mo equivalent")

## 4. Admin capabilities

- CRUD groups (drag sort), products (tabs: details, pricing grid currency×cycle with enable toggles, module settings with driver-provided config fields + "test" button, custom fields, config option groups, upgrades, free domain, other). **MUST**
- Duplicate product (copies prices, options links, custom fields, upgrade paths). **SHOULD**
- CRUD config option groups/options/values with pricing grids; assign groups to products. **MUST**
- CRUD addons with pricing grid, applicable products, driver binding. **MUST**
- Product list with type, payment type, stock, auto-setup, active services count. **MUST**
- Direct links: product cart link, group link, link with promo code. **SHOULD**
- Bulk price update (percent/fixed across selection) with optional "also re-price existing services". **COULD**

## 5. Client-area / store capabilities

- Browse visible groups/products; product page shows cycles available in visitor's currency, setup fees, monthly breakdown (if enabled), "out of stock" badge. **MUST**
- Configure step renders config options (respecting hidden), custom fields (non admin-only, show_on_order), addons, domain requirement. **MUST**
- Existing service page lists purchasable addons and upgrade targets. **MUST**

## 6. Automation

- `catalog:convert-prices` (daily, only if `auto_convert_prices`) — idempotent overwrite of non-default currency rows. **COULD**
- No other scheduled jobs. Stock is transactional, not scheduled.

## 7. Events emitted

- `ProductCreated`, `ProductUpdated`, `ProductRetired` (id) — for cache invalidation / search index.
- `ProductPriceChanged` (priceable, currency, cycle, old, new), also for every row an automatic or manual price conversion writes or turns off.
- `ProductStockDepleted` (product_id) when stock hits 0 (staff notification).
- `AddonCreated`, `AddonUpdated`.

## 8. Edge cases & pitfalls observed in the category

- Products default to **untaxed** in some products, causing silently untaxed invoices — we default taxable and show a badge in lists.
- Config option pricing must exist for every cycle the product enables; missing rows priced as 0 is a revenue leak. Validation: saving a product cycle without option prices for that cycle/currency shows a blocking warning. **MUST**
- Mixed product groups with no common gateway produce an unpayable cart.
- Hiding vs retiring confusion: retired items must never be re-offered via direct link.
- Quantity × configurable options conflicts (`multiple_services` with per-instance config) — disallow.
- Display-name hacks (`value|label`) leak machine strings into invoices; we keep separate fields.
- Currency rounding when auto-converting (e.g., 9.99 USD → 9.2907 EUR) — round to minor unit, optionally to `.99` (COULD). One rule (`Currency::priceFromDefault()`) covers every converted amount: recurring prices, setup fees, add-ons, option values, TLD prices, domain late-renewal fees, and promotion and minimum-cart amounts.
- Changing a product's provisioning driver never carries the old driver's stored secrets over; a blank secret field is kept only while the driver is unchanged.
- Stock race under concurrent checkouts — atomic decrement only.
- Price edits retroactively changing existing customers' bills is a frequent support complaint — never implicit.

## 9. Acceptance criteria

1. Given a product with EUR monthly 10.00 and annual 100.00 enabled, when `availableCycles(product,'EUR')` is called, then it returns [monthly, annually].
2. Given a product with no USD rows, when a USD client views it, then it is not orderable and no conversion occurs: the store hides it, its page says it is not sold in USD (and, to guests, offers the currencies it is sold in), and add-to-cart is refused with the same reason.
3. Given a product with monthly 10.00 + option value "4GB RAM" monthly 5.00, when quoted monthly, then recurring = 1500 minor units.
4. Given a quantity option with unit 2.00 and qty 3, when quoted, then option contributes 600.
5. Given client group discount 10% and recurring 1500/setup 1000, then quote = 1350/900.
6. Given stock_quantity=1 and two concurrent `reserveStock` calls, then exactly one returns true and stock ends at 0.
7. Given a retired product, when accessed by direct cart link, then add-to-cart is rejected.
8. Given a hidden product, when accessed by direct link, then it can be added to cart; it is absent from group listings.
9. Given a product with active services, when staff tries to delete it, then the action is refused with "retire instead".
10. Given an addon with only annual pricing and a monthly parent, when added to the cart, then the addon cycle is annual.
11. Given an addon with monthly pricing and a monthly parent, then the addon cycle is monthly.
12. Given upgrade path A→B and B retired, then `canUpgrade(A,B)` is false.
13. Given prorata_day=1, charge_next_month_day=20, monthly 31.00, order on Jan 10, then first invoice = 31.00×22/31 = 22.00 and next_due_date = Feb 1.
14. Same config, order on Jan 25, then first invoice = 31.00×7/31 + 31.00 = 38.00 and next_due_date = Mar 1.
15. Given a product price change from 10 to 12, then existing services' recurring_amount stays 10.
16. Given a hidden config option value used by a service, then the service still renews at that value's price.
17. Given a dropdown custom field with regex, when an order submits a non-matching value, then validation fails with field error.
18. Given a password custom field, then its stored value is encrypted (raw DB value ≠ plaintext).
19. Given product groups G1 (gateways A,B) and G2 (B,C) in one cart, then only gateway B is offered.
20. Given `payment_type=free`, then quoting returns 0/0 and cycle `free`.

## 10. Out of scope for v1

Product bundles; cross-sell recommendations; metric/usage-based pricing tiers; on-demand early renewals;
downloadable product files; marketplace/reseller-provided catalogs; per-product affiliate overrides (Affiliates owns later);
signup-anniversary prorata (COULD); custom order form templates per group (single store theme in v1); tiered/volume pricing.
