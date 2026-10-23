# ADR 0025 — Report registry and one export pipeline

- Status: Accepted · 2026-10-23 (amended in PR #352 after review; first Proposed 2026-10-22; Amendment (#PR) on screen
  paging) · Owner: Principal architect.
- Related: `billing/docs/product/reports.md` §6–§7, `billing/docs/product/vat-register.md` (PR #327), ADR 0001
  (modules), ADR 0004 (objects disk), ADR 0010 (public API), ADR 0012 (capability interfaces and registries), ADR 0014
  (queues), ADR 0018 (step-up), ADR 0024 (fiscal documents).

## Context

- `reports.md` describes the reports as a library. Each report has a key, a category, filters, columns and a CSV
  export, and its permission is `reports.{key}.view`. The Reports module that would host them does not exist on
  `develop`.
- The first report that must ship is the VAT register. Billing owns it as a compliance document, and it reads only
  Billing's documents (ADR 0024). Billing may not call into Reports (ADR 0001). `architecture.md` lets Reports, as a
  read model, read other modules' tables, but that does not help a package's report or a module-owned compliance
  report.
- The CSV formula-injection guard lives in `Migration\Support\ErrorsCsv::safe()`, where no other module may use it.
  It also prefixes cells that start with `-`. A credit-note row of `-1300.00` would therefore be written as text,
  and a spreadsheet would no longer sum it.
- PDFs are rendered through dompdf inside Billing (`InvoicePdf`). The queued exports, signed download links and
  `report.exported` activity entries described in `reports.md` §7 do not exist yet.

Without a kernel seam, Billing would build its own export for the register, and the later Reports module would build
a second one.

## Decision

### 1. Registry and contract

- `App\Contracts\Reports\Report` is a provider contract (ADR 0010). It is implemented by the module that owns the
  data, or by a package:

    ```php
    interface Report
    {
        public function key(): string;                 // "vat_register"; at most 50 characters, unique
        public function label(): string;
        public function category(): string;            // "billing", "clients", ...
        /** @return list<ReportFilter> */
        public function filters(): array;
        /** @return list<ReportColumn> */
        public function columns(ReportQuery $query): array;
        /** @return iterable<array<string, scalar|Money|CarbonImmutable|null>> rows keyed by column key, lazily */
        public function rows(ReportQuery $query): iterable;
        public function count(ReportQuery $query): int;
    }
    ```

- Optional capabilities follow ADR 0012 (one capability per interface, detected with `instanceof`):
    - `HasSensitiveExport`: a marker with no methods (§4). It is a fact about the class, so the registry reads it
      without building the report;
    - `ProvidesCsvOptions`: `csvBom(): bool`, the report's own BOM default (§3);
    - `ProvidesTotals`: footer and "return figures" rows;
    - `ProvidesHeaderBlock`: PAN, legal name, period;
    - `ProvidesLayouts`: named column sets, such as `detail` and `ird` with the template's Nepali headings;
    - `ProvidesPdfView`: a custom Blade view.
- `rows()` is lazy, and reports use `lazyById()` or `lazy()` (on PostgreSQL `cursor()` holds the whole result in
  memory). A `money` cell is a `Money`; a `date` cell is a date value (00:00 UTC on that date, like a `date` cast), so
  an instant goes through `CompanyClock::localDate()` or uses an `instant` column.
- The DTOs live in `App\Data\Reports`: `ReportColumn` (key, label, `ColumnType` enum), `ReportFilter` and
  `ReportQuery` (validated filters, the acting user id, and the company period resolved with `CompanyClock`).
  `ColumnType` is a closed kernel enum: `text`, `money`, `integer`, `decimal`, `date`, `instant`. `FilterType` is an
  open enum: `date`, `text`, `select`, `boolean`. The pipeline derives each filter's base rules from its type (a
  date parses as Y-m-d, a select is one of its options, a text is a string of at most 255 characters, no value is an
  array) and refuses an unknown filter key, an unknown layout or an unknown report with a validation error. A
  `ReportFilter` declares whether its value may be written to the activity log (`loggable`; false for text).
- `App\Support\Reports\ReportRegistry` is `final`, bound in `AppServiceProvider`, and follows ADR 0012:
  `register(string $key, class-string|Closure $report, bool $replace = false)`, `has()`, `keys()` and `get()`.
  Owners call `app(ReportRegistry::class)->register('vat_register', VatRegisterReport::class)` from their service
  provider. `register()` builds nothing; it adds the two permission keys (§4) at once, with labels resolved when the
  permission list is read (so they are translated). `get()` builds the report on first use and checks that `key()`
  equals the registered key. A duplicate key throws unless `replace: true` (logged); a report whose owner module is
  filtered out (`MODULES=`) does not exist. A report with `HasSensitiveExport` is registered by class name, because a
  Closure cannot be inspected before it is built (`get()` refuses the mismatch).

