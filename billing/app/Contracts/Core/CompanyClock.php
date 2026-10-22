<?php

namespace App\Contracts\Core;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The company's calendar (setting `system.timezone`), the only source of "today" for documents and automation
 * (ADR 0013). Timestamps are stored in UTC; a calendar date (issue date, due date, run date) is produced by this
 * clock or by arithmetic on another calendar date, never by `CarbonImmutable::today()` or `now()->toDateString()`.
 *
 * @api
 */
interface CompanyClock
{
    /** The company's time zone identifier; a stored value that is not a valid identifier falls back to UTC. */
    public function timezone(): string;

    /** Now, in the company time zone. */
    public function now(): CarbonImmutable;

    /** The company's calendar date today, as a date value at 00:00 (the time zone is not meaningful). */
    public function today(): CarbonImmutable;

    /**
     * The UTC instants that bound one company-calendar day: [start, end), for querying timestamp columns.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayBounds(string|CarbonInterface $date): array;

    /** The company-calendar date (Y-m-d) of an instant. */
    public function localDate(CarbonInterface $instant): string;
}
