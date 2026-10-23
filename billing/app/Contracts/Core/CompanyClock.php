<?php

namespace App\Contracts\Core;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The company's calendar (setting `system.timezone`), the only source of "today" for documents and automation
 * (ADR 0013). Timestamps are stored in UTC; a calendar date (issue date, due date, run date) is produced by this
 * clock or by arithmetic on another calendar date, never by `CarbonImmutable::today()` or `now()->toDateString()`.
 */
interface CompanyClock
{
    /** The company's time zone, a valid IANA identifier. */
    public function timezone(): string;

    /** Now, in the company time zone. */
    public function now(): CarbonImmutable;

    /**
     * The start of the company's day today: 00:00 in the company zone, a real instant. Its `toDateString()` is the
     * company date. Never compare it with a date value or a `date`-cast attribute (`lt`, `gt`, `diffInDays`): use
     * todayDate(), or compare `toDateString()`. To turn a company date into instants, use dayBounds().
     */
    public function today(): CarbonImmutable;

    /**
     * The company date today as a date value: 00:00 UTC on that date, the same shape as a `date` cast and as the run
     * date tasks receive. Compare it only with date columns and other date values; to get instants use dayBounds().
     */
    public function todayDate(): CarbonImmutable;

    /**
     * The UTC instants that bound one company-calendar day: [start, end), for querying timestamp columns. A
     * CarbonInterface is read by its own Y-m-d (a calendar date), not converted: pass an instant through localDate().
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayBounds(string|CarbonInterface $date): array;

    /** True when the stored company time zone is not valid and dates use UTC (fiscal documents must refuse then). */
    public function isFallback(): bool;

    /** The company-calendar date (Y-m-d) of an instant. */
    public function localDate(CarbonInterface $instant): string;
}
