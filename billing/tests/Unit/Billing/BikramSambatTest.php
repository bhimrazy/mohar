<?php

use App\Modules\Billing\Support\BikramSambat;
use Carbon\CarbonImmutable;

/*
 * Reference dates from published calendars, independent of the converter tables the month lengths come from
 * (all read on 2026-10-22):
 *  [HP]  Hamro Patro, public holidays 2082 and 2083 BS: https://www.hamropatro.com/nepali-public-holidays/2082 and
 *        https://www.hamropatro.com/nepali-public-holidays
 *  [VM]  Vedic Marga panchang pages: https://vedicmarga.com/hindu-calendar/2023/04/14/ and …/2025/04/14/
 *  [NEWS] New Year reports: Spotlight Nepal 2024-04-13 (2081), Radio Nepal 2025-04-14 (2082),
 *         ekantipur 2026-04-14 (2083); Nepal Rastra Bank 2080 calendar via Onlinekhabar (2080 ran 14 Apr 2023 to 12 Apr 2024)
 *  [CL]  Prithvi Jayanti (27 Poush) 2026 listing: https://www.calendarlabs.com/holidays/nepal/prithvi-jayanti.php
 */
dataset('reference dates', [
    'table start 1 Baisakh 2000' => [[2000, 1, 1], '1943-04-14'], // epoch used by every source table
    'New Year 2080 [VM][NEWS]' => [[2080, 1, 1], '2023-04-14'],
    'last day of 2080 [NEWS]' => [[2080, 12, 30], '2024-04-12'],
    'New Year 2081 [NEWS]' => [[2081, 1, 1], '2024-04-13'],
    'New Year 2082 [VM][NEWS]' => [[2082, 1, 1], '2025-04-14'],
    'last day of a 31-day Shrawan 2082 [HP]' => [[2082, 4, 31], '2025-08-16'],
    '1 Asoj 2082 [HP]' => [[2082, 6, 1], '2025-09-17'],
    'Mangsir 18 2082 [HP]' => [[2082, 8, 18], '2025-12-04'],
    'last day of a 29-day Mangsir 2082 [HP, Poush 10 = 25 Dec]' => [[2082, 8, 29], '2025-12-15'],
    'Christmas, Poush 10 2082 [HP]' => [[2082, 9, 10], '2025-12-25'],
    'Prithvi Jayanti 2082 [HP][CL]' => [[2082, 9, 27], '2026-01-11'],
    'issue example, 28 Poush 2082' => [[2082, 9, 28], '2026-01-12'],
    'last day of a 30-day Poush 2082 [HP]' => [[2082, 9, 30], '2026-01-14'],
    'Maghe Sankranti 2082 [HP]' => [[2082, 10, 1], '2026-01-15'],
    'Ghode Jatra 2082 [HP]' => [[2082, 12, 4], '2026-03-18'],
    'New Year 2083 [HP][NEWS]' => [[2083, 1, 1], '2026-04-14'],
    'Labour Day 2083 [HP]' => [[2083, 1, 18], '2026-05-01'],
    'Republic Day 2083 [HP]' => [[2083, 2, 15], '2026-05-29'],
    '32nd of a 32-day Asar 2083 [HP, Bhadra 10 = 26 Aug]' => [[2083, 3, 32], '2026-07-16'],
    'Constitution Day 2083 [HP]' => [[2083, 6, 3], '2026-09-19'],
    '31st of a 31-day Asoj 2083 [HP]' => [[2083, 6, 31], '2026-10-17'],
    '1 Kartik 2083 [HP]' => [[2083, 7, 1], '2026-10-18'],
    'Maghe Sankranti 2083 [HP]' => [[2083, 10, 1], '2027-01-15'],
    'Ghode Jatra 2083 [HP]' => [[2083, 12, 23], '2027-04-06'],
]);

test('known dates convert both ways', function (array $bs, string $ad) {
    [$year, $month, $day] = $bs;
    $converted = BikramSambat::fromAd(CarbonImmutable::parse($ad));

    expect([$converted->year, $converted->month, $converted->day])->toBe($bs)
        ->and(BikramSambat::toAd($year, $month, $day)->toDateString())->toBe($ad);
})->with('reference dates');

test('it formats with the English month name', function () {
    expect(BikramSambat::fromAd(CarbonImmutable::parse('2026-01-12'))->format())->toBe('28 Poush 2082 BS')
        ->and(BikramSambat::fromAd(CarbonImmutable::parse('2026-04-14'))->format())->toBe('1 Baisakh 2083 BS')
        ->and(BikramSambat::fromAd(CarbonImmutable::parse('2027-04-13'))->monthName())->toBe('Chaitra');
});

test('only the calendar day counts, not the time or time zone', function () {
    // 23:30 in Kathmandu on 12 Jan is still 12 Jan there, though it is 17:45 UTC.
    expect(BikramSambat::fromAd(CarbonImmutable::parse('2026-01-12 23:30', 'Asia/Kathmandu'))->format())->toBe('28 Poush 2082 BS')
        ->and(BikramSambat::fromAd(CarbonImmutable::parse('2026-01-12 00:10', 'America/Los_Angeles'))->format())->toBe('28 Poush 2082 BS');
});

test('every day of the table converts there and back', function () {
    $date = CarbonImmutable::parse('1943-04-14', 'UTC');
    $last = BikramSambat::toAd(BikramSambat::LAST_YEAR, 12, BikramSambat::daysInMonth(BikramSambat::LAST_YEAR, 12));
    $previous = null;

    while ($date->lte($last)) {
        $bs = BikramSambat::fromAd($date);

        if ($previous !== null && $bs->day !== 1) {
            expect([$bs->year, $bs->month, $bs->day])->toBe([$previous->year, $previous->month, $previous->day + 1]);
        }

        expect(BikramSambat::toAd($bs->year, $bs->month, $bs->day)->toDateString())->toBe($date->toDateString());
        $previous = $bs;
        $date = $date->addDay();
    }

    expect([$previous->year, $previous->month])->toBe([2100, 12]);
});

test('AD dates outside the table are refused', function (string $ad) {
    expect(fn () => BikramSambat::fromAd(CarbonImmutable::parse($ad)))->toThrow(OutOfRangeException::class, 'outside the Bikram Sambat table');
})->with([
    'the day before 1 Baisakh 2000' => '1943-04-13',
    'the day after Chaitra 2100' => '2044-04-13',
]);

test('BS dates that do not exist or are outside the table are refused', function (int $year, int $month, int $day) {
    expect(fn () => BikramSambat::toAd($year, $month, $day))->toThrow(OutOfRangeException::class);
})->with([
    'year 1999 BS' => [1999, 12, 30],
    'year 2101 BS' => [2101, 1, 1],
    'month 13' => [2082, 13, 1],
    'day 30 of a 29-day Mangsir' => [2082, 8, 30],
    'day 0' => [2082, 1, 0],
]);
