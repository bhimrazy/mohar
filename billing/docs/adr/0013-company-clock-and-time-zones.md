# ADR 0013 — Company clock and time zones

- Status: Proposed · 2026-10-18 · Owner: Principal architect

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
2. **One company time zone**: the setting `core.timezone`, registered in Core's settings section (Settings → Company),
   validated against `DateTimeZone::listIdentifiers()`, defaulting to `config('app.timezone')`. A Core migration
   copies any stored `system.timezone` value. Changing the setting is audited, and the UI warns that it shifts what
   "today" means.
3. **A kernel contract**, `App\Contracts\Core\CompanyClock`, implemented by Core and bound in `CoreServiceProvider`:

    ```php
    public function timezone(): string;
    public function now(): CarbonImmutable;               // now, in the company time zone
    public function today(): CarbonImmutable;             // company-local midnight (a calendar date)
    public function dayBounds(string|CarbonInterface $date): array; // [startUtc, endUtc) instants for querying timestamps
    public function localDate(CarbonInterface $instant): string;     // Y-m-d of an instant on the company calendar
    ```

    Orders' `CompanyClock` is replaced by the contract.

4. **Calendar dates** are company-calendar dates without a time zone: `next_due_date`, invoice `date`/`due_date`,
   `run_date`, promotion start and end, report periods. They are produced only by `CompanyClock::today()` or by
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
