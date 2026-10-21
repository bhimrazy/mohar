# Admin dashboard v2

Status: spec for `feature/dashboard-v2` · 2026-10-18 · Owner: principal designer

The owner compared the direction mockups (`directions/{calm,operator,slate,warm}/dashboard-*.png`, sample
data) with the shipped dashboard (`screenshots/billing/admin-dashboard-90d.png`, real widgets) and asked for
the real one to be at least as good, especially the charts. Two binding rules came with that:

1. **Nothing the current dashboard shows may be lost.** It is reorganised and presented better. Anything
   that might move off the dashboard stays one click away and is listed as an open question.
2. **The directions are the primary reference** (all four), with Calm Fintech as the shipped default. Every
   block must look right under every preset because all presets share the same components.

Hierarchy, top to bottom: **summary** (KPIs, counters, needs attention), then **trends** (charts), then
**detail** (lists).

## 1. What the directions contribute

The four directions share one layout and one component set; they differ in tokens (colour, font,
radius, density). v2 takes the shared layout and these specific treatments:

| Element                                                                            | Taken from                      | Notes                                                                                                                                                                |
| ---------------------------------------------------------------------------------- | ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| KPI card: label, icon chip, figure, trend pill, context line, 12-point sparkline   | all four                        | Overdue card carries the danger tone on its icon chip and sparkline.                                                                                                 |
| Income hero: headline total, YoY pill, "Reporting in" caption, area + compare line | Calm (soft wash under the line) | Area fill is a ~10% wash of `--chart-1`.                                                                                                                             |
| Comparison series drawn in neutral ink, not a second hue                           | Slate                           | Slate draws "previous year" grey. v2 does this in every preset (`--muted-foreground`, dashed) so the comparison never competes with this year and frees `--chart-2`. |
| Stacked new-orders bars with legend under the plot                                 | all four                        | 2px surface gap between segments (dataviz spec) instead of touching blocks.                                                                                          |
| Income by currency: code chip, name, amount in original currency, share meter      | all four                        | Tax collected becomes a per-tax list rather than one converted sum (see 4.6).                                                                                        |
| Needs attention rows with tone icon chip, context line, count, chevron             | all four                        | Moves to the top on mobile (Calm mobile).                                                                                                                            |
| Health card: icon, check name, detail line, status dot + value                     | all four ("Automation health")  | Renamed **System health**; fed by an aggregated registry (5.3).                                                                                                      |
| Quick actions card with ⌘K hint                                                    | all four                        | Per-action key hints are dropped: no such shortcuts exist, and the UI must not advertise them.                                                                       |
| Tabular, monospaced figures in KPIs                                                | Operator                        | Already a theme token (`--font-numeric`); v2 uses `font-numeric` everywhere a figure appears so Operator keeps its look.                                             |
| Header: currency switch + New                                                      | all four                        | Export waits for the Reports module (decision 3).                                                                                                                    |

Secondary references (market): Stripe's home overview (period comparison drawn as a muted line under the
current one, "compared to previous period" caption), shadcn/ui dashboard blocks (section cards with a trend
badge and a footer line; an interactive area chart with a 3-way range toggle), Linear and Vercel analytics
(restrained hairline grid, tabular numbers, one accent). They confirm the directions rather than add to them.

## 2. Critique of the current build (vs. the directions)

- **First impression.** A wall of 15 counters competes with the chart; nothing reads as the headline. The
  directions lead with four money KPIs with trends, then a large income chart.
- **Income chart.** 30 daily points, mostly zero with one spike: noisy, no comparison, no total. The
  directions show 12 monthly points with the previous year, which answers "are we growing?".
- **Counters duplicate Needs attention.** Overdue, pending orders, failed module actions, awaiting reply and
  domains expiring each appear twice with no added context. Needs attention has dots but no icons or context.
- **Automation is a raw per-task list** of 17 rows with technical keys (`billing.late_fees`), summarised by
  a status-count bar. The directions summarise system health in six readable rows.
