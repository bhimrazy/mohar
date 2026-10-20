<?php

use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Support\Automation\Automation;
use App\Support\Queues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

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

test('the automation connection keeps a running job reserved past its timeout on the database, redis and beanstalkd backends', function (string $backend) {
    $connection = Queues::automationConnection(config('queue.connections'), $backend);
    $job = new RunAutomationJob;

    expect($connection['driver'])->toBe($backend)
        ->and($connection['queue'])->toBe(Queues::AUTOMATION)
        ->and($connection['retry_after'])->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBeGreaterThan((int) config("queue.connections.{$backend}.retry_after"))
        ->and($job->uniqueFor)->toBe($connection['retry_after']);
})->with(['database', 'redis', 'beanstalkd']);

test('a default connection that runs jobs outside a worker falls back to the database queue; SQS keeps its own visibility timeout', function () {
    expect(Queues::automationConnection(config('queue.connections'), 'failover')['driver'])->toBe('database')
        ->and(Queues::automationConnection(config('queue.connections'), 'deferred')['driver'])->toBe('database')
        ->and(Queues::automationConnection(config('queue.connections'), 'sqs'))->toMatchArray(['driver' => 'sqs', 'queue' => Queues::AUTOMATION])
        ->not->toHaveKey('retry_after');
});

/**
 * "Run now" from the admin page, for one test task.
 */
function runNow(): TestResponse
{
    return test()->post(route('admin.platform.automation.run'), ['task' => 'test.housekeeping']);
}

// Review probe P2 (#122): the hazard install.md warns about, kept as a regression test.
test('a worker on the default connection that names the automation queue would take a reserved run after 240 s', function () {
    RunAutomationJob::dispatch('test.housekeeping');
    $running = Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION);

    $this->travel(241)->seconds();
    secondWorker('database');

    expect($running)->not->toBeNull()
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('MaxAttemptsExceededException');
});

test('a job taken by a worker on another connection fails without running and names the right command', function () {
    actingAsStaff(['core.automation.*']);
    runNow()->assertInertiaFlash('toast.type', 'success');

    secondWorker('database');

    expect($this->calls)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('a worker on the [database] connection took it')
        ->toContain('php artisan queue:work automation --queue=automation');

    // The failure released the unique lock: the next request is queued.
    runNow()->assertInertiaFlash('toast.type', 'success');
});

// Review probe P3 (#122).
test('a failed or timed-out run releases the unique lock', function () {
    actingAsStaff(['core.automation.*']);
    runNow()->assertInertiaFlash('toast.type', 'success');
    $running = Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION);

    // What the worker's timeout handler does with failOnTimeout before it stops the process.
    $running->fail(new RuntimeException('timed out'));

    runNow()->assertInertiaFlash('toast.type', 'success');
});

// Review probe P4 (#122): it used to be accepted, then did nothing and left no record.
test('run now during the scheduled run is refused, and a request queued before it started is skipped on record', function () {
    actingAsStaff(['core.automation.*']);
    runNow()->assertInertiaFlash('toast.type', 'success');

    $scheduled = Cache::lock(RunAutomation::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS);
    expect($scheduled->get())->toBeTrue();

    runNow()->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'An automation run is in progress. Try again when it finishes; results appear in the run log.']);
    secondWorker(Queues::AUTOMATION);

    expect($this->calls)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'automation.skipped')->sole()->properties)->toMatchArray(['task' => 'test.housekeeping', 'force' => false]);
});

// Review probe P1 (#122).
test('after a worker is killed mid-run, run now is refused until the locks expire or are released', function () {
    actingAsStaff(['core.automation.*']);
    runNow()->assertInertiaFlash('toast.type', 'success');

    // The worker reserves the job and starts the run, then is killed: no fire(), no failed(), no lock release.
    expect(Queue::connection(Queues::AUTOMATION)->pop(Queues::AUTOMATION))->not->toBeNull()
        ->and(Cache::lock(RunAutomation::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS)->get())->toBeTrue();

    runNow()->assertInertiaFlash('toast.message', 'An automation run is in progress. Try again when it finishes; results appear in the run log.');
    $this->travel(Queues::AUTOMATION_TIMEOUT_SECONDS + 1)->seconds();
    runNow()->assertInertiaFlash('toast.type', 'info');
    $this->travel(Queues::AUTOMATION_RETRY_AFTER_SECONDS - Queues::AUTOMATION_TIMEOUT_SECONDS)->seconds();
    runNow()->assertInertiaFlash('toast.type', 'success');

    // The killed reservation comes back first and fails on max attempts; its release does not free the new run's lock.
    secondWorker(Queues::AUTOMATION);
    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->calls)->toBe(0);
    runNow()->assertInertiaFlash('toast.type', 'info');
});

test('automation:release-locks frees both locks after confirmation, and nothing without it', function () {
    actingAsStaff(['core.automation.*']);
    runNow()->assertInertiaFlash('toast.type', 'success');
    Cache::lock(RunAutomation::LOCK, Queues::AUTOMATION_TIMEOUT_SECONDS)->get();

    $this->artisan('automation:release-locks')->expectsConfirmation('Release the automation locks? Do this only when no automation worker or scheduled run is running: releasing them lets a second run start alongside one.', 'no')->assertFailed();
    expect(RunAutomation::inProgress())->toBeTrue();

    $this->artisan('automation:release-locks', ['--force' => true])->assertSuccessful();

    expect(RunAutomation::inProgress())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'automation.locks_released')->exists())->toBeTrue();
    runNow()->assertInertiaFlash('toast.type', 'success');
});
