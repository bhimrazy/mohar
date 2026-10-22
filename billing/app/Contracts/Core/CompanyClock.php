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
     * company date. To turn a company date into an instant, use dayBounds(); to compare it with a date column
     * (a UTC-midnight value), compare date strings.
     */
    public function today(): CarbonImmutable;

    /**
     * The UTC instants that bound one company-calendar day: [start, end), for querying timestamp columns. A
     * CarbonInterface is read by its own Y-m-d (a calendar date), not converted: pass an instant through localDate().
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayBounds(string|CarbonInterface $date): array;

    /** The company-calendar date (Y-m-d) of an instant. */
    public function localDate(CarbonInterface $instant): string;
}
