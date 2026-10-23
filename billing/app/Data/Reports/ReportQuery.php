<?php

namespace App\Data\Reports;

use App\Contracts\Core\CompanyClock;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one run of a report asks for: the validated filters, the acting user and the period resolved on the company
 * calendar (ADR 0013). `from` and `to` are company dates (both inclusive; CalendarDate since Amendment (#410));
 * `startsAt` and `endsAt` are the UTC instants bounding them, [start, end), for querying timestamp columns.
 *
 * `limit` and `offset` select one page of `Report::rows()` for an on-screen view (ReportViewer); a null limit means
 * all rows, which is what exports use. A limit is 1 to MAX_LIMIT and an offset 0 to MAX_OFFSET.
 *
 * @api
 */
final readonly class ReportQuery
{
    public const MAX_LIMIT = 200;

    public const MAX_OFFSET = 100_000;

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
        public ?int $limit = null,
        public int $offset = 0,
    ) {
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new InvalidArgumentException('A report page holds 1 to '.self::MAX_LIMIT.' rows.');
        }

        if ($offset < 0 || $offset > self::MAX_OFFSET || ($limit === null && $offset !== 0)) {
            throw new InvalidArgumentException('A report offset is 0 to '.self::MAX_OFFSET.' and needs a limit.');
        }
    }

    public function page(int $limit, int $offset): self
    {
        return new self($this->filters, $this->userId, $this->from, $this->to, $this->startsAt, $this->endsAt, $limit, $offset);
    }

    public function unpaged(): self
    {
        return new self($this->filters, $this->userId, $this->from, $this->to, $this->startsAt, $this->endsAt);
    }

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
