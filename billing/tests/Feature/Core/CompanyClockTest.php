<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\Settings;
use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/*
 * The company clock (ADR 0013 Amendment 1). Time is frozen at a UTC instant; the company is in Kathmandu (UTC+5:45), so
 * 18:15:00 UTC is local midnight and a whole-hour zone would hide an error of the last 45 minutes.
 */

afterEach(fn () => CarbonImmutable::setTestNow());

it('reads the company date from a frozen UTC instant at the Kathmandu midnight boundary', function (string $utc, string $date, string $time) {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    $clock = app(CompanyClock::class);

    expect($clock->timezone())->toBe('Asia/Kathmandu')
        ->and($clock->today()->toDateString())->toBe($date)
        ->and($clock->today()->format('H:i:s'))->toBe('00:00:00')
        ->and($clock->now()->format('H:i:s P'))->toBe($time)
        ->and($clock->localDate(CarbonImmutable::now('UTC')))->toBe($date);
})->with([
    'one second before local midnight' => ['2026-10-18 18:14:59', '2026-10-18', '23:59:59 +05:45'],
    'local midnight' => ['2026-10-18 18:15:00', '2026-10-19', '00:00:00 +05:45'],
    'five minutes after local midnight' => ['2026-10-18 18:20:00', '2026-10-19', '00:05:00 +05:45'],
]);

it('names the company date as a date value, apart from the instant', function (string $utc, string $date) {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    $clock = app(CompanyClock::class);

    expect($clock->todayDate()->toDateString())->toBe($date)
        ->and($clock->todayDate()->utc()->toDateTimeString())->toBe("{$date} 00:00:00")
        ->and($clock->today()->utc()->toDateTimeString())->not->toBe($clock->todayDate()->toDateTimeString());
})->with([
    ['2026-10-18 18:14:59', '2026-10-18'],
    ['2026-10-18 18:15:00', '2026-10-19'],
]);

it('gives the company date as a CalendarDate at the Kathmandu midnight boundary', function (string $utc, string $date) {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));

    expect(app(CompanyClock::class)->calendarDate()->toString())->toBe($date);
})->with([
    ['2026-10-18 18:14:59', '2026-10-18'],
    ['2026-10-18 18:15:00', '2026-10-19'],
]);

it('turns a CalendarDate into the instants of its company day and back', function (string $zone, string $date, string $start, string $end) {
    inCompanyTimezone($zone);
    $clock = app(CompanyClock::class);
    [$from, $to] = $clock->dayBounds(CalendarDate::parse($date));

    expect($from->toIso8601ZuluString())->toBe($start)
        ->and($to->toIso8601ZuluString())->toBe($end)
        // The first instant of the day is on that date and the one before it is on the day before.
        ->and($clock->localDate($from))->toBe($date)
        ->and($clock->localDate($from->subSecond()))->toBe(CalendarDate::parse($date)->addDays(-1)->toString())
        ->and($clock->localDate($to->subSecond()))->toBe($date)
        ->and($clock->localDate($to))->toBe(CalendarDate::parse($date)->addDays(1)->toString());
})->with([
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-19', '2026-10-18T18:15:00Z', '2026-10-19T18:15:00Z'],
    'Kathmandu, 1 Shrawan 2083 (fiscal-year start)' => ['Asia/Kathmandu', '2026-07-17', '2026-07-16T18:15:00Z', '2026-07-17T18:15:00Z'],
    'New York, winter' => ['America/New_York', '2026-01-15', '2026-01-15T05:00:00Z', '2026-01-16T05:00:00Z'],
    'New York, summer' => ['America/New_York', '2026-07-15', '2026-07-15T04:00:00Z', '2026-07-16T04:00:00Z'],
    'New York, 23-hour day (DST starts)' => ['America/New_York', '2026-03-08', '2026-03-08T05:00:00Z', '2026-03-09T04:00:00Z'],
    'New York, 25-hour day (DST ends)' => ['America/New_York', '2026-11-01', '2026-11-01T04:00:00Z', '2026-11-02T05:00:00Z'],
]);

