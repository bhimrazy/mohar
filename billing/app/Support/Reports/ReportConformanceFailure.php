<?php

namespace App\Support\Reports;

use AssertionError;

/**
 * Thrown by ReportConformance when a report breaks the paging or query-budget rules. It is an AssertionError, so
 * PHPUnit and Pest report it as a failed test without the shipped kit depending on PHPUnit.
 *
 * @api
 */
final class ReportConformanceFailure extends AssertionError {}
