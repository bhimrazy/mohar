# Stripe fixtures

Response bodies shaped from the objects in Stripe's API reference (docs.stripe.com/api, read 2026-10-18), trimmed
to the fields the driver reads plus a few neighbours. Every id, key and amount is made up. Tests override fields
with `stripeFixture('name', [...])`.