- **Lists are good and must stay** (renewals, payments, activity, sign-ins are not in the direction). They
  are stacked in one long masonry column with uneven heights; the page is 3,000px tall at 1440.
- **Money rows** show `2.99 USD`, not formatted money; status badges are fine.
- **Accessibility.** Charts have an `aria-label` but no data-table equivalent; tooltips are the only way to
  read values.

## 3. Inventory → placement (binding)

Everything the current dashboard shows, and where it lives in v2. "Strip" is the secondary counter strip
under the KPIs; "NA" is Needs attention.

| #   | Current element (owner module)                               | v2 placement                                                                                                                           |
| --- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | KPI: Active clients, "N new this month" (Core)               | Strip tile, hint kept as the tile's context line                                                                                       |
| 2   | KPI: Income this month (Billing)                             | **Primary KPI**, plus trend vs. same days last month and 12-week sparkline                                                             |
| 3   | KPI: Pending orders (Orders)                                 | NA "Orders awaiting review" (same query and permission; removed from the strip, CTO decision 2026-10-18)                               |
| 4   | KPI: New clients this month, trend vs. last month (Core)     | Strip tile with its trend pill                                                                                                         |
| 5   | Counter: Orders today (Orders)                               | Strip tile                                                                                                                             |
| 6   | Counter: Unpaid invoices, amount + "N invoices" (Billing)    | **Primary KPI "Outstanding"** (amount, count as context, trend, sparkline)                                                             |
| 7   | Counter: Overdue invoices, count (Billing)                   | **Primary KPI "Overdue"** (amount, "N invoices, oldest D days", trend, sparkline); count also in NA                                    |
| 8   | Counter: Active services (Services)                          | Strip tile                                                                                                                             |
| 9   | Counter: Pending provisioning (Services)                     | NA "Services waiting for provisioning" (exact duplicate; removed from the strip)                                                       |
| 10  | Counter: Failed module actions (Services)                    | NA "Failed module actions" (exact duplicate; removed from the strip)                                                                   |
| 11  | Counter: Domains expiring in 30 days (Domains)               | NA "Domains expiring in 30 days" (exact duplicate; removed from the strip)                                                             |
| 12  | Counter: Open tickets (Support)                              | Strip tile                                                                                                                             |
| 13  | Counter: Pending registrations (Domains)                     | Strip tile                                                                                                                             |
| 14  | Counter: Awaiting staff reply (Support)                      | NA "Tickets awaiting staff reply" (exact duplicate; removed from the strip)                                                            |
| 15  | Counter: My assigned tickets (Support)                       | Strip tile                                                                                                                             |
| 16  | NA items, all 18 registered types (all modules)              | NA, unchanged keys and order; each gains an icon and, where cheap, a context line                                                      |
| 17  | Chart: Income, last 30 days, per-currency switch (Billing)   | Hero chart, **"30 days" range** (daily, vs. previous 30 days); the currency switch moves to the page header                            |
| 18  | Chart: New clients, last 12 weeks (Core)                     | Trends row, unchanged data, restyled                                                                                                   |
| 19  | List: Recent orders (Orders)                                 | Detail grid; money formatted, client name leads                                                                                        |
| 20  | List: Newest clients (Core)                                  | Detail grid                                                                                                                            |
| 21  | List: Renewals due in 7 days (Services)                      | Detail grid                                                                                                                            |
| 22  | List: Recent payments (Billing)                              | Detail grid; money formatted                                                                                                           |
| 23  | List: Recent activity (Platform)                             | Detail grid, **Activity** card, tab 1                                                                                                  |
| 24  | List: Recent staff sign-ins (Platform)                       | Detail grid, **Activity** card, tab 2                                                                                                  |
| 25  | List: Tickets awaiting reply (Support)                       | Detail grid                                                                                                                            |
| 26  | List: Automation, every task with its last status (Platform) | **System health → "Daily automation"** row: ran / failed / not run counts; "View all" opens the per-task page (one click). Decision 1. |
| 27  | Greeting, date, description (page)                           | Header, unchanged                                                                                                                      |

