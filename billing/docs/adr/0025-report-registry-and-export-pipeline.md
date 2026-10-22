# ADR 0025 — Report registry and one export pipeline

- Status: Proposed · 2026-10-22 · Owner: Principal architect.
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

- Optional capabilities follow ADR 0012:
    - `ProvidesTotals`: footer and "return figures" rows;
    - `ProvidesHeaderBlock`: PAN, legal name, period;
    - `ProvidesLayouts`: named column sets, such as `detail` and `ird` with the template's Nepali headings;
    - `ProvidesPdfView`: a custom Blade view.
- The DTOs live in `App\Data\Reports`: `ReportColumn` (key, label, `ColumnType` enum), `ReportFilter` and
  `ReportQuery` (validated filters, the acting user id, and the company period resolved with `CompanyClock`).
  `ColumnType` is a closed kernel enum: `text`, `money`, `integer`, `decimal`, `date`, `instant`.
- `App\Support\Reports\ReportRegistry` is `final` and bound in `AppServiceProvider`. Owners call
  `app(ReportRegistry::class)->register(VatRegisterReport::class)` from their service provider. A report whose owner
  module is filtered out (`MODULES=`) does not exist. Keys are unique; a duplicate key fails at boot.

### 2. Who renders what

- The owner computes the rows. A feature module reads only its own tables. Reports, as the read model, may register
  reports that span modules (income, top clients), reading through other modules' models as `architecture.md` allows.
  Every report, whoever owns it, goes through the same registry and pipeline.
- Until the Reports module exists, the owner also renders the report's screen (for example Billing → VAT register),
  using the registry's rows and columns.
- When the Reports module arrives, it adds the library page, saved filters and the dashboard links on top of the same
  registry. Owners do not change.

### 3. One export pipeline

- `App\Support\Reports\ReportExporter::export(string $key, ReportQuery, ExportFormat $format, ?string $layout)` is
  the only export path. Formats are `csv` and `pdf`. XLSX is not included (see the open questions).
- **CSV.**
    - Written by `App\Support\Csv\SafeCsv`, a stream writer that fixes the comma separator, the quoting and the
      optional BOM.
    - The formula guard moves there from Migration, and `ErrorsCsv` switches to it in the same PR.
    - The guard applies to **text cells only**. Money, integer, decimal and date cells are formatted by the pipeline
      from typed values (money as a decimal string plus a currency column, ISO-8601 dates), so a negative amount is
      never prefixed.
    - A report may default its own BOM. The VAT register does, so Excel reads Nepali names. `reports.csv_bom` applies
      otherwise.
- **PDF.** A kernel view (`resources/views/reports/pdf.blade.php`), rendered through the dompdf dependency already
  installed. A4 landscape. It shows the header block, page totals and grand totals, "Printed by <staff> at <company
  time>", and the brand from `BrandProvider`. `ProvidesPdfView` replaces only the table part of the page.
- **Sync or queued.**
    - Up to `reports.sync_export_max_rows` (5,000 by default, from `count()`), the export is streamed in the response
      from a lazy cursor.
    - Above that, `ExportReportJob` runs on the `default` queue. It is `ShouldBeUnique` on the export id, and its
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

- A report can mark its export **sensitive**, which requires step-up (ADR 0018), when the export carries personal or
  tax identifiers in bulk. The VAT register's export is sensitive.
- The seeded roles grant report permissions through the owner's seeder. Full Administrator holds them all.

### 5. Audit

The pipeline writes one `report.exported` activity entry per export, sync or queued, through Core's `ActivityLogger`.
The entry records the key, layout, format, filters and row count. Viewing a report is not logged.

### 6. Public surface

The contract, its capabilities, the DTOs, `ColumnType`, the registry's `register()` and `SafeCsv` (`@api`) are public
under ADR 0010. A package can therefore ship a report without editing core, which closes the extension-model gap for
reports ("Report modules" in `billing/docs/parity/developer-architecture.md`).

## Open questions

- **D4:** XLSX in the IRD template needs a spreadsheet package, which requires approval and the cooldown (ADR 0007),
  and the accountant's answer on whether hosts upload the register (spec A2). If the answer is yes, XLSX becomes a
  third `ExportFormat` behind the same pipeline.
- Whether the IRD portal expects a particular file layout. The `ird` layout follows the template as read (spec F2);
  an answer only changes that layout.

## Consequences

- The register is built with PR 0 of the #327 split: the kernel seam, `SafeCsv`, and the move out of Migration. Each
  later report reuses it, and the Reports module becomes a UI module rather than an owner of queries.
- Core gains one table and one cleanup task. The kernel gains one PDF view.
- The spec's permission keys change to `reports.vat_register.*`. The spec author adopts this in #327.

## Alternatives considered

- **A Billing-local export for the register:** quickest, but a second pipeline would arrive with Reports, with its own
  guard and audit. Rejected.
- **Only the Reports read model defines reports**, as `architecture.md` permits: one owner, but packages could not add
  reports, and a compliance report would live away from the documents it certifies. Rejected as the only path; kept
  for cross-module reports.
- **Keeping the guard where it is:** the only copy is in Migration, which other modules cannot import, so every new
  export would copy it again, `-` rule included. Rejected.
- **Laravel Excel for every format:** one library, but a new dependency for CSV, which needs none. Deferred until D4.
