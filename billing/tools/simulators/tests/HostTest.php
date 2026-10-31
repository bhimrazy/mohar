<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

// Pins that this suite runs on Linux (CI) rather than being skipped there: Tests\TestCase skips it only off Linux, and
// the simulators need setsid(1) and /proc to start and find their processes.
test('the host this suite runs on has what the simulators need', function () {
    expect(PHP_OS_FAMILY)->toBe('Linux')
        ->and((new Process(['sh', '-c', 'command -v setsid']))->run())->toBe(0)
        ->and(is_dir('/proc/self/fd'))->toBeTrue();
});
