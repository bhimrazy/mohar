# ADR 0013 — Company clock and time zones

- Status: Accepted · 2026-10-19 (CTO, after the 2026-10-19 architecture review) · Owner: Principal architect. Amendment 1 (Accepted, 2026-10-23) keeps the registered key `system.timezone` and puts fiscal documents first in the rollout. [Amendment (#PRNUM)](#amendment-prnum--2026-10-23-calendardate) (Accepted, 2026-10-23) adds the `CalendarDate` value.

## Context

Billing is a calendar business: due dates, renewal periods, "overdue on day N" and daily automation all depend on
which day it is. Mohar's first markets are Nepal (UTC+5:45) and India (UTC+5:30). Today:

- `config('app.timezone')` is `UTC`, and nearly every module uses `CarbonImmutable::today()` and `now()`, which are
  UTC dates.
- `automation:run` defaults its run date to `CarbonImmutable::today()` (`RunAutomation.php:41`). It is scheduled
  `dailyAt(core.automation_time)` without `->timezone()` (`CoreServiceProvider.php:66`), so "03:00" means 03:00 UTC,
  which is 08:45 in Kathmandu.
- Only Orders has a company clock (`Modules/Orders/Services/CompanyClock.php`). It reads `system.timezone`, a setting
  that nothing registers, so staff cannot set it. Other modules cannot import it.
- Dashboard v2 (#41) documents the result. Orders buckets days in the company time zone, while Billing, Core,
  Services and the Automation page bucket in UTC. "On a company east or west of UTC, income days and order days are
  different calendars."

For a Kathmandu company, an invoice paid at 01:00 local time on the 5th counts as paid on the 4th, and a service due
"today" is suspended on the wrong morning.

## Decision

1. **Storage is UTC.** `app.timezone` stays `UTC` and so do database sessions. All timestamps are stored as UTC
   instants.
2. **One company time zone**: the setting `system.timezone` (amended; first written as `core.timezone`), registered in Core's settings section (Settings → Company),
   validated against `DateTimeZone::listIdentifiers()`, defaulting to `config('app.timezone')`. No settings
   migration is needed (Amendment 1). Changing the setting is audited, and the UI warns that it shifts what
   "today" means.
3. **A kernel contract**, `App\Contracts\Core\CompanyClock`, implemented by Core and bound in `CoreServiceProvider`:

    ```php
    public function timezone(): string;
    public function now(): CarbonImmutable;               // now, in the company time zone
    public function today(): CarbonImmutable;             // 00:00 in the company zone: a real instant (Amendment 1)
    public function todayDate(): CarbonImmutable;         // the company date as a date value: 00:00 UTC (Amendment 1)
    public function isFallback(): bool;                   // the stored zone is invalid and dates use UTC (Amendment 1)
    public function dayBounds(string|CarbonInterface $date): array; // [startUtc, endUtc) instants for querying timestamps
    public function localDate(CarbonInterface $instant): string;     // Y-m-d of an instant on the company calendar
    ```

    Orders' `CompanyClock` is replaced by the contract. **Never compare an instant with a date value** (a `date`
    cast, a run date, `todayDate()`): compare date strings, or convert a date to instants through `dayBounds()`.

4. **Calendar dates** are company-calendar dates without a time zone: `next_due_date`, invoice `date`/`due_date`,
   `run_date`, promotion start and end, report periods. They are produced only by `CompanyClock::todayDate()` (or the company date of `today()`) or by
   arithmetic on other calendar dates (`BillingCycle::advance`). Never call `CarbonImmutable::today()` or
   `now()->toDateString()` in module code. Pest's arch `not->toUse('today')` catches the helper; a small test that
   scans `app/Modules` for `::today(` and `->startOfDay()->toDateString()` catches the static forms, which the arch
   plugin cannot see.
5. **Automation.** `automation:run` defaults to `CompanyClock::today()`, and the schedule uses
   `->dailyAt($time)->timezone($clock->timezone())`. Tasks already receive `$today`; they must use it instead of
   reading the clock again.
6. **Series and reports** bucket timestamps by company day. They compute each day's UTC bounds with `dayBounds()`
   and query ranges, which is portable and needs no database time-zone functions. This is how Orders does it in #41.
7. **Display.** Staff pages show instants in the company time zone for v1, with the zone in tooltips. A per-user zone
   is a later option and does not change storage.
8. **Events** carry calendar dates as `Y-m-d` and instants in UTC (ADR 0011).
9. **Tests.** A shared Pest helper, `inCompanyTimezone('Asia/Kathmandu')`, sets the setting. Every automation and
   dashboard-series test runs once at UTC+5:45, because a non-whole-hour offset exposes bucketing bugs that
   whole-hour zones hide.

## Rollout

1. Core adds the contract, the setting and the migration.
2. Automation uses the clock.
3. Orders switches to the contract.
4. Billing, Services, Domains, Support and Platform replace `today()` module by module. Each step is a small PR with a
   Kathmandu test.
5. The arch rule and the scan test land last.

## Consequences

- Due dates, overdue days and dashboard series agree with the company's calendar, and automation runs at the local
  time staff chose.
- Changing the time zone shifts the run day once. The audit entry and the UI warning make that visible.
- Every module that touches dates needs a small migration PR. Wave 3 work (credit notes, reports) depends on this,
  so it should land before 3.8 and 3.9.

## Amendment 1 — 2026-10-22, documents and the clock contract (Accepted 2026-10-23)

Status: Accepted 2026-10-23 (CTO), with ADR 0024. Written after the architecture review of #327, which found that Billing dates and
numbers fiscal documents on the UTC calendar.

### What changed since this ADR was accepted

- The rollout above has not started. There are three local clocks, none of them the contract: Core's
  `BusinessCalendar`, Orders' `CompanyClock` and Notifications' `MailClock`. Billing, Kyc and Platform use
  `BusinessCalendar`. Billing has 14 files that call `CarbonImmutable::today()`, Services 16 and Domains 11.
- The setting that is registered is `system.timezone`, not `core.timezone`. Core registers it with a help text in
  Settings → Company, and the installer writes it (`Asia/Kathmandu` by default, `RegionalDefaults::TIMEZONE`).
- `BusinessCalendar::sqlZoneArgument()` converts time zones in SQL, which §6 rules out. Two dashboards use it.
- `core.automation_time` is described as "Server time", and the schedule has no `->timezone()`
  (`CoreServiceProvider.php:116`).

### Decision

1. **The key is `system.timezone`.** It is registered, has an installer default and holds data on live installs.
   Renaming it would need a settings migration and would gain nothing. §2 and the status line are corrected to this
   key. Issue #85 keeps the general drift in settings ownership.
2. **Contract.** `App\Contracts\Core\CompanyClock` keeps the shape in §3. Core implements it by turning
   `BusinessCalendar` into `CoreCompanyClock` and binds it in `CoreServiceProvider`. Orders' `CompanyClock` and
   `MailClock` are deleted in PR 0a and their callers use the contract (CTO decision of 2026-10-23, see decision 5).
   `sqlZoneArgument()` and
   `sqlLocalDate()` stay as Core-internal helpers until the two dashboards query with `dayBounds()`, and then they go.
   The contract gets no BS method: the Bikram Sambat date is computed in Billing from the company date (ADR 0024 §2).
3. **Fiscal-document call sites switch first.** Billing step 4 of the rollout comes before any other module and
   before the VAT-register build:
    - issue dates: `PublishInvoice.php:68`, `InvoiceSettlement.php:78` (numbered on payment);
    - numbering: `DocumentNumbers::next()` takes a date the caller must supply (no default to `today()`), so the
      yearly and monthly reset periods, and later `bs_fiscal_year`, follow the company calendar;
    - due dates and validity: `InvoiceController.php:79`, `CreateAddFundsInvoice.php:68`, `Quotes.php:62` and `:127`;
    - overdue and state checks: the `Invoice::isOverdue`, `daysOverdue` and `scopeOverdue` defaults, `PayMethod.php:69`,
      `CaptureInvoice.php:121`, and the default date in `TaxEngine.php:60`;
    - BS dates: `InvoiceBsDates` and the snapshot from ADR 0024 §2 read the company `issue_date`, never an instant;
    - the scheduler: `automation:run` uses `->dailyAt($time)->timezone($clock->timezone())`, the command's default date
      becomes `CompanyClock::today()` (`RunAutomation.php:59`), and the help text for `core.automation_time` says
      "company time". The exchange-rate schedule (`PlatformServiceProvider.php:137`) does the same.
    - new fiscal reads from ADR 0024. They take the company date from the clock, never from an instant:
        - a note's `issue_date` and BS fields;
        - the check that `enabled_from` is the first day of the current or a later BS month;
        - the "in effect" decision, which uses the one instant per transaction of ADR 0024 §8;
        - the company date of `created_at` in the enable-time refusal;
        - the register's default period ("last closed BS month");
        - the "printed at" time on PDFs.
4. **Tests.**
    - Time is frozen with `Carbon::setTestNow()` (or `travelTo()`) at a **UTC instant**, never at a local time.
      `inCompanyTimezone('Asia/Kathmandu')` sets the setting.
    - There is no fake clock class, because a real clock reading a frozen `now()` is what production runs.
    - Every document test that touches a date runs at the boundary pair **18:14:59 and 18:15:00 UTC** (23:59:59 and
      00:00:00 in Kathmandu). It also runs on **1 Baisakh** (the BS year start) and **1 Shrawan** (the fiscal-year start),
      where a day's error changes the year.
    - The arch scan lands in the same PR. Over `app/Modules/Billing` it rejects `::today(`, a bare `today()`,
      `now()->toDateString()`, `->startOfDay()->toDateString()` and `date('Y-m-d'`. The other modules join the scan
      as each one moves.
5. **Scope of the prerequisite PR (0a).** PR 0a contains:
    - the contract and Core's implementation;
    - Billing's fiscal call sites (decision 3) and the scheduler change;
    - the tests and the scan.

    **CTO decision, 2026-10-23: the three local clocks are deleted in 0a.** Orders, Kyc, Platform and Notifications
    moved only their clock imports (their own `today()` and `now()` calls are unchanged); Notifications' `MailClock`
    keeps its date formatting as `MailDates`. Orders' `today()` changed kind (it was a 00:00 UTC date value, it is
    now an instant); its consumers are unchanged in behaviour because they use `toDateString()`, read `day` in the
    company zone, or store through `date` casts. The remaining `CarbonImmutable::today()` and `now()->toDateString()`
    calls move module by module, as in the Rollout above, each with a Kathmandu test: Services (13 files), Domains
    (9), Notifications (6), Platform (`ExchangeRateStatus`) and the retention cutoffs that use a date as an instant
    (Orders `CancelStalePendingOrders`, Notifications `PruneNotificationLogs`, the Platform, Domains and Services
    prunes). Support has no `today()` calls.
    - **`today()` is a real instant**: 00:00 in the company zone, not 00:00 UTC of the company date. A company
      date becomes an instant only through `dayBounds()`, never `startOfDay()` or a bare date compared with a
      timestamp column. Automation tasks still receive the run date as a date value (00:00 UTC of the company
      date, like a date column), so that code which compares it with date columns keeps working. A task that compares
      it with a timestamp converts it with `dayBounds()`.
    - **`todayDate()`** names the date value (00:00 UTC of the company date, the shape of a `date` cast and of the run
      date that tasks receive: see the `Automation` docblock). The Arch scan bans hand conversions (`shiftTimezone(`,
      `setTime*`, `endOfDay`, `isToday`, `isSameDay`, `utc`, `setTimezone` on `$today`, and a `*_at` column compared
      with `$today`) over `app/Modules`, with a baseline of existing hits, counted per file, that may only shrink. A distinct
      `CalendarDate` value object for task signatures is deferred to Amendment 2.
    - **Invalid zone**: backward-compatible names (`Asia/Katmandu`, `Asia/Calcutta`) are accepted and mapped to their
      canonical zones. A real fallback to UTC is reported and shown as a dashboard warning. A VAT note must refuse
      to be issued while the clock is in fallback (`CompanyClock::isFallback()`, used from PR 4).

### Migration of existing data

- Calendar dates already stored (`issue_date`, `due_date`, `next_due_date`) were taken from the UTC calendar. Only
  rows created between 18:15 and 24:00 UTC (00:00–05:45 in Kathmandu) can be a day early.
- **Numbered invoices are not rewritten.** They are issued documents (ADR 0024 §1), already sent and possibly filed.
  A read-only command, `billing:report-utc-dates`, lists the affected numbered invoices by when they were created, paid or last updated (the moment of dating is not stored) so the owner
  and the accountant can judge them. The fix applies from deploy onwards.
- Drafts and proformas need no backfill: their dates are set again when they are published or paid.
- Before Nepal VAT mode is enabled there are no notes and no stored BS fields, so nothing else moves. This is why the
  amendment must land before ADR 0024's schema.
- On deploy the automation run date can shift: the first run after the switch uses the company date. Runs are keyed
  by (run date, task), so a date is never run twice. For a Kathmandu install deployed between 21:15 and 03:00 UTC a
  run date can be skipped once (18 Oct, then 20 Oct). Nothing is lost: the Billing, Services and Domains tasks select
  by `<=` windows, so the next run takes what the skipped one would have. An operator who wants the skipped date
  can run `automation:run --date=<date>`. `core.automation_time` and `platform.rates_update_time` now mean company
  time; the release notes say this, and no value is converted (there are no live installs yet).

### Consequences

- One clock everywhere. Documents and their BS periods agree with the Kathmandu calendar at the day boundary.
- `DocumentNumbers::next()` changes signature inside Billing. This is module code, not kernel API.
- The three local clocks are removed in PR 0a (CTO decision), with their callers moved to the contract, so every
  module already reads one company time zone. The other `today()` calls move module by module.

### Alternatives considered

- **Set `app.timezone` to the company zone:** one line, but timestamps would be stored in local time, which breaks §1
  and makes a time-zone change rewrite history. Rejected.
- **Rename to `core.timezone` as §2 said:** consistent naming, but a settings migration on live installs for no
  behaviour change. Rejected.
- **A fake `CompanyClock` in tests:** easy to freeze, but tests would then stop exercising the conversion from UTC
  to the local date. Rejected.

## Amendment (#PRNUM) — 2026-10-23, `CalendarDate`

Status: Accepted 2026-10-23 (CTO decision D6 of the 2026-10-23 architecture review). Called "Amendment 2" in that
review and in Amendment 1; keyed by its PR under ADR 0027.

### Context

Amendment 1 left two "todays": `today()`, an instant at the company midnight, and `todayDate()`, the company date as
a Carbon value at 00:00 UTC. The second is still an instant in type: it accepts `setTime()`, `endOfDay()` and `lt()`
against a timestamp, and only an Arch scan with a baseline keeps tasks from converting it by hand. 30 automation
closures, `DocumentNumbers::next()`, the BS conversion and `ReportQuery` periods take dates in this shape or as bare
strings. Each module that moves onto the clock (Domains in #398, Billing in VAT PR 3, the importer in G3b) would move
again if the date type changed after it.

### Decision

1. **The value.** `App\Support\CalendarDate`, `final readonly`, beside `App\Support\Money` and public kernel surface
   in the same way (`@api`, ADR 0010). It holds `year`, `month` and `day`, and nothing else: no time, no zone.
    - Made by `of(y, m, d)`, `parse('Y-m-d')` (exactly that form; `'today'`, a time or an offset is refused) and
      `fromDateValue()` for a `date`-cast attribute or another date value. `fromDateValue()` refuses a value with a time
      of day, so an instant cannot slip in; an instant goes through `CalendarDate::parse($clock->localDate($instant))`.
    - Read by `toString()` (`Y-m-d`; also `Stringable` and `JsonSerializable`), `format()` for display, `compare()`,
      `equals()`, `isBefore()`, `isAfter()` and `daysUntil()`.
    - Arithmetic: `addDays()`, `addMonths()` and `addYears()`, negative amounts allowed. **Overflow rule:** months and
      years keep the day of the month and clamp it to the last day of a shorter month (31 Jan + 1 month = 28 or 29
      Feb; 29 Feb + 1 year = 28 Feb). The clamp is applied once and not carried (31 Jan + 1 month + 1 month = 28 Mar,
      while 31 Jan + 2 months = 31 Mar). This is `BillingCycle::advance()`'s rule, so a cycle and a date agree.
2. **To an instant only through `dayBounds()`.** `CompanyClock::dayBounds()` accepts a `CalendarDate` (a widened
   parameter, compatible for callers). `CalendarDate` has no `toCarbon()`, `startOfDay()` or zone argument, and none
   is to be added.
3. **The clock.** `CompanyClock::calendarDate(): CalendarDate` is added; `todayDate()` keeps its Carbon return type.
   Changing `todayDate()`'s return type was the first choice and is rejected for now: 33 calls in 24 module files use
   Carbon methods on it (`lt`, `addDays`, query bindings), mostly in Services and Billing, with #398 and #385 open on
   the same files. One kernel PR would rewrite all of them and conflict with wave 2. Modules move to `calendarDate()` once,
   as part of their own clock move. When no in-repo caller of `todayDate()` remains, it gets `@deprecated` with ADR
   0010's window and is then removed; it is never re-typed, so no module moves twice.
4. **Automation tasks.** `Automation::run(string $key, CalendarDate $date)` runs a task. A handler whose first parameter
   is typed `CalendarDate` gets the `CalendarDate`; any other gets the Carbon date value as before. `automation:run`
   resolves the run date as a `CalendarDate` and accepts `--date` only as `Y-m-d`. Catalog, Migration, Orders (cart
   pruning), Platform and Support tasks are typed in this PR. Billing (VAT PR 3), Services, Domains (#398), Kyc,
   Notifications and Orders' stale-order cancelling move with their modules. When none is left, the Carbon branch is
   removed (pre-1.0 breaking change, kernel changelog).
5. **Document numbers.** `DocumentNumbers::next(string $sequence, CalendarDate $date)` and `format()` take a
   `CalendarDate` (module code). Callers pass `CalendarDate::fromDateValue($invoice->issue_date)` or
   `$clock->calendarDate()`.
6. **Bikram Sambat.** `BikramSambat::fromAd()` accepts a `CalendarDate` (the `DateTimeInterface` form stays for its
   existing callers); the fiscal snapshot passes one. `toAd()` returning a `CalendarDate` is a follow-up with VAT PR 3.
7. **Report periods.** `ReportQuery::$from` and `$to` are `?CalendarDate` (a pre-1.0 breaking change: the only
   in-repo reports are test reports, updated here; `Stringable` keeps string interpolation working). `startsAt` and
   `endsAt` stay the instants from `dayBounds()`.
8. **Storage and events are unchanged.** Date columns keep `date` casts and are read with `fromDateValue()`; a
   `CalendarDate` Eloquent cast is added when the first module stores through one. Events carry `Y-m-d` strings (§8),
   which is what `jsonSerialize()` gives.
9. **Not added:** SQL helpers on the contract (`sqlLocalDate()`, asked for in #366: bucket in PHP with `dayBounds()`,
   §6); a `today()` or `now()` factory on `CalendarDate` (only the clock says what today is); a second date type in any
   module.

### Tests

Value semantics, strict parsing, month-end clamping, leap years (2028, 2100), `dayBounds()` round trips at the
Kathmandu midnight (18:15 UTC), on 1 Shrawan 2083, and at New York midnight in winter, summer and on both DST days
(23 and 25 hours); automation handing each handler its type; a yearly document sequence resetting at the Kathmandu
new year (18:14:59 and 18:15:00 UTC on 31 December); the BS fiscal year turning at local midnight on 1 Shrawan.

### Consequences

- A task typed `CalendarDate $today` cannot call an instant method on the run date, so the hand-conversion baseline of
  `CompanyClockScanTest` only shrinks as modules move.
- Two ways to get today's date exist until `todayDate()` is deprecated: new code uses `calendarDate()`.

### Alternatives considered

- **Re-type `todayDate()` now:** one name, but 24 files rewritten in a kernel PR during wave 2. Rejected (decision 3).
- **A Carbon subclass with the time methods disabled:** keeps call sites, but it is still an instant underneath and
  `CarbonImmutable` parameters would accept it. Rejected.
- **`Y-m-d` strings:** no type, no arithmetic and no month-end rule. Rejected.
