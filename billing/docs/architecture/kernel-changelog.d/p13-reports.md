### Report conformance kit moves to the kernel; step-up decided for the query's user (#515)

- `Tests\Support\Reports\ReportConformance` is now `App\Support\Reports\ReportConformance` (`@api`) and throws
  `App\Support\Reports\ReportConformanceFailure` instead of using PHPUnit assertions. New
  `assertQueryBudget(key, query, budget = ReportConformance::QUERY_BUDGET)` (ADR 0020 amendment). Packages that
  registered reports call both `assertPages()` and `assertQueryBudget()`; `tests/Arch/ReportsTest.php` requires both.
- `ReportExporter::export()` has a new trailing parameter `?int $passwordConfirmedAt = null`. Additive. A sensitive
  export is now confirmed only by that time or by the current request when it belongs to the query's user.
- New `RequireRecentPasswordConfirmation::confirmedFor(User, int $confirmedAt)`.
