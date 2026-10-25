<?php

namespace App\Modules\Billing\Support;

use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use OutOfRangeException;

/**
 * Converts calendar dates between the Gregorian calendar (AD) and Bikram Sambat (BS), Nepal's official calendar.
 *
 * BS month lengths follow the published panchang and cannot be computed, so they come from the table below
 * (1 Baisakh 2000 BS = 14 April 1943 AD). Dates outside 2000–2095 BS are refused with an OutOfRangeException.
 * Pass calendar dates (date-cast fields such as an invoice's issue_date): the object's own year, month and day are
 * used, so a UTC timestamp converts the UTC day, which can differ from the day in Nepal.
 *
 * Source of the table (names, licences and notices: THIRD_PARTY_NOTICES.md at the repository root): the BS calendar
 * data of nepali-datetime (github.com/amitgaru/nepali-datetime, nepali_datetime/data/calendar_bs.csv) and
 * nepali-date-converter (github.com/sbmdkl/nepali-date-converter, src/config.ts), compared year by year on
 * 2026-10-22. They agree on every year from 2000 to 2098 except 2062 and 2087, where this table follows
 * nepali-date-converter (a third table, github.com/ernilambar/nepali-date, agrees with it); they also differ on 2099.
 * The table stops at 2095: both sources give 2096 only 364 days, which no BS year has, so their rows from 2096 on
 * cannot be trusted. Years after the current panchang (about 2084 onwards) are projections that may change when the
 * official calendar for that year is published; correct the row here if it does. Reference dates checked against
 * published calendars are in tests/Unit/Billing/BikramSambatTest.php.
 */
final class BikramSambat
{
    public const FIRST_YEAR = 2000;

    public const LAST_YEAR = 2095;

    /** English transliterations, Baisakh (1) to Chaitra (12). */
    public const MONTHS = ['Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Asoj', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];

    /** 1 Baisakh 2000 BS. */
    private const EPOCH = '1943-04-14';

    /** Days in each month, Baisakh to Chaitra, per BS year. */
    private const DAYS = [
        2000 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2001 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2002 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2003 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2004 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2005 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2006 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2007 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2008 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2009 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2010 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2011 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2012 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2013 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2014 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2015 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2016 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2017 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2018 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2019 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2020 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2021 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2022 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2023 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2024 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2025 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2026 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2027 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2028 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2029 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2030 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2031 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2032 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2033 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2034 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2035 => [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2036 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2037 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2038 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2039 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2040 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2041 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2042 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2043 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2044 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2045 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2046 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2047 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2048 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2049 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2050 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2051 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2052 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2053 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2054 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2055 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2056 => [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2057 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2058 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2059 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2060 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2061 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2062 => [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31],
        2063 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2064 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2065 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2066 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2067 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2068 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2069 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2070 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2071 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2072 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2073 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2074 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2076 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2077 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2078 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2079 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2081 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2082 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2083 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2084 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2086 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2087 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2088 => [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2089 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2090 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2091 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2092 => [30, 31, 32, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2093 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2094 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2095 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 30, 30, 30],
    ];

    /**
     * The BS date of an AD calendar date. A DateTimeInterface is read by its own year, month and day (time and zone
     * ignored); new callers pass a CalendarDate.
     */
    public static function fromAd(CalendarDate|DateTimeInterface $date): BikramSambatDate
    {
        $remaining = self::daysSinceEpoch($date->format('Y-m-d'));

        if ($remaining >= 0) {
            foreach (self::DAYS as $year => $months) {
                $length = array_sum($months);

                if ($remaining >= $length) {
                    $remaining -= $length;

                    continue;
                }

                foreach ($months as $index => $days) {
                    if ($remaining < $days) {
                        return new BikramSambatDate($year, $index + 1, $remaining + 1);
                    }

                    $remaining -= $days;
                }
            }
        }

        throw new OutOfRangeException(sprintf('%s is outside the Bikram Sambat table (%d to %d BS).', $date->format('Y-m-d'), self::FIRST_YEAR, self::LAST_YEAR));
    }

    /** The AD calendar date (midnight UTC) of a BS date. */
    public static function toAd(int $year, int $month, int $day): CarbonImmutable
    {
        if ($day < 1 || $day > self::daysInMonth($year, $month)) {
            throw new OutOfRangeException(sprintf('%d-%02d-%02d BS is not a date: %s %d has %d days.', $year, $month, $day, self::MONTHS[$month - 1], $year, self::daysInMonth($year, $month)));
        }

        $days = $day - 1;

        for ($y = self::FIRST_YEAR; $y < $year; $y++) {
            $days += array_sum(self::DAYS[$y]);
        }

        for ($m = 1; $m < $month; $m++) {
            $days += self::DAYS[$year][$m - 1];
        }

        return CarbonImmutable::parse(self::EPOCH, 'UTC')->addDays($days);
    }

    /**
     * The Nepali fiscal year that holds a BS date, in the form the tax office uses: "2083.084" for 1 Shrawan 2083 to
     * the end of Asar 2084. The year starts on 1 Shrawan (month 4), so Baisakh to Asar belong to the year before.
     */
    public static function fiscalYear(BikramSambatDate $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%d.%03d', $start, ($start + 1) % 1000);
    }

    /** The BS month as a sortable key, "2083-07" for Kartik 2083: the VAT period of a document. */
    public static function period(BikramSambatDate $date): string
    {
        return sprintf('%d-%02d', $date->year, $date->month);
    }

    public static function daysInMonth(int $year, int $month): int
    {
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR || $month < 1 || $month > 12) {
            throw new OutOfRangeException(sprintf('%d-%02d BS is outside the Bikram Sambat table (%d to %d BS).', $year, $month, self::FIRST_YEAR, self::LAST_YEAR));
        }

        return self::DAYS[$year][$month - 1];
    }

    private static function daysSinceEpoch(string $date): int
    {
        $epoch = CarbonImmutable::parse(self::EPOCH, 'UTC');

        return intdiv(CarbonImmutable::parse($date, 'UTC')->getTimestamp() - $epoch->getTimestamp(), 86_400);
    }
}
