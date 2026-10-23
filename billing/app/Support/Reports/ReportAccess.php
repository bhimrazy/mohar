<?php

namespace App\Support\Reports;

use App\Data\Reports\ReportQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one permission check shared by ReportViewer and ReportExporter: the query's user must hold
 * `reports.{key}.{ability}`. The step-up check for a sensitive export stays in the exporter; deciding both against one
 * stored identity for a queued export is #354.
 */
final class ReportAccess
{
    /**
     * @param  'view'|'export'  $ability
     *
     * @throws AuthorizationException
     */
    public static function authorize(string $key, ReportQuery $query, string $ability, string $message): User
    {
        $user = $query->userId === null ? null : User::query()->find($query->userId);

        if (! $user?->hasPermission("reports.{$key}.{$ability}")) {
            throw new AuthorizationException($message);
        }

        return $user;
    }
}
