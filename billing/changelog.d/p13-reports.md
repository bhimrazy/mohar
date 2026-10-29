### Fixed

- **A report PDF with a very long header no longer prints an extra page (#367).** A header value is cut at three lines with an ellipsis, and a header block takes at most half a page; entries beyond that are replaced by one note saying how many were left out.
- **An export's password confirmation is now checked for the same person whose permission is checked (#354).** A confirmed session of another user no longer lets a sensitive report export for the query's user. Upgrade note: callers that export with no request must pass the confirmation time as the new `passwordConfirmedAt` argument of `ReportExporter::export()`.

### Changed

- **Report pages have a query budget, and the conformance kit ships with the app (#431).** One screen page of a report, totals included, may run at most 12 queries and no statement once per row. Report packages can run the same checks with `App\Support\Reports\ReportConformance::assertPages()` and `assertQueryBudget()`; the class moved from `tests/Support/Reports`.