### 2. Who renders what

- The owner computes the rows. A feature module reads only its own tables. Reports, as the read model, may register
  reports that span modules (income, top clients), reading through other modules' models as `architecture.md` allows.
  Every report, whoever owns it, goes through the same registry and pipeline.
- Until the Reports module exists, the owner also renders the report's screen (for example Billing → VAT register),
  reading it through `ReportViewer::page()` (Amendment (#PR) below).
- When the Reports module arrives, it adds the library page, saved filters and the dashboard links on top of the same
  registry. Owners do not change.

### 3. One export pipeline

- `App\Support\Reports\ReportExporter::export(string $key, ReportQuery, ExportFormat $format, ?string $layout)` is
  the only export path. `ExportFormat` is a kernel enum with the cases `csv` and `pdf` (D4, decided by the owner on
  2026-10-22). Each format is written by its own `ExportWriter`, selected by format. Adding a format means adding an
  enum case and a writer; reports, permissions, audit and queued delivery stay as they are. `ExportFormat` is
  documented `@open` (ADR 0010), so consumers keep a default branch.
- **CSV.**
    - Written by `App\Support\Csv\SafeCsv`, a stream writer that fixes the comma separator, the quoting and the
      optional BOM.
    - The formula guard moves there from Migration, and `ErrorsCsv` switches to it in the same PR.
    - The guard applies to **text cells only**. Money, integer, decimal and date cells are formatted by the pipeline
      from typed values (money as a decimal string plus a currency column, ISO-8601 dates), so a negative amount is
      never prefixed.
    - A `money` cell is the decimal string only (`-1300.00`). The pipeline never adds a currency column, because
      fixed layouts such as `ird` follow the IRD template exactly; a report that can mix currencies declares its own
      currency column.
    - A report may default its own BOM. The VAT register does, so Excel reads Nepali names. `reports.csv_bom` applies
      otherwise.
- **PDF.** A kernel view (`resources/views/reports/pdf.blade.php`), rendered through the dompdf dependency already
  installed. A4 landscape. It shows the header block, page totals and grand totals, "Printed by <staff> at <company
  time>", and the brand from `BrandProvider`. Amounts print through `MoneyFormatter` in the currency's configured
  display (`Rs 1,23,456.00` for NPR). Totals are kept per currency and never added across currencies: a page or
  document with several currencies gets one total line for each. `ProvidesPdfView` replaces only the table part of the page.
- **Sync now, queued later.**
    - Up to `reports.sync_export_max_rows` (5,000 by default, bound 100 to 50,000; from `count()`), a CSV is streamed
      in the response from a lazy cursor with constant memory. This is the only delivery path in PR 0c (CTO decision 7).
      The limit is hard: the row stream is wrapped in a counting generator that stops at the limit and logs a
      warning (report, counted, written), so a wrong `count()` cannot write past it.
    - A PDF is built in memory (about 70 KB a row; 500 rows measured +40 MB, 5,000 rows +303 MB against the 256 MB
      production `memory_limit`). It has its own limit, `reports.sync_pdf_max_rows` (default 1,000, bound 100 to
      2,000), refused with the same "narrow the filters" message, which also points to the CSV. The file is built
      completely before the response starts, so a failure is an error response, not a broken 200.
    - Until queued delivery exists, a larger export is refused with the row count and a request to narrow the
      filters. A monthly VAT register is far below the limit.
    - **Deferred to the Reports workstream.** The queued path below is decided now, so that it fits without changing
      the contract, but it is built with the Reports module. `ExportReportJob` runs on the `default` queue. It is `ShouldBeUnique` on the export id, and its
      timeout is the default pool's. A dedicated queue would require amending ADR 0014.
    - The job writes the file to the `objects` disk under `exports/<id>`, and records it in `report_exports`, a Core
      table: id, key, user, filters, format, rows, path, `expires_at`.
    - The staff member gets a signed download link that is valid 24 hours. At download, the link re-checks the owner
      and the permission.
    - A scheduled Core task deletes expired files and rows.

### 4. Permissions

- The registry registers two keys for each report in `PermissionRegistry`, group "Reports":
    - `reports.{key}.view`: see the screen and its rows;
    - `reports.{key}.export`: run any export.

    There is no second naming scheme: the VAT register uses `reports.vat_register.view` and
    `reports.vat_register.export`, not `billing.vat_register.*`.

- A report marks its export **sensitive** with the `HasSensitiveExport` marker (read from the class once, at registration), which requires step-up (ADR 0018), when the export carries personal or
  tax identifiers in bulk. The VAT register's export is sensitive.
- The seeded roles grant report permissions through the owner's seeder. Full Administrator holds them all.

### 5. Audit

The pipeline writes one `report.exported` activity entry per export, sync or queued, through Core's `ActivityLogger`.
The entry records the key, layout, format, row count and the filters that are loggable (other filter values are
replaced by `[not logged]`). Viewing a report is not logged.

- A CSV entry is written before the first byte is streamed, so an attempted export of tax data is on record even if
  the connection drops. It records `min(count, limit)`, the most that can be written (the activity log is
  append-only, so it cannot be corrected afterwards).
- A PDF entry is written after the file is built and records the rows actually written. A failed build writes no
  entry and returns an error.

### 6. Public surface

The contract, its capabilities, the DTOs, `ColumnType`, the registry's `register()` and `SafeCsv` (`@api`) are public
under ADR 0010. A package can therefore ship a report without editing core, which closes the extension-model gap for
reports ("Report modules" in `billing/docs/parity/developer-architecture.md`).

## Known limitations

- **Devanagari in PDF exports.** dompdf's bundled fonts have no Devanagari glyphs and dompdf does no OpenType
  shaping. Labels, headings, amounts and dates that Mohar prints are English or digits and are unaffected, but
  Devanagari text typed by users (a client name) shows as boxes in a PDF. It is a known limitation, decided by the
  owner on 2026-10-23 (no font and no PDF-engine change): use the CSV, which round-trips Devanagari (UTF-8, with a
  BOM for Excel). Layouts keep their PDF.

## Open questions

- **D4 is decided** (owner, 2026-10-22): CSV and PDF now. XLSX is added only once the IRD portal requires an upload.
  It then needs a spreadsheet package, with approval and the cooldown (ADR 0007). It is added as a third
  `ExportFormat` with its own writer, behind the same pipeline.
- Whether the IRD portal expects a particular file layout. The `ird` layout follows the template as read (spec F2);
  an answer only changes that layout.

## Consequences

- PR 0c of the build order (ADR 0024 §10) ships, just before the register screens:
    - the registry and the DTOs;
    - `SafeCsv`, with `ErrorsCsv` moved onto it;
    - the synchronous CSV and PDF writers.

    Each later report reuses it, and the Reports module becomes a UI module rather than an owner of queries.

- The kernel gains one PDF view. The `report_exports` table, the job, the signed links and the cleanup task come
  with the Reports module.
- The spec's permission keys change to `reports.vat_register.*`. The spec author adopts this in #327.

## Alternatives considered

- **A Billing-local export for the register:** quickest, but a second pipeline would arrive with Reports, with its own
  guard and audit. Rejected.
- **Only the Reports read model defines reports**, as `architecture.md` permits: one owner, but packages could not add
  reports, and a compliance report would live away from the documents it certifies. Rejected as the only path; kept
  for cross-module reports.
- **Keeping the guard where it is:** the only copy is in Migration, which other modules cannot import, so every new
  export would copy it again, `-` rule included. Rejected.
- **Laravel Excel for every format:** one library, but a new dependency for CSV, which needs none. Deferred: XLSX comes only once the IRD portal requires an upload (D4).

## Amendment (#PR) — 2026-10-23, on-screen paging: view versus export

Paging (#355, K3) added a second way to read a report's rows. This amendment states how it relates to exports, and
replaces the §2 text that has the owner render the screen "using the registry's rows and columns".

- **The screen reads through `ReportViewer::page()` only.** It checks `reports.{key}.view`, returns at most 200 rows
  from an offset of up to 100,000, the total from `count()`, and the footer from `ProvidesTotals::totals()` on the
  unpaged query (`ReportPage::$totals`). An owner's screen does not call the report's `rows()` or `totals()` itself;
  it may read its own module's data for things that are not report rows (for example the warnings above the VAT
  register, which come from the same period after `page()` has authorized).
- **Paging is viewing, not exporting.** A user with only the view permission can page through a whole report,
  including the bulk identifiers that make an export sensitive. This is by design: the view permission already grants
  the rows on screen. Paging therefore has no step-up, no export row cap and no `report.exported` entry (§5: viewing is
  not logged). What it does not give is a file: every file still goes through `ReportExporter` with
  `reports.{key}.export`, step-up for a sensitive report, the row cap and the audit entry.
- **Each screen route is rate-limited** per user, so the view permission cannot be used to pull a report page by page
  at machine speed. The VAT registers allow 60 requests a minute (`billing-registers`).
- **Every report pages conformantly.** A report that ignores `limit` and `offset` would show page 1 again as page 2;
  the viewer caps the row count but cannot detect the repeat. `tests/Support/Reports/ReportConformance` checks the
  pages against the unpaged rows, and `tests/Arch/ReportsTest.php` requires a test calling it for every report in
  `app/`. `count()` runs on every page without a cache, so it must be served by an index.
