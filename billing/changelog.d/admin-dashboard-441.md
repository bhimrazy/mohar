### Changed

- **The admin dashboard no longer makes a visitor wait while its figures are recomputed (#441).** Income, outstanding,
  overdue and MRR stay fresh for 60 seconds as before; after that the last figures are shown for up to 10 minutes
  while the visit that found them stale recomputes them after its page has been sent. Measured locally (20,000
  clients): that visit took 218–290 ms before and 100–106 ms now. A figure can therefore be up to 10 minutes old
  after a quiet spell; it is fresh again on the next visit. The dashboard shows "Figures as of HH:MM" (company time)
  under the key figures. A visitor still waits on the first visit of the day, after more than 10 idle minutes, and on
  servers that cannot send the page before the work is done (Apache mod_php, `artisan serve`).

### Fixed

- **The load-test seed runs again (#441).** The calendar-date change made invoice numbering take a `CalendarDate`,
  and the seed still passed a Carbon date, so every load-test run would have failed while seeding. A test now runs a
  small seed against the app.
