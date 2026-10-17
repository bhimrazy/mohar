<?php

use App\Modules\Core\Models\AutomationRun;
use App\Support\Automation\Automation;
use Carbon\CarbonImmutable;

test('automation runs tasks in stage order once per day', function () {
    $calls = [];
    $automation = app(Automation::class);
    $automation->task('test.terminate', Automation::TERMINATIONS, function () use (&$calls) {
        $calls[] = 'terminate';

        return 1;
    });
    $automation->task('test.renew', Automation::RENEWALS, function (CarbonImmutable $date) use (&$calls) {
        $calls[] = 'renew:'.$date->toDateString();

        return 'renewed 3';
    });

    $this->artisan('automation:run', ['--date' => '2026-05-01'])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => '2026-05-01'])->assertSuccessful();

    expect($calls)->toBe(['renew:2026-05-01', 'terminate'])
        ->and(AutomationRun::query()->where('task', 'test.renew')->value('summary'))->toBe('renewed 3');
});

test('a failing task is recorded and retried on the next run', function () {
    $attempts = 0;
    app(Automation::class)->task('test.flaky', Automation::REMINDERS, function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new RuntimeException('gateway down');
        }

        return 'ok';
    });

    $this->artisan('automation:run', ['--date' => '2026-05-02'])->assertFailed();
    expect(AutomationRun::query()->where('task', 'test.flaky')->value('status'))->toBe('failed');

    $this->artisan('automation:run', ['--date' => '2026-05-02'])->assertSuccessful();
    expect(AutomationRun::query()->where('task', 'test.flaky')->value('status'))->toBe('success');
});