New in v2 (from the directions): MRR KPI (Services), outstanding and overdue amounts with sparklines
(Billing), income 12 months vs. previous year with YoY (Billing), new orders per day by product type
(Orders), income by currency with tax collected (Billing), system health (Platform, Services, Billing,
Domains), quick actions (Navigation registry), header currency switch and New menu.

## 4. Layout

Content width at 1440 is about 1,150px (sidebar 256px). Gap is the theme's `--page-gap`.

**Desktop (≥1280, `xl`)**

```
Header ............................................. [All | USD | INR | NPR] [+ New]
[ MRR ][ Income this month ][ Outstanding ][ Overdue ]                 4 cols
[tile][tile][tile][tile]                                                4 cols (6 at ≥1536), 3 rows
[ Income hero (12 months | 30 days)         ][ Needs attention ]       2fr / 1fr
[ New orders (stacked) ][ New clients ][ Income by currency ]          3 cols
[ System health ][ Recent orders ][ Recent payments ]                  3 cols
[ Tickets awaiting ][ Renewals due 7 days ][ Newest clients ]          3 cols
[ Activity (tabs: activity | sign-ins)      ][ Quick actions ]         2 / 1
```

**Tablet (768–1279):** KPIs 2×2, strip 3–4 per row, hero full width with Needs attention below it, trend and
detail grids 2 columns.

