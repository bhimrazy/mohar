# ADR 0008 — Integration contracts v2

- Status: Accepted · 2026-10-17 · Owner: CTO

## Context

With all wave-1 modules built against fakes, reviews surfaced the seams the v1 contracts missed: client-group
pricing unreachable from Orders, no stock release, Services unable to list provisioning drivers, Billing unable
to ask whether an order is fraud-flagged, Support unable to list a client's services, and module-local events
other modules (Notifications) must react to.

## Decision (all backward compatible — new interfaces have Core null defaults; DTOs only gain trailing defaulted fields)

- `ProductSelection::$clientId` — PriceBook applies the client's group discount. `PriceBook::quote()` returns null
  (never throws) for invalid selections. New `PriceBook::releaseStock()`, `productBySlug()`.
- `ProductSummary` gains payment type, prorata, free-domain, suspend/terminate overrides, cycle limit, term,
  slug and allowed gateways. The prorata calculator moves to `App\Support\Billing\Prorata` (pure, shared).
- `ServiceDraft` custom fields are keyed by field id; adds `promotionCyclesRemaining` and `renewalDiscount`
  (Orders computes, Services applies on renewal). `ServiceActivated::$nextDueDate`.
- `DomainPricing::price(..., ?int $clientId)` (group tiers) and `terms()`.
- New contracts: `Services\ProvisioningDrivers`, `Services\ClientServiceDirectory`, `Orders\PaymentGuard`
  (Billing must consult it before accepting payment), `Billing\GatewayDirectory`, `Billing\InvoiceQueries`.
- Module events that another module consumes are promoted to `app/Events/<Area>` (pure moves) in each PR.
- Declined: Invoicer line removal — renewal invoices are one billable item each, so cancelling an invoice is
  equivalent and keeps invoices immutable after issue.
- Event-order robustness: listeners must not assume `OrderPlaced` precedes `OrderMarkedFraud`; keep
  per-order state idempotent and order-independent.

## Consequences

Module fakes implementing `PriceBook` / `DomainPricing` must add the new methods when merging develop. Each
owning module implements and binds the real contract in its PR fix round.

## Addendum (2026-10-17, from Catalog review)

- `ProductSummary::$termMonths` → `$termDays` (the spec stores fixed terms in days); `$freeDomainRecurring` added.
- Stock release on `OrderCancelled` / `OrderMarkedFraud` is owned by Catalog's listener (idempotent per order);
  Orders must not call `PriceBook::releaseStock()` for those events — it is for other callers (e.g. manual order edits).
- Provisioning driver configuration (incl. fields drivers mark `secret`) is encrypted at rest by its owner.

## Amendment (#607) — 2026-11-01, the first-period calculation moves to Orders

- Supersedes "The prorata calculator moves to `App\Support\Billing\Prorata` (pure, shared)" above. Orders is the
  only caller (catalog.md §3.4: "performed by Orders"), so the calculator lives in `App\Modules\Orders\Pricing\Prorata`
  and the kernel class is removed. Change-plan proration stays in `Services/Support/Proration` (services.md §5).
- Orders computes each new service's and addon's first period at checkout (amount, invoice period, anchor day, next
  invoice date) and hands Services the result through `ServiceDraft` / `AddonDraft`: `nextDueDate` is the first
  period's end and `billingAnchorDay` the renewal day. Services does not read prorata settings.
- A longer cycle's partial divides by that cycle's length in days (docs/reviews/2026-11-01-prorata.md, decision 2).

