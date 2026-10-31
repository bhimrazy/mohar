<?php

use Tests\TestCase;

// Tests\TestCase skips the simulator suite only where the simulators cannot run (macOS: no setsid, no /proc). On Linux,
// where CI runs it, nothing is skipped, so a missing tool there fails instead of passing as skipped.

test('the simulator suite is skipped only off Linux, with the way to run it', function () {
    expect(TestCase::simulatorHostProblem('Linux'))->toBeNull()
        ->and(TestCase::simulatorHostProblem('Darwin'))->toContain('setsid')->toContain('scripts/linux.sh')
        ->and(TestCase::simulatorHostProblem('Windows'))->not->toBeNull();
});
