<?php

namespace App\Modules\Core\Support;

use App\Support\CalendarDate;

/**
 * How long automation runs are kept (#49). A run row is the (run date, task) key that stops a date running twice
 * (ADR 0013), so `automation:run` refuses a date older than the oldest one kept, and the prune never removes a date
 * that can still be run. The prune also records the last date it deleted through: that date and earlier stay refused
 * after the retention is raised again.
 */
final class AutomationLogRetention
{
    public const KEY = 'platform.automation_log_retention_days';

    public const DEFAULT_DAYS = 365;

    public const MIN_DAYS = 90;

    /** State, not a tunable: the last run date whose rows the prune deleted. */
    public const PRUNED_THROUGH = 'core.automation_log_pruned_through';

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

    /** Whether `automation:run` may run this date: inside the window and after anything already pruned. */
    public function mayRun(CalendarDate $date, CalendarDate $today): bool
    {
        $pruned = $this->prunedThrough();

        return ! $date->isBefore($this->oldestRunDate($today)) && ($pruned === null || $date->isAfter($pruned));
    }

    public function prunedThrough(): ?CalendarDate
    {
        $value = $this->settings->get(self::PRUNED_THROUGH);

        return is_string($value) && $value !== '' ? CalendarDate::parse($value) : null;
    }

    /** Called by the prune after it deleted runs before $oldestKept; never moves back. */
    public function markPrunedBefore(CalendarDate $oldestKept): void
    {
        $through = $oldestKept->addDays(-1);
        $current = $this->prunedThrough();

        if ($current === null || $through->isAfter($current)) {
            $this->settings->set(self::PRUNED_THROUGH, $through->toString());
        }
    }
}
