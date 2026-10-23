### Changed

- **The admin dashboard no longer makes a visitor wait while its figures are recomputed (#441).** Income, outstanding,
  overdue and MRR stay fresh for 60 seconds as before; after that the last figures are shown for up to 10 minutes
  while the visit that found them stale recomputes them after its page has been sent. Measured locally (20,000
  clients): that visit took 218–290 ms before and 100–106 ms now. A figure can therefore be up to a few minutes old
  after a quiet spell; it is fresh again on the next visit.

### Fixed

- **The load-test seed runs again (#441).** The calendar-date change made invoice numbering take a `CalendarDate`,
  and the seed still passed a Carbon date, so every load-test run would have failed while seeding. A test now runs a
  small seed against the app.
