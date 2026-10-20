<?php

use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Modules\Core\Console\Commands\RunAutomation;
use App\Support\Automation\Automation;
use App\Support\Queues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Architecture review probes for PR #122 (not for merge).
 */
beforeEach(function () {
    $this->calls = 0;
    app(Automation::class)->task('test.housekeeping', Automation::HOUSEKEEPING, function () {
        $this->calls++;

        return 'cleaned 2';
    });

    config(['queue.connections.'.Queues::AUTOMATION => Queues::automationConnection(config('queue.connections'), 'database')]);
    fwrite(STDERR, '[probe db driver: '.DB::connection()->getDriverName().']');
});

function probeWorker(string $connection): void
{
    test()->artisan('queue:work', ['connection' => $connection, '--queue' => Queues::AUTOMATION, '--once' => true, '--sleep' => 0])->assertSuccessful();
}

function probeRunNow(): Illuminate\Testing\TestResponse
{
    return test()->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping']);
}

test('P1 crash: Run now stays blocked until uniqueFor expires; the redelivered job does not free a newer lock', function () {
    actingAsStaff(['core.automation.*']);
    probeRunNow()->assertInertiaFlash('toast.type', 'success');

    // The worker reserves the job and is SIGKILLed: no fire(), no failed(), no lock release.
    expect(Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION))->not->toBeNull();

    $this->travel(Queues::AUTOMATION_RETRY_AFTER_SECONDS - 10)->seconds();
    probeRunNow()->assertInertiaFlash('toast.type', 'info');

    $this->travel(20)->seconds();
    probeRunNow()->assertInertiaFlash('toast.type', 'success');

    // The crashed reservation comes back first (lowest id) and fails on max attempts.
    probeWorker(Queues::AUTOMATION);
    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->calls)->toBe(0);

    // Its failed() release uses the old owner, so the new run's lock is still held.
    probeRunNow()->assertInertiaFlash('toast.type', 'info');
});

test('P2 a worker on the default connection that names the automation queue redelivers after 240 s', function () {
    RunAutomationJob::dispatch('test.housekeeping');
    $running = Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION);

    $this->travel(241)->seconds();
    // `php artisan queue:work --queue=automation` on QUEUE_CONNECTION=database: the same jobs rows.
    probeWorker('database');

    expect($running)->not->toBeNull()
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('MaxAttemptsExceededException');
});

test('P3 a failed or timed-out run releases the unique lock', function () {
    actingAsStaff(['core.automation.*']);
    probeRunNow()->assertInertiaFlash('toast.type', 'success');
    $running = Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION);

    // What the worker's SIGALRM handler does with failOnTimeout before it kills the process.
    $running->fail(new RuntimeException('timed out'));

    probeRunNow()->assertInertiaFlash('toast.type', 'success');
});

test('P4 Run now during the scheduled run is accepted, then does nothing and leaves no trace', function () {
    actingAsStaff(['core.automation.*']);
    $scheduled = Cache::lock(RunAutomation::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS);
    expect($scheduled->get())->toBeTrue();

    probeRunNow()->assertInertiaFlash('toast.type', 'success');
    probeWorker(Queues::AUTOMATION);

    expect($this->calls)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});
