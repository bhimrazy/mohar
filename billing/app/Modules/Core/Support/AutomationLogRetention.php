<?php

namespace App\Modules\Core\Support;

use App\Support\CalendarDate;

/**
 * How long automation runs are kept (#49). A run row is the (run date, task) key that stops a date running twice
 * (ADR 0013), so `automation:run` refuses a date older than the oldest one kept, and the prune never removes a date
 * that can still be run.
 */
final class AutomationLogRetention
{
    public const KEY = 'platform.automation_log_retention_days';

    public const DEFAULT_DAYS = 365;

    public const MIN_DAYS = 90;

    public function __construct(private Settings $settings) {}

    public function days(): int
    {
        return max(self::MIN_DAYS, (int) $this->settings->get(self::KEY, self::DEFAULT_DAYS));
    }

    /** The oldest run date still kept, and so the oldest date that may be run. */
    public function oldestRunDate(CalendarDate $today): CalendarDate
    {
        return $today->addDays(-$this->days());
    }
}
