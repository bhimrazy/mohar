<?php

namespace App\Support\Reports;

use App\Data\Reports\ReportPage;
use App\Data\Reports\ReportQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The on-screen entry point for a report (the export path is ReportExporter). page() checks `reports.{key}.view` for
 * the query's user, bounds the page (1 to ReportQuery::MAX_LIMIT rows, offset up to ReportQuery::MAX_OFFSET) and
 * returns the page without ever holding more than `limit` rows, whatever the report yields.
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

        $user = $query->userId === null ? null : User::query()->find($query->userId);

        if (! $user?->hasPermission("reports.{$key}.view")) {
            throw new AuthorizationException(__('You do not have permission to view this report.'));
        }

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

        return new ReportPage($report->columns($paged), $rows, $report->count($query->unpaged()), $limit, $offset);
    }
}
