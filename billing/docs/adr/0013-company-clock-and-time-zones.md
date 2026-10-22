# ADR 0013 — Company clock and time zones

- Status: Accepted · 2026-10-19 (CTO, after the 2026-10-19 architecture review) · Owner: Principal architect. Amendment 1 (Proposed, 2026-10-22) keeps the registered key `system.timezone` and puts fiscal documents first in the rollout.

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

## Amendment 1 — 2026-10-22, documents and the clock contract (Proposed)

Status: Proposed, with ADR 0024. Written after the architecture review of #327, which found that Billing dates and
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
   `MailClock` delegate to the contract and are deleted once their callers have moved. `sqlZoneArgument()` and
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
4. **Tests.** Time is frozen with `Carbon::setTestNow()` (or `travelTo()`) at a **UTC instant**, never at a local
   time, and `inCompanyTimezone('Asia/Kathmandu')` sets the setting. There is no fake clock class, because the real
   clock derived from a frozen `now()` is what production runs. Every document test that touches a date also runs at
   18:20 UTC, which is 00:05 the next day in Kathmandu. The Billing arch scan for `::today(` lands in the same PR, so
   later code cannot regress.

### Migration of existing data

- Calendar dates already stored (`issue_date`, `due_date`, `next_due_date`) were taken from the UTC calendar. Only
  rows created between 18:15 and 24:00 UTC (00:00–05:45 in Kathmandu) can be a day early.
- **Numbered invoices are not rewritten.** They are issued documents (ADR 0024 §1), already sent and possibly filed.
  A read-only command, `billing:report-utc-dates`, lists the affected numbered invoices by `created_at` so the owner
  and the accountant can judge them. The fix applies from deploy onwards.
- Drafts and proformas need no backfill: their dates are set again when they are published or paid.
- Before Nepal VAT mode is enabled there are no notes and no stored BS fields, so nothing else moves. This is why the
  amendment must land before ADR 0024's schema.
- On deploy the automation run day can shift by one: the first run after the switch uses the company date. Runs are
  keyed by run date, so a day is neither run twice nor skipped. The release notes say this.

### Consequences

- One clock everywhere. Documents and their BS periods agree with the Kathmandu calendar at the day boundary.
- `DocumentNumbers::next()` changes signature inside Billing. This is module code, not kernel API.
- Three local clocks are removed, which is a small cross-module PR led by Core. Kyc, Platform and Notifications move in
  the same PR, because they only call `today()` and `timezone()`.

### Alternatives considered

- **Set `app.timezone` to the company zone:** one line, but timestamps would be stored in local time, which breaks §1
  and makes a time-zone change rewrite history. Rejected.
- **Rename to `core.timezone` as §2 said:** consistent naming, but a settings migration on live installs for no
  behaviour change. Rejected.
- **A fake `CompanyClock` in tests:** easy to freeze, but tests would then stop exercising the conversion from UTC
  to the local date. Rejected.
