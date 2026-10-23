### Report page totals and paging rules (#428, closes #403)

- `App\Data\Reports\ReportPage` has a new last constructor parameter `array $totals = []`. `ReportViewer::page()`
  fills it from `ProvidesTotals::totals()`, called with the unpaged query, so a screen never calls the report itself.
  Additive: existing callers pass nothing.
- `ProvidesTotals::totals()` is documented to receive the unpaged query (no limit or offset), from the viewer and from
  the exporter.
- `Report::rows()`: a paged read uses `orderBy()` in a total order, then `offset`, `limit` and `get()`; `lazyById()`
  and `lazy()` are for unpaged reads only. `Report::count()` runs uncached on every screen page and must be
  index-backed. Documentation only.
- `ReportQuery::page()` and `unpaged()` copy every field by name through one private `with()`. No behaviour change.
- New internal `App\Support\Reports\ReportAccess::authorize()`: the one user lookup and permission check of the viewer
  and the exporter. Messages and exceptions are unchanged.
- New test helper `Tests\Support\Reports\ReportConformance::assertPages()`; `tests/Arch/ReportsTest.php` fails when a
  report in `app/` has no test that calls it.
