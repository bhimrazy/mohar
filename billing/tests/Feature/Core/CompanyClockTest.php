<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\Settings;
use App\Support\Automation\Automation;
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
