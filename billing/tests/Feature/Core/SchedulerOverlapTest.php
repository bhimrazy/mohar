<?php

/*
 * cPanel cron mode runs `schedule:run` without flock (docs/devops/cpanel.md): overlap protection is the entries' own
 * withoutOverlapping() (ADR 0017 decision 2). A new entry without it would run twice when a tick outlasts a minute.
 */

use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Support\ExchangeRateTask;
use Illuminate\Console\Scheduling\Schedule;

test('every scheduled entry runs on one server and, except the heartbeat, is protected against overlap', function () {
    // The separate exchange-rate fetch registers only when its time is set; set it so that entry is checked too.
    app(Settings::class)->set('platform.rates_update_time', '17:00');
    app()->forgetInstance(Schedule::class);

    $events = collect(app(Schedule::class)->events());

    expect($events->pluck('description')->all())->toContain(ExchangeRateTask::KEY);

    foreach ($events as $event) {
        // The heartbeat is one cache write a minute; an overlap is harmless (ADR 0017).
        if ($event->description !== 'core.heartbeat') {
            expect($event->withoutOverlapping)->toBeTrue("{$event->description} {$event->command} lacks withoutOverlapping()");
        }

        expect($event->onOneServer)->toBeTrue("{$event->description} {$event->command} lacks onOneServer()");
    }
});
