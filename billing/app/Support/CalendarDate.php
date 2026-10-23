<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A company-calendar date (ADR 0013 Amendment 2): a year, month and day with no time and no time zone. Due dates,
 * run dates, issue dates and report periods are calendar dates. It becomes an instant only through
 * `CompanyClock::dayBounds()`; it has no time, so it never compares with a timestamp.
 *
 * Adding months or years keeps the day of the month and clamps it to the last day of a shorter month: 31 Jan + 1 month
 * is 28 or 29 Feb, and 29 Feb + 1 year is 28 Feb (the rule of `BillingCycle::advance`).
 *
 * @api
 */
final readonly class CalendarDate implements JsonSerializable, Stringable
{
    private function __construct(public int $year, public int $month, public int $day) {}

    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1 || $year > 9999 || ! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('%04d-%02d-%02d is not a calendar date.', $year, $month, $day));
        }

        return new self($year, $month, $day);
    }

    /** Parses exactly `Y-m-d`; anything else (a time, a zone, "today") is refused. */
    public static function parse(string $date): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$date}\" is not a Y-m-d date.");
        }

        return self::of((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /**
     * The date of a date value: a `date`-cast attribute, `CompanyClock::todayDate()` or `today()`. Its own Y-m-d is
     * read, so a value with a time of day is refused; for an instant use `CompanyClock::localDate()`.
     */
    public static function fromDateValue(DateTimeInterface $value): self
    {
        if ($value->format('H:i:s.u') !== '00:00:00.000000') {
            throw new InvalidArgumentException('A date value has no time of day; pass an instant through CompanyClock::localDate().');
        }

        return self::parse($value->format('Y-m-d'));
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /** Formats with date characters (`d M Y`); time and zone characters print midnight UTC. */
    public function format(string $format): string
    {
        return $this->utcMidnight()->format($format);
    }

    public function compare(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isBefore(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /** Days from this date to `$other`: positive when `$other` is later. */
    public function daysUntil(self $other): int
    {
        return intdiv($other->utcMidnight()->getTimestamp() - $this->utcMidnight()->getTimestamp(), 86_400);
    }

    public function addDays(int $days): self
    {
        return self::fromUtc($this->utcMidnight()->modify(sprintf('%+d days', $days)));
    }

    /** Clamps the day to the end of a shorter month (see the class docblock). */
    public function addMonths(int $months): self
    {
        $index = $this->year * 12 + ($this->month - 1) + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;

        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC')))->format('t');

        return self::of($year, $month, min($this->day, $daysInMonth));
    }

    public function addYears(int $years): self
    {
        return $this->addMonths($years * 12);
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function utcMidnight(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toString(), new DateTimeZone('UTC'));
    }

    private static function fromUtc(DateTimeImmutable $date): self
    {
        return self::of((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
    }
}
