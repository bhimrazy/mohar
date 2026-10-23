<?php

namespace App\Data\Reports;

use App\Contracts\Core\CompanyClock;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;

/**
 * What one run of a report asks for: the validated filters, the acting user and the period resolved on the company
 * calendar (ADR 0013). `from` and `to` are company dates (both inclusive; CalendarDate since Amendment 2); `startsAt`
 * and `endsAt` are the UTC instants bounding them, [start, end), for querying timestamp columns.
 *
 * @api
 */
final readonly class ReportQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public array $filters,
        public ?int $userId,
        public ?CalendarDate $from = null,
        public ?CalendarDate $to = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
    ) {}

    /**
     * Builds the query from validated filters; the `from` and `to` filters, when present, become the period.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function resolve(array $filters, ?int $userId, CompanyClock $clock): self
    {
        $from = isset($filters['from']) && $filters['from'] !== '' ? CalendarDate::parse((string) $filters['from']) : null;
        $to = isset($filters['to']) && $filters['to'] !== '' ? CalendarDate::parse((string) $filters['to']) : null;

        return new self(
            $filters,
            $userId,
            $from,
            $to,
            $from === null ? null : $clock->dayBounds($from)[0],
            $to === null ? null : $clock->dayBounds($to)[1],
        );
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }
}
