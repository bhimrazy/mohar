<?php

use App\Support\CalendarDate;
use Carbon\CarbonImmutable;

/*
 * The kernel's calendar date (ADR 0013 Amendment (#410)): Y-m-d only, compared by value, with months clamped to month end.
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
})->with(['2026-02-30', '2026-13-01', '2026-1-5', '2026-10-23 00:00:00', '2026-10-23T00:00:00+05:45', 'today', '0000-01-01', '', "2026-10-19\n"]);

test('formats with date characters and escaped letters only', function () {
    $date = CalendarDate::parse('2026-10-19');

    expect($date->format('D, j F Y'))->toBe('Mon, 19 October 2026')
        ->and($date->format('Y/m/d \\o\\n l'))->toBe('2026/10/19 on Monday')
        ->and($date->format('N W z t L o y n M S'))->toBe('1 43 291 31 0 2026 26 10 Oct th');
});

test('a multibyte literal is not a format character, in any locale', function () {
    $date = CalendarDate::parse('2026-10-19');
    $locale = setlocale(LC_CTYPE, 0);
    // ctype_alpha() follows the locale; an ASCII-only check must not.
    setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'en_US.ISO-8859-1');

    try {
        expect($date->format('j F Y, वार l'))->toBe('19 October 2026, वार Monday')
            ->and($date->format('Y मिति'))->toBe('2026 मिति')
            ->and(fn () => $date->format('Y वार H'))->toThrow(InvalidArgumentException::class);
    } finally {
        setlocale(LC_CTYPE, $locale);
    }
});

test('refuses a format character that prints a time, a zone or an instant', function (string $format) {
    expect(fn () => CalendarDate::parse('2026-10-19')->format($format))->toThrow(InvalidArgumentException::class);
})->with(['U', 'c', 'r', 'H', 'e', 'Y-m-d H:i', 'P', 'T', 'u', 'G']);

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

test('reads a date value (00:00 UTC) and refuses any other time or offset', function () {
    expect(CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-19', 'UTC'))->toString())->toBe('2026-10-19')
        // `CompanyClock::today()` is an instant (00:00 in Kathmandu), not a date value.
        ->and(fn () => CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-19 00:00', 'Asia/Kathmandu')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CalendarDate::fromDateValue(CarbonImmutable::parse('2026-10-18 18:15:00', 'UTC')))->toThrow(InvalidArgumentException::class);
});

test('serializes by value and validates on unserialize', function () {
    $payload = serialize(CalendarDate::parse('2026-02-28'));

    expect(unserialize($payload))->toEqual(CalendarDate::parse('2026-02-28'))
        ->and(fn () => unserialize(str_replace('i:28;', 'i:31;', $payload)))->toThrow(InvalidArgumentException::class);
});