it('exposes the fallback on the contract', function () {
    expect(app(CompanyClock::class)->isFallback())->toBeFalse();

    app(Settings::class)->set('system.timezone', 'Mars/Olympus');

    expect(app(CompanyClock::class)->isFallback())->toBeTrue();
});

it('gives the UTC bounds of a company day, from a string or an instant', function () {
    inCompanyTimezone('Asia/Kathmandu');
    [$start, $end] = app(CompanyClock::class)->dayBounds('2026-10-19');

    expect($start->toIso8601ZuluString())->toBe('2026-10-18T18:15:00Z')
        ->and($end->toIso8601ZuluString())->toBe('2026-10-19T18:15:00Z')
        ->and(app(CompanyClock::class)->dayBounds(CarbonImmutable::parse('2026-10-19 22:00', 'Asia/Kathmandu'))[0]->equalTo($start))->toBeTrue();
});

it('falls back to UTC for a stored zone that is not valid', function () {
    app(Settings::class)->set('system.timezone', 'Mars/Olympus');

    expect(app(CompanyClock::class)->timezone())->toBe('UTC');
});

it('defaults the automation run date to the company date', function () {
    inCompanyTimezone('Asia/Kathmandu');
    app(Automation::class)->task('test.clock', Automation::HOUSEKEEPING, fn (CarbonImmutable $date): string => $date->toDateString());

    foreach (['2026-10-18 18:14:59' => '2026-10-18', '2026-10-18 18:15:00' => '2026-10-19'] as $utc => $date) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
        $this->artisan('automation:run', ['--only' => 'test.clock'])->assertSuccessful();

        expect(AutomationRun::query()->where('task', 'test.clock')->whereDate('run_date', $date)->value('summary'))->toBe($date);
    }
});

it('starts the daily automation at the configured time on the company clock', function () {
    inCompanyTimezone('Asia/Kathmandu');
    app(Settings::class)->set('core.automation_time', '03:00');
    $this->app->forgetInstance(Schedule::class);
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'automation:run'));

    // 03:00 in Kathmandu is 21:15 UTC the day before; 03:00 UTC is 08:45 there.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 21:15:00', 'UTC'));
    expect($event->isDue($this->app))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 03:00:00', 'UTC'));
    expect($event->isDue($this->app))->toBeFalse();
});

it('hands a CalendarDate-typed task a CalendarDate and an older task the Carbon date value', function () {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 18:15:00', 'UTC'));
    $seen = [];
    app(Automation::class)->task('test.typed', Automation::HOUSEKEEPING, function (CalendarDate $today) use (&$seen): string {
        $seen['typed'] = $today;

        return $today->toString();
    });
    app(Automation::class)->task('test.legacy', Automation::HOUSEKEEPING, function (CarbonImmutable $today) use (&$seen): string {
        $seen['legacy'] = $today;

        return $today->toDateString();
    });
    app(Automation::class)->task('test.untyped', Automation::HOUSEKEEPING, fn (): string => 'ok');

    $this->artisan('automation:run')->assertSuccessful();

    expect($seen['typed'])->toEqual(CalendarDate::parse('2026-10-19'))
        ->and($seen['legacy']->toIso8601ZuluString())->toBe('2026-10-19T00:00:00Z')
        ->and(AutomationRun::query()->whereIn('task', ['test.typed', 'test.legacy', 'test.untyped'])->pluck('summary', 'task')->all())
        ->toEqual(['test.typed' => '2026-10-19', 'test.legacy' => '2026-10-19', 'test.untyped' => 'ok']);
});

it('refuses a run date that is not Y-m-d', function () {
    $this->artisan('automation:run', ['--date' => 'next friday'])->assertFailed();

    expect(AutomationRun::query()->count())->toBe(0);
});
