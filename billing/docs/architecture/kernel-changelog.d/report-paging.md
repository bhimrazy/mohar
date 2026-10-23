### Report paging and `ReportViewer` (#355)

- `ReportQuery` has two new trailing constructor parameters, `?int $limit = null` and `int $offset = 0`, with
  `MAX_LIMIT` (200) and `MAX_OFFSET` (100,000); an out-of-range value throws `InvalidArgumentException`. New
  `page(limit, offset)` and `unpaged()` return copies. Additive: existing callers pass neither and get all rows.
- `Report::rows()` must return only the requested page when `limit` is set (skip `offset`, yield at most `limit`) in a
  total order. `count()` is called on an unpaged query. The viewer never returns more than `limit` rows, but a report
  that ignores the page would repeat page 1 as page 2: `ReportConformance::assertPages()` checks every report (#403).
- New `App\Support\Reports\ReportViewer::page(key, query, limit, offset): ReportPage` checks `reports.{key}.view` for
  `query->userId`, validates the bounds and returns `App\Data\Reports\ReportPage` (columns, rows, total, limit, offset).
- `ReportExporter::export()` calls `unpaged()` on its query, so exports are unaffected by a paged query.
