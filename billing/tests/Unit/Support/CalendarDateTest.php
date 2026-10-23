<?php

use App\Support\CalendarDate;
use Carbon\CarbonImmutable;

/*
 * The kernel's calendar date (ADR 0013 Amendment 2): Y-m-d only, compared by value, with months clamped to month end.
 */

test('parses and prints exactly Y-m-d', function () {
    $date = CalendarDate::parse('2026-10-23');

    expect([$date->year, $date->month, $date->day])->toBe([2026, 10, 23])
        ->and($date->toString())->toBe('2026-10-23')
        ->and((string) $date)->toBe('2026-10-23')
        ->and(json_encode(['due' => $date]))->toBe('{"due":"2026-10-23"}')
        ->and($date->format('d M Y'))->toBe('23 Oct 2026');
});

test('refuses anything that is not a calendar date', function (string $input) {
    expect(fn () => CalendarDate::parse($input))->toThrow(InvalidArgumentException::class);
})->with(['2026-02-30', '2026-13-01', '2026-1-5', '2026-10-23 00:00:00', '2026-10-23T00:00:00+05:45', 'today', '0000-01-01', '']);

test('compares by value', function () {
    $a = CalendarDate::parse('2026-10-23');
    $b = CalendarDate::of(2026, 10, 23);
    $later = CalendarDate::parse('2027-01-02');

    expect($a->equals($b))->toBeTrue()
        ->and($a == $b)->toBeTrue()
        ->and($a->compare($later))->toBe(-1)
        ->and($later->compare($a))->toBe(1)
        ->and($a->isBefore($later))->toBeTrue()
        ->and($a->isAfter($later))->toBeFalse()
        ->and($a->daysUntil($later))->toBe(71)
        ->and($later->daysUntil($a))->toBe(-71);
});

test('adds days across month, year and leap-day boundaries', function (string $from, int $days, string $to) {
    expect(CalendarDate::parse($from)->addDays($days)->toString())->toBe($to);
})->with([
    ['2026-12-31', 1, '2027-01-01'],
    ['2028-02-28', 1, '2028-02-29'],
    ['2027-02-28', 1, '2027-03-01'],
    ['2026-03-01', -1, '2026-02-28'],
    // No DST: a day is a day in every zone.
    ['2026-03-07', 2, '2026-03-09'],
]);

test('clamps months and years to the end of a shorter month', function (string $from, string $method, int $amount, string $to) {
    expect(CalendarDate::parse($from)->{$method}($amount)->toString())->toBe($to);
})->with([
    'Jan 31 + 1 month' => ['2026-01-31', 'addMonths', 1, '2026-02-28'],
    'Jan 31 + 1 month in a leap year' => ['2028-01-31', 'addMonths', 1, '2028-02-29'],
    'Mar 31 - 1 month' => ['2026-03-31', 'addMonths', -1, '2026-02-28'],
    'Aug 31 + 1 month' => ['2026-08-31', 'addMonths', 1, '2026-09-30'],
    'Oct 31 + 4 months' => ['2026-10-31', 'addMonths', 4, '2027-02-28'],
    'Dec 15 + 1 month' => ['2026-12-15', 'addMonths', 1, '2027-01-15'],
    'Jan 15 - 1 month' => ['2027-01-15', 'addMonths', -1, '2026-12-15'],
    'Feb 29 + 1 year' => ['2028-02-29', 'addYears', 1, '2029-02-28'],
    'Feb 29 + 4 years' => ['2028-02-29', 'addYears', 4, '2032-02-29'],
    'Feb 29 - 1 year' => ['2028-02-29', 'addYears', -1, '2027-02-28'],
    'century not a leap year' => ['2096-02-29', 'addYears', 4, '2100-02-28'],
]);

test('does not drift when a clamped month is added again', function () {
    // The day is clamped once; it is not carried as 31.
    expect(CalendarDate::parse('2026-01-31')->addMonths(1)->addMonths(1)->toString())->toBe('2026-03-28')
        ->and(CalendarDate::parse('2026-01-31')->addMonths(2)->toString())->toBe('2026-03-31');
});

test('reads a date value but refuses an instant with a time of day', function () {
    expect(CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-19', 'UTC'))->toString())->toBe('2026-10-19')
        // `CompanyClock::today()`: 00:00 in the company zone is that zone's date.
        ->and(CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-19 00:00', 'Asia/Kathmandu'))->toString())->toBe('2026-10-19')
        ->and(fn () => CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-18 18:15:00', 'UTC')))->toThrow(InvalidArgumentException::class);
});
