### Changed

- **Products with Prorata on are now charged a pro-rated first period at checkout (#607).** Until now the setting was
  saved but ignored: every order was invoiced one full cycle from the order date. The first invoice now runs from the
  order date (company time zone) to the product's prorata day, plus the next full cycle when ordered after the
  "charge next month" day (monthly) or always (longer cycles). A quarter, half-year or year is divided by its own
  length in days, not by one month. The service renews on the prorata day. The cart and checkout show the pro-rated
  amount and its dates, and the order summary shows when the full-price renewals start. A price-override promotion's
  price is pro-rated the same way. Upgrade note: products without Prorata are unaffected; check that products with it on use the
  day you intend before the next order. Adds `order_items.period_start` and `period_end` (nullable dates).
- **Addons with "Prorata with parent" follow their parent's prorata day (#607).** The addon's first period ends on
  the parent's anchor and is pro-rated the same way; without the setting an addon bills a full cycle from the order
  date, as before.

### Fixed

- **A new service's next invoice date and renewal day are set from its first period (#607).** A service created at
  checkout recorded the order date as its next invoice date until the order invoice was paid, and one ordered on the
  31st renewed on the 28th after February. A re-issued order invoice (fraud review cleared) now bills the dates of the
  original order instead of a new cycle from the re-issue day.
