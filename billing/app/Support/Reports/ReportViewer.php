<?php

namespace App\Support\Reports;

use App\Contracts\Reports\ProvidesTotals;
use App\Data\Reports\ReportPage;
use App\Data\Reports\ReportQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The on-screen entry point for a report (the export path is ReportExporter). page() checks `reports.{key}.view` for
 * the query's user, bounds the page (1 to ReportQuery::MAX_LIMIT rows, offset up to ReportQuery::MAX_OFFSET) and
 * returns the page without ever holding more than `limit` rows, whatever the report yields. A report that provides
 * totals gets them on the page, computed on the unpaged query, so a screen never calls the report itself.
 *
 * Paging on screen is not an export (ADR 0025, Amendment 1): it needs only the view permission, so it has no step-up,
 * row cap or `report.exported` entry. The screen's route is rate-limited instead.
 *
 * @api
 */
final class ReportViewer
{
    public function __construct(private ReportRegistry $reports) {}

    /**
     * @throws AuthorizationException when the acting user may not view the report
     * @throws ValidationException when the report is unknown or the limit or offset is out of bounds
     */
    public function page(string $key, ReportQuery $query, int $limit, int $offset = 0): ReportPage
    {
        if (! $this->reports->has($key)) {
            throw ValidationException::withMessages(['report' => __('This report does not exist.')]);
        }

        ReportAccess::authorize($key, $query, 'view', __('You do not have permission to view this report.'));

        $errors = [];

        if ($limit < 1 || $limit > ReportQuery::MAX_LIMIT) {
            $errors['limit'] = __('Show 1 to :max rows per page.', ['max' => ReportQuery::MAX_LIMIT]);
        }

        if ($offset < 0 || $offset > ReportQuery::MAX_OFFSET) {
            $errors['offset'] = __('Choose a page within the first :max rows; narrow the filters to reach later rows.', ['max' => number_format(ReportQuery::MAX_OFFSET)]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $paged = $query->page($limit, $offset);
        $report = $this->reports->get($key);
        $rows = [];

        foreach ($report->rows($paged) as $row) {
            if (count($rows) >= $limit) {
                break;
            }

            $rows[] = $row;
        }

        $unpaged = $query->unpaged();

        return new ReportPage(
            $report->columns($paged),
            $rows,
            $report->count($unpaged),
            $limit,
            $offset,
            $report instanceof ProvidesTotals ? $report->totals($unpaged) : [],
        );
    }
}
