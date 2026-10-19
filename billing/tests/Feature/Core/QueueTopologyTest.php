<?php

use App\Modules\Core\Console\Commands\InstallCommand;
use App\Modules\Services\Jobs\RunModuleCommand;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ADR 0017 §3 and ADR 0014: a job that outlives its queue's retry_after is delivered to a second worker while the
 * first still runs. Every module job declares its timeout and tries, stays below retry_after with the lock slack,
 * and names its queue through App\Support\Queues.
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function moduleJobs(): array
{
    $jobs = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Modules/*/Jobs/*.php') ?: [] as $file) {
        $class = 'App\\Modules\\'.basename(dirname($file, 2)).'\\Jobs\\'.basename($file, '.php');

        if (is_subclass_of($class, ShouldQueue::class)) {
            $jobs[class_basename($class)] = [$class, (string) file_get_contents($file)];
        }
    }

    return $jobs;
}

it('keeps every job timeout plus the lock slack below the queue retry_after', function (string $class, string $source): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->hasProperty('timeout') && $reflection->getProperty('timeout')->getDeclaringClass()->getName() === $class)->toBeTrue("{$class} must declare \$timeout")
        ->and($reflection->hasProperty('tries') && $reflection->getProperty('tries')->getDeclaringClass()->getName() === $class)->toBeTrue("{$class} must declare \$tries");

    // RunModuleCommand takes the driver's timeout, capped by services.module.max_job_timeout_seconds.
    $timeout = $class === RunModuleCommand::class
        ? (int) config('services.module.max_job_timeout_seconds')
        : (int) $reflection->getProperty('timeout')->getDefaultValue();

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect($timeout + Queues::LOCK_SLACK_SECONDS)->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"));
    }

    expect($timeout)->toBeGreaterThan(0)->toBeLessThanOrEqual(Queues::POOL_TIMEOUT_SECONDS)
        ->and($source)->not->toMatch('/onQueue\(\s*[\'"]/');
})->with(fn (): array => moduleJobs());

it('pushes jobs only after the surrounding transaction commits, on every queue driver', function (): void {
    foreach (['database', 'redis', 'beanstalkd', 'sqs'] as $connection) {
        expect(config("queue.connections.{$connection}.after_commit"))->toBeTrue();
    }
});

it('documents the same worker pools that app:install prints', function (): void {
    $install = (string) file_get_contents(base_path('docs/devops/install.md'));

    foreach (Queues::workerCommands() as $command) {
        expect($install)->toContain($command);
    }

    expect((string) file_get_contents((new ReflectionClass(InstallCommand::class))->getFileName()))->toContain('Queues::workerCommands()')
        ->and(Queues::POOL_TIMEOUT_SECONDS + Queues::LOCK_SLACK_SECONDS)->toBeLessThanOrEqual((int) config('queue.connections.database.retry_after'));
});
