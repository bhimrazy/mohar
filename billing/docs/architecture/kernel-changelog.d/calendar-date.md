### `CalendarDate` for company dates, ADR 0013 amendment (#410)

- New `App\Support\CalendarDate` (`@api`, `final readonly`): a company-calendar date with `year`, `month`, `day` and no
  time or zone. `of()`, `parse('Y-m-d')` (strict), `fromDateValue()` (a `date` cast or `todayDate()`: 00:00 at a
  UTC offset of zero, anything else refused), `toString()`, `format()` (date characters only; `H`, `e`, `U`, `c` and
  other time or zone characters throw), `compare()`, `equals()`, `isBefore()`, `isAfter()`, `daysUntil()`,
  `addDays()`, `addMonths()` and `addYears()` (clamped to month end, as `BillingCycle::advance()` without an anchor
  day). Additive.
- `CompanyClock::calendarDate(): CalendarDate` is new, and `dayBounds()` also accepts a `CalendarDate` (the only way
  from a date to instants). A service contract implemented by Core: additive for consumers. `todayDate()` is
  unchanged; new code uses `calendarDate()`.
- `Automation::run(string $key, CalendarDate $date)` is new. A task handler typed `fn (CalendarDate $today)` receives
  a `CalendarDate`; a handler typed `CarbonImmutable` still receives the Carbon date value (00:00 UTC) for now. The
  type is read once by `task()`, which refuses a union naming `CalendarDate`.
  **Package authors:** type new tasks with `CalendarDate` and convert with `$clock->dayBounds($today)`; the Carbon
  form will be deprecated once the in-repo tasks have moved.
- **Breaking (pre-1.0):** `App\Data\Reports\ReportQuery::$from` and `$to` are `?CalendarDate`, not `?string`.
  `"{$query->from}"` still prints `Y-m-d`; compare with `->equals()` or `->isBefore()`, and use `startsAt` and
  `endsAt` for timestamp columns. In-repo reports updated. A Date filter also requires `0001-01-01` or later, and
  `ColumnType::Date` cells accept a `CalendarDate`.
- `automation:run --date` accepts only `Y-m-d`.
- Module code, not kernel API: Billing's `DocumentNumbers::next()` and `format()` take a `CalendarDate`, and
  `BikramSambat::fromAd()` accepts one.
