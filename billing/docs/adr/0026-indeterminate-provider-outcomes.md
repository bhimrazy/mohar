# ADR 0026 — Indeterminate provider outcomes

- Status: Proposed · 2026-10-23 · Owner: Principal architect. From the architecture review of 2026-10-23
  (`docs/reviews/2026-10-23-architecture.md`, H5, D5).
- Related: ADR 0009 §4 (`DriverOutcome`), ADR 0012 (capabilities), ADR 0018 §4 (step-up), ADR 0021 (row locks), ADR
  0024 §5 (refund reservations); PRs #336, #362; issues #147, #217, #323.

## Context

Each driver family answers "it may have happened" in its own way:

- **Provisioning and registrars** use `DriverOutcome::Unknown` and `ReconcileStatus`. Services opens a verify queue
  item, and Domains reconciles submissions.
- **Payments** use `LookupResult::AMBIGUOUS`, `PROCESSING` and `NOT_FOUND`, and the attempt status `Ambiguous`.
  The evidence is `ReturnHint::$signedPaid` (#362).
- **Refunds** use `ChargeResult::ERROR` and `PENDING`, which keep the refund pending (#336), and
  `LooksUpRefunds::lookupRefund()` with `RefundRequest::$sentAt`.

All four families arrived at the same rules: never release or retry on an unknown, look up, keep the evidence, and
let staff resolve with a provider reference, step-up and an audit entry. These rules were found one PR at a time, and
each family wrote its own resolution screen. VAT PR 4 (refund reservations), live gateway keys and Namecheap will
each add another path.

## Decision

1. **One meaning.** An outcome is _indeterminate_ when the provider may have acted and we cannot prove either way.
   Every family maps its own types onto `DriverOutcome`, and an indeterminate answer is `Unknown`:
    - payments: `LookupResult::AMBIGUOUS`, and `NOT_FOUND` after evidence of payment;
    - refunds: `ChargeResult::ERROR`, a timeout, a 5xx;
    - registrars: `ReconcileStatus::Unknown`;
    - provisioning: `DriverResult::$outcomeUnknown`.

    Family types stay (ADR 0009 §4); the mapping is documented on each type.

2. **Nothing is released or retried automatically.** Reserved money stays reserved, a claimed name stays claimed,
   and a non-idempotent write is never sent again until a lookup or a person settles it. A retry inside the
   provider's idempotency window, with the same key, is not a second write.
3. **Look up first, with a bound.** A family that has a lookup capability (`LooksUpRefunds`, `lookup()`,
   `ReconcilesSubmissions`, `reconcile`) polls through a bounded sweep (ADR 0017 §2) until a deadline the family
   documents. Past that deadline, the item moves to staff review. It never moves to failed.
4. **Evidence is kept and never downgraded.** Evidence of an action (a signed paid return, a provider id, a
   timestamp) is stored on the attempt or item and survives later lookups. A later "not found" with evidence goes to
   review, never to closed (#362).
5. **One hand-resolution pattern.** Staff settle an indeterminate item in one of two ways. **Happened** needs the
   provider reference, deduplicated against booked rows, and books through the normal path. **Did not happen** is
   final: no later lookup reopens it. Both:
    - need the family's manage permission and `password.recent`;
    - re-check the state under the ADR 0021 lock;
    - write one activity entry inside the transaction.
6. **Review lists share a shape.** Each family lists its items with age, evidence, the last lookup and the action.
   Later, a kernel `ReviewQueue` registry (ADR 0012 shape) gives staff one "needs review" count on the dashboard. It
   is built when the second family needs the count, not now.
7. **Contract tests.** Every driver family contract adds one case: a timeout after sending maps to `Unknown`, never to
   `Rejected` or `Retryable`.

## Consequences

- VAT PR 4 reserves on send, and finalises a note only on a provider success or a staff "Happened".
- Live gateway keys (G1) and new registrars inherit the rules instead of rediscovering them in review.
- No type is renamed, and no merged path changes behaviour. Where a family's code already differs, the difference is
  listed as a follow-up.

## Alternatives rejected

- **One result type for all families.** ADR 0009 §4 already rejected it: the async semantics differ.
- **A timeout-based release after N days.** A late provider success would then pay twice, or lose money.

## Amendment (#500) — 2026-10-25, a success must say what was paid; signed cancels travel on the hint

- **A success without its amount or currency is indeterminate.** `SettlePaymentAttempt` books a
  `LookupResult::SUCCEEDED` only when it carries both, and both equal the attempt's. Without either, the attempt goes
  to staff review (§1: the provider may have taken money, but we cannot prove how much). The guard no longer skips
  the currency check when the driver gives none.
- **Signed cancels are a hint for one lookup, not driver state.** A driver that verifies the provider's signature
  over a cancel sets `ReturnHint::$signedCancel`; Billing passes it to that attempt's lookup as
  `PaymentAttemptRef::$signedCancel`, and the lookup alone decides (it may answer `not_found` with `sessionEnded`).
  Drivers keep no static memory of returns: it held only within one PHP process and grew with a long-lived worker.
  A signed cancel that arrives only through the merchant notice (`CallbackEvent`) is not carried; the attempt then
  ends at the grace period.
