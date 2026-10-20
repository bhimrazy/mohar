<?php

use App\Modules\Core\Models\AutomationRun;
use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Support\Automation\Automation;
use App\Support\Queues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Issue #82: a queue worker hands a reserved job to a second worker once the connection's retry_after has passed,
 * even while the first worker is still running it. "Run now" jobs run on the automation connection, whose
 * retry_after is above the job's timeout. These tests use the database queue of a default install.
 */
beforeEach(function () {
    $this->calls = 0;
    app(Automation::class)->task('test.housekeeping', Automation::HOUSEKEEPING, function () {
        $this->calls++;

        return 'cleaned 2';
    });

    config(['queue.connections.'.Queues::AUTOMATION => Queues::automationConnection(config('queue.connections'), 'database')]);
});

/**
 * A second worker on the connection takes the next available job, if any.
 */
function secondWorker(string $connection): void
{
    test()->artisan('queue:work', ['connection' => $connection, '--queue' => Queues::AUTOMATION, '--once' => true, '--sleep' => 0])->assertSuccessful();
}

test('a run that outlasts the pools\' retry_after is not handed to a second worker', function () {
    RunAutomationJob::dispatch('test.housekeeping');
    $running = Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION);

    // Up to the job's own timeout, far past the 240 s the pools allow, nothing else picks the job up.
    $this->travel(Queues::AUTOMATION_TIMEOUT_SECONDS)->seconds();
    secondWorker(Queues::AUTOMATION);

    expect($running)->not->toBeNull()
        ->and($this->calls)->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', Queues::AUTOMATION)->pluck('attempts')->all())->toBe([1]);

    // The first worker finishes: one run, the job is gone, and the unique lock is free for the next "Run now".
    $running->fire();

    expect($this->calls)->toBe(1)
        ->and(AutomationRun::query()->where('task', 'test.housekeeping')->value('status'))->toBe('success')
        ->and(DB::table('jobs')->count())->toBe(0);

    RunAutomationJob::dispatch('test.housekeeping');
    expect(DB::table('jobs')->where('queue', Queues::AUTOMATION)->count())->toBe(1);
});

test('on a pool connection the same run is handed to a second worker after 240 s, which is why it has its own', function () {
    RunAutomationJob::dispatch('test.housekeeping')->onConnection('database');
    $running = Queue::connection('database')->pop(Queues::AUTOMATION);

    $this->travel((int) config('queue.connections.database.retry_after') + 1)->seconds();
    secondWorker('database');

    expect($running)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('MaxAttemptsExceededException');
});

test('a run now that cannot be queued does not block the next one', function () {
    actingAsStaff(['core.automation.*']);
    $this->withoutExceptionHandling();
    // The queue backend cannot be reached: the push fails before anything is stored.
    config(['queue.connections.'.Queues::AUTOMATION.'.connection' => 'unreachable']);

    expect(fn () => $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping']))->toThrow(InvalidArgumentException::class);

    config(['queue.connections.'.Queues::AUTOMATION.'.connection' => null]);
    $this->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping'])->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('jobs')->where('queue', Queues::AUTOMATION)->count())->toBe(1);
});

test('the automation connection keeps a running job reserved past its timeout on every queue backend', function (string $backend) {
    $connection = Queues::automationConnection(config('queue.connections'), $backend);
    $job = new RunAutomationJob;

    expect($connection['driver'])->toBe($backend)
        ->and($connection['queue'])->toBe(Queues::AUTOMATION)
        ->and($connection['retry_after'])->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBeGreaterThan((int) config("queue.connections.{$backend}.retry_after"))
        ->and($job->uniqueFor)->toBe($connection['retry_after']);
})->with(['database', 'redis', 'beanstalkd']);

test('a default connection that runs jobs in the web process falls back to the database queue', function () {
    expect(Queues::automationConnection(config('queue.connections'), 'failover')['driver'])->toBe('database')
        ->and(Queues::automationConnection(config('queue.connections'), 'deferred')['driver'])->toBe('database');
});