**Mobile (<768):** Needs attention first (the direction's mobile order), then KPIs 2×2, hero, strip 2 per
row, then every card full width. The currency switch stays in the header; New collapses to an icon button.

The layout is a policy over widget **types**, not hard-coded keys, so a missing module only removes its own
cards: first four stats → KPI row; other stats → strip; first money chart → hero; other charts and
breakdowns → trends grid; health → first card of the detail grid; lists (tab groups merged) → detail grid;
quick actions → last. Empty rows collapse.

## 5. Blocks

Colours are theme tokens only: series `--chart-1..6`, comparison `--muted-foreground`, grid
`--chart-grid`, status `--success|warning|danger|info` with their `-subtle` and `-text` pairs. Text never
wears a series colour. Figures use `font-numeric`; axis ticks and list amounts use `tabular-nums`.

### 5.1 Primary KPIs (4)

| KPI               | Module   | Value                                                 | Trend pill                              | Sparkline (12 pts)          | Context line                |
| ----------------- | -------- | ----------------------------------------------------- | --------------------------------------- | --------------------------- | --------------------------- |
| MRR               | Services | Σ active + suspended recurring amount ÷ cycle months  | vs. one month ago, up is good           | month-end MRR, 12 months    | "From N recurring services" |
| Income this month | Billing  | payments + manual income − refunds − chargebacks, MTD | vs. same days last month, up is good    | weekly net income, 12 weeks | "Oct 1–18 vs. Sep 1–18"     |
| Outstanding       | Billing  | Σ balance of payable invoices                         | vs. 30 days ago, up is bad              | weekly snapshot, 12 weeks   | "N unpaid invoices"         |
| Overdue           | Billing  | Σ balance of overdue invoices                         | vs. 30 days ago, up is bad; tone danger | weekly snapshot, 12 weeks   | "N invoices, oldest D days" |

Form: stat tile (dataviz: the number is the chart). Sparkline uses `--chart-1`; only the Overdue card (tone
danger) draws it in `--danger`. Whether a change is good or bad is carried by the trend pill, not by turning
every line red (tried and rejected: four red sparklines read as an alarm on an ordinary day). Empty: value `0`/`$0.00`, no pill, flat sparkline hidden (fewer than two non-zero points). The whole
card is the link (to services, transactions, unpaid and overdue invoices).

### 5.2 Counter strip

Every remaining stat as a compact tile: icon, label, value, optional context line or trend. Four per row from
`lg`, two on phones with two-line labels. Tiles keep their links; zero values stay (a zero is information).

A counter that is the same figure as a Needs attention item (same query, same permission, same key) is
registered with `mirrorsTodo: true`; the admin dashboard skips it without running its query and shows the
figure only in Needs attention (CTO decision, 2026-10-18): pending orders,
pending provisioning, failed module actions, domains expiring in 30 days and tickets awaiting staff reply.
Seven counters remain: active clients, new clients this month, orders today, active services, pending domain
registrations, open tickets, my assigned tickets.

### 5.3 Needs attention

Unchanged registry and keys; rows gain `icon` and an optional `hint` (e.g. overdue: "$3,120.40 at risk";
pending orders: "oldest waiting 5 hours"; domains expiring: "3 without auto-renew"). Badge: "N open" in the
most severe tone. Empty: "All caught up · Nothing is waiting on staff right now."

### 5.4 Income hero (Billing)

- **Form:** area line, this period in `--chart-1` with a 10% wash, previous period as a dashed 1.5px line in
  `--muted-foreground`, no fill. One y-axis, compact money ticks ($45K, ₹12L). Why: change over time with a
  baseline; a bar chart of 12 months would hide the year-over-year shape.
- **Ranges:** `12 months` (default; monthly, vs. the same months a year earlier) and `30 days` (daily, vs.
  the previous 30 days). A segmented control in the card header; it only scopes this chart.
- **Headline:** period total in the reporting currency, then a pill "+18.4% vs. previous year" (or "vs.
  previous 30 days"), success or danger subtle tone; neutral when the previous period is zero ("No prior
  data").
- **Hover:** vertical crosshair, one tooltip listing both series with the value first, plus the change.
- **Table view:** a "Show as table" toggle (button with `aria-pressed`) swaps the plot for a table of the
  same points, so values are reachable without hover.
- **Empty:** fewer than one non-zero point → "No income recorded yet. Payments you record appear here."

### 5.5 New orders by product type (Orders)

- **Form:** stacked columns, one per day for 14 days, ≤ 4 categories: Hosting (shared + reseller), Servers
  (server-type products, i.e. VPS and dedicated), Domains, Other (add-ons, upgrades, unknown). Each order counts once, under its
  first top-level item, so the stack height is the order count. Colours `--chart-1..4` in that fixed order.
  2px surface gap between segments, 4px rounded top, bar ≤ 24px.
- **Header:** "Last 14 days · N orders". Legend under the plot. Per-bar tooltip lists every category.
- **Why stacked:** the total per day is the primary read and the mix is secondary; four categories is
  within the stacking limit.
- **Empty:** "No orders in the last 14 days."

### 5.6 Income by currency (Billing)

- **Form:** a `breakdown` list, not a donut (close shares are easier to compare as aligned bars). Rows:
  currency code chip, currency name, amount in its own currency (INR lakh grouping, NPR "Rs", per product standards), a share meter
  in `--chart-n` by fixed currency order, "46% of income". Shares are computed after converting every
  currency to the default currency at today's rates; the amounts shown are not converted.
- **Footer:** "Tax collected this month" with one line per tax and currency (e.g. "GST ₹1,24,000.00 · VAT
  Rs 8,400.00"), from invoices paid this month. Tax amounts are never added across currencies.
- **Empty:** "No income this month yet."

### 5.7 New clients (Core)

Existing weekly bar chart, restyled with the shared chart: single series, no legend, bars ≤ 24px.

### 5.8 System health (aggregated)

A card titled "System health" with a badge "N issues" (warning) or "All systems normal" (success). Each
module contributes checks; each row: icon, name, detail, status dot + short value, and an optional link.

| Check              | Module   | Source                                                                     | OK when                                 |
| ------------------ | -------- | -------------------------------------------------------------------------- | --------------------------------------- |
| Scheduler          | Platform | scheduler heartbeat (`AutomationStatus`)                                   | < 5 min OK, < 60 min late, else stopped |
| Daily automation   | Platform | `automation_runs` latest run per registered task                           | no failed task and the run is not stale |
| Queue              | Platform | `jobs` waiting, `failed_jobs` in the last 24 hours                         | no failed jobs                          |
| Provisioning       | Services | `module_commands` success rate, last 7 days; open `module_queue` items     | rate ≥ 98% and no open failures         |
| Payment gateways   | Billing  | `payment_attempts` last 7 days: succeeded vs. failed + ambiguous to review | nothing to review                       |
| Registrar commands | Domains  | `registrar_command_log` success rate, last 7 days                          | rate ≥ 98%                              |

Status always pairs a dot with text ("OK", "98.6%", "2 failed"); colour is never alone. Email delivery joins
when Notifications ships its check.

### 5.9 Lists

Shared `ListWidget`. Items may carry `money` (formatted on the client with the currency's grouping) and
`leading` initials. "View all" links stay. Lists sharing a `group` render as one card with tabs (Radix
Tabs, arrow-key navigation): **Activity** = Recent activity + Recent staff sign-ins. Empty copy unchanged.

### 5.10 Quick actions

`Navigation::quickActions()` (the same source as the header New menu) as a card of links with icons; the
header shows "⌘K" because the command palette exists. Hidden when the user has no create permissions.

## 6. Interactions

- **Reporting currency** (page header, segmented control): each configured currency, default first, then
  `All (est.)`. The default view is the default currency. A code shows only money in that currency,
  unconverted. `All (est.)` converts every currency to the default currency at today's rates and says so
  ("All currencies in USD, estimated at today's rates"). Transactions do not store their rate to the default
  currency (`transactions.exchange_rate` is the gateway's conversion rate, set only by converting gateways),
  so converting at the rate in force when paid is not possible without a Billing schema change; until then
  "All" is an explicit estimate (CTO decision, 2026-10-18). Every money widget sends a variant for every
  configured currency, so each option has data. The choice is remembered per browser (localStorage, in
  try/catch). Hidden when only one currency is configured.
- **Range** on the hero only (12 months | 30 days).
- **Hover/focus:** crosshair + tooltip on line charts, per-column tooltip on bars; cards and rows show a
  hover wash and a visible focus ring.
- **Deferred loading:** heavy widgets stay deferred; their slots hold a skeleton of the final size so the
  layout does not jump.

## 7. Registry contract (backward compatible)

`App\Support\Dashboard\Dashboard`. Modules that send none of the new fields keep working unchanged.

- **stat** gains optional `sparkline: int[]`, `trend: {value, direction: up|down|flat, upIsGood?}`,
  `tone: default|warning|danger`, `money: {amount, currency}` (already sent by Billing) and `primary: bool`
  (the KPI row: up to four flagged cards; without any flag the first four by order, as before).
- **card()/widget()** gain `mirrorsTodo: bool`: `resolveWidgets()` skips such a card without running it;
  `resolve()` (client area, simple dashboards) still returns it, marked `mirrorsTodo: true`.
- **Controller props:** `todos` and `widgets` (closures, first paint), `deferredWidgets` and `health` (one
  deferred request). The unused `cards`, `activity` and `automation` props are gone.
- **chart** gains optional `stacked: bool`, `description`, `summary: {money|value, label, trend?}`, series
  `compare: bool` (drawn as the neutral dashed baseline), `ranges: {key: {label, series, summary?}}`, and
  `xFormat: month|day|dayOfMonth|week`.
- **Currency variants (any widget):** the base payload is the consolidated "All" view (default currency at
  today's rates); optional `variants: {<code>: partial payload}` hold each currency unconverted. The client
  merges `variants[selected]` over the base. The legacy chart `currencies` map keeps working.
- **chart series** may also carry `slot` (fixed `--chart-n`, so a category keeps its colour when others are
  absent) and `tone` (`success|danger|warning|neutral`, for series that are themselves a status).
- **New type `breakdown`:** `{title, description?, rows: [{key, label, code?, money|value|display, share}], notes?:
  [{label, money}] , empty?}`.
- **list** items gain optional `money` and `avatar` (a name shown as initials); lists gain optional `group`,
  `groupTitle` and `tabTitle`.
- **todo** items gain optional `icon` and `hint` (the client already renders them).
- **New aggregated registry `health()`** (like `todo()`):
  `$dashboard->health('admin', 'services.provisioning', fn ($user) => ['label', 'detail', 'value', 'tone',
  'icon', 'href'?], 'services.module_log.view', order: 20)`; `resolveHealth()` returns visible checks in order.
- `Dashboard::TYPES` gains `breakdown`.

## 8. Data rules

- Money in integer minor units; `sum()` results cast to `int` (PostgreSQL returns strings).
- One query per widget where possible, on indexed columns (`transactions(type, occurred_at)`,
  `invoices(status, due_date)`, `services.status`, `orders.created_at`, `module_commands.created_at`,
  `registrar_command_log.created_at`).
- Daily buckets use `date(<column>)`, which SQLite, MySQL and PostgreSQL all accept; months, weeks and
  comparisons are built in PHP. No `strftime`, `date_trunc` or `DATE_FORMAT`.
- Billing computes one per-currency dataset for all its widgets and caches it for 60 seconds
  (`Cache::remember`, key includes the date), so the Billing widgets share one set of queries. The cache holds
  computed figures only (daily income, per-currency receivable totals with their 12 weekly points, tax),
  never invoice rows. The dataset is the same for every user; permissions apply per widget.
- Receivables are read in three indexed queries: payable statuses (`invoices(status, due_date)`), paid or
  refunded since the window start (`invoices(status, paid_at)`, added here), cancelled since the window start.
- Historical outstanding/overdue points are reconstructed from issue, paid and cancelled dates; partial
  payments on still-open invoices count at today's balance. Overdue uses one rule today and in history: an
  invoice still open counts only while Unpaid (not Payment pending or Collections); one closed since counts
  while it was open and past due. The last point always equals the KPI value.
- Budget: one visit makes about 50 queries for first paint and about 24 for the deferred request (all
  modules and permissions, cold cache), with no growth per row. `DashboardQueryCountTest` holds the ceilings
  (60 and 32).
- **Calendars.** Every series buckets days in the company timezone (`system.timezone`): Orders through its
  `CompanyClock`; Billing and Core sign-ups through Core's `BusinessCalendar` (local today; local midnight
  converted to UTC for query bounds). Billing sums income per currency and local day in SQL with literal
  per-driver SQL and the zone as a bound parameter: PostgreSQL `date((occurred_at at time zone 'UTC') at time
  zone ?)` (exact, DST included), MySQL `convert_tz` and SQLite a `date(col, ?)` modifier (today's offset),
  grouped by position (`GROUP BY 1, 2`). Due dates and automation run dates are calendar dates already.
- **Shared counts.** A nav badge and its Needs attention item read one count per request through Core's
  `RequestMemo` (stored on the request, so nothing leaks between requests): pending orders, open cancellation
  requests, tickets awaiting reply. Currency rows are read once per request (`CurrencyRollup`, request-scoped).
- Each widget is registered with its permission and hidden when the user lacks it. Widgets touch only their
  own module's tables; cross-module data goes through contracts (`PriceBook::product()` for product type).
- Demo seeding: billing history extends to 24 months with gradual growth so the YoY comparison is
  meaningful; Orders seeds 30 days of demo orders across product types; Platform seeds 30 days of automation
  runs (one failure, one missed day). None of it runs in production (Platform's existing demo history was not
  guarded; it is now).

## 9. Accessibility

- Every chart has `role="img"`, an `aria-label` that names what and when, and a table view toggle.
- Trend pills include screen-reader text ("Up 12.1%, good").
- Tabs, range and currency controls are Radix toggle groups/tabs: arrow keys move, focus ring visible.
- Status uses icon/dot plus text. Chart colours were checked with the dataviz validator against each
  preset's card surface: all pass 3:1 contrast. Palette fixes (CTO decision 2026-10-18), each the smallest
  OKLCH move that clears CVD ΔE ≥ 8 and the normal-vision floor (≥ 15) against its neighbours, with the full
  palette then passing (adjacent pairs; remaining 6–8 warnings are slots 5↔6, which dashboard charts never
  use, and every multi-series chart has a legend and table view):

    | Preset / mode | Slot | Before    | After     |
    | ------------- | ---- | --------- | --------- |
    | Slate light   | 2    | `#8A8A92` | `#978DCB` |
    | Slate light   | 6    | `#D06A84` | `#D5677B` |
    | Slate dark    | 2    | `#A3A3AB` | `#CDB3EA` |
    | Slate dark    | 6    | `#DD8BA0` | `#EC8A87` |
    | Calm dark     | 2    | `#5BBCD8` | `#53BFCE` |
    | Calm dark     | 6    | `#E38EAE` | `#CE8ED3` |
    | Warm dark     | 5    | `#E08FAA` | `#EF8D9A` |

    Operator passes unchanged. Note for Themes: in every preset slots 1 and 4 (blue/indigo and violet) are hard
    to tell apart as a non-adjacent pair; that matters only for charts where any series can sit next to any
    other (scatter, maps), which the admin does not have.

- Touch targets ≥ 24px; list rows are full-width links.

## 10. Decisions (CTO, 2026-10-18)

1. **Automation on the dashboard:** one System health row; the full per-task list stays on the Automation
   page, one click away.
2. **Reporting currency:** default to the default currency; "All" is an explicit estimate at today's rates
   (stored per-transaction rates to the default currency do not exist yet; see section 6).
3. **Export:** waits for the Reports module (roadmap 3.8).
4. **Chart colours:** Slate's grey replaced with a desaturated blue-violet; all four presets checked the same
   way and fixed where an adjacent pair failed (section 9).
5. **Counter strip:** exact duplicates of Needs attention rows removed from the strip (section 5.2; mapping
   in section 3).
6. **NPR symbol:** "Rs" (product standards), as a client-side stopgap in `lib/money.ts` until the Currencies
   workstream makes the configured prefix the single source.

## 11. Automation page (owner addition, 2026-10-18)

`admin.platform.automation.index` opens with a summary built from the same data as the dashboard's
automation health (Platform's `AutomationInsights`: one query over 30 days of `automation_runs` plus the
scheduler heartbeat). The task list and run log below it are unchanged, so nothing is lost.

**Scheduler banner.** When the heartbeat is late (5–60 minutes) or stopped (over an hour, or never seen while
runs exist), a banner above everything says so in plain words, what stops working (renewals, reminders, late
fees, suspensions), and shows the cron entry with a Copy button. Warning tone for late, danger for stopped.
This answers the most common WHMCS complaint in the parity study: cron failing silently.

**Empty state.** With no runs ever recorded, the summary is replaced by three set-up steps: add the cron entry
(shown with Copy), check the daily time (Settings → Company), and run the pipeline once (button, when the user
may run it).

**Summary cards** (`StatCard`, five across at `xl`):

| Card                  | Value                                  | Context / trend                                                |
| --------------------- | -------------------------------------- | -------------------------------------------------------------- |
| Scheduler             | Running / Late / Stopped / Not running | "Last tick 2 minutes ago"; tone warning or danger              |
| Success rate, 7 days  | succeeded ÷ recorded runs              | "182 of 182 runs"; change vs. the 7 days before in points      |
| Failed, last 24 hours | count                                  | links to the log filtered to failed runs; danger tone when > 0 |
| Next scheduled run    | "03:00"                                | "Tomorrow · in 21 hours"                                       |
| Average run time      | pipeline time per day, last 7 days     | change vs. the 7 days before (up is bad); 14-day sparkline     |

**Runs per day** (30 days): stacked columns of tasks that succeeded (`--success`), failed (`--danger`) and
did not run (`--muted-foreground`). The series are themselves statuses, so the reserved status colours are
correct here (with a legend and table view). "Did not run" is registered tasks minus recorded runs: a day the
scheduler missed shows as a full grey column, which is the silent-failure signal made visible.

**Processed today:** items each stage reported today (tasks return counts such as "12" or "3 invoices"),
with the number of tasks per stage. **Time per stage:** average time per day over 7 days, as one-colour
meters relative to the slowest stage, in three columns.

Inventory of the existing page → placement: header and "Run today's pipeline" (kept), Tasks table with stage,
last status and Run now (kept), Run log with task/status filters and pagination (kept), the run confirmation
dialog with the force option (kept).
