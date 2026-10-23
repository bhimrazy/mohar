<?php

/*
 * cPanel cron mode runs `schedule:run` without flock (docs/devops/cpanel.md): overlap protection is the entries' own
 * withoutOverlapping() (ADR 0017 decision 2). A new entry without it would run twice when a tick outlasts a minute.
 */

use Illuminate\Console\Scheduling\Schedule;

test('every scheduled entry except the heartbeat is protected against overlap and runs on one server', function () {
    $events = collect(app(Schedule::class)->events())
        ->reject(fn ($event): bool => $event->description === 'core.heartbeat');

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue("{$event->description} {$event->command} lacks withoutOverlapping()")
            ->and($event->onOneServer)->toBeTrue("{$event->description} {$event->command} lacks onOneServer()");
    }
});
