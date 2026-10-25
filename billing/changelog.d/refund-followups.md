### Fixed

- **A Stripe refund that fails after it was booked now goes to Disputes to review (#498).** Stripe can fail or cancel a
  refund after reporting it succeeded (the card or bank returns it), and before #497 refunds still `pending` or
  `requires_action` were booked as made. The `refund.updated` webhook for such a refund used to be logged only, so the
  books showed money refunded that never left. Now a refund booked as made, whether from the admin or the Stripe
  Dashboard, is put on Disputes to review for at least the booked amount, once: a replay, or another failed event
  after staff mark it reviewed, does not reopen it. Nothing is reversed automatically. A failed webhook for a refund
  still pending only marks it: Check refund releases it, as before, and if it is booked anyway it goes to review. One
  for a refund never recorded here is logged. An earlier review note on the row is kept.
- **Refund rows released before #497 no longer block a later "paid" report (#499).** A migration moves the provider
  refund id of every released (failed) refund row from `gateway_txn_id` into its stored state, as releases do since
  #497. A later report from the provider that the refund was paid is then booked once and goes to Disputes to review,
  where it used to be answered as a duplicate and booked nothing.

    Upgrade notes:
    - The migration changes data only (no schema change) and is safe to run while the previous release serves.
    - A Stripe refund that failed before this release was only logged. The query in the #497 notes still finds the
      refunds booked while Stripe had them pending; check those at Stripe. Failures Stripe reports from now on reach
      Disputes to review.
