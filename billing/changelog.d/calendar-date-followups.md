### Changed

- **Domains automation takes the run date as a company-calendar date (#426).** The renewal invoicing, expiry reminder,
  expiry lifecycle and registrar-log prune tasks behave as before; only the date type changed.

### Fixed

- A date format with a non-ASCII literal, such as Devanagari text, is no longer refused by `CalendarDate::format()`.
