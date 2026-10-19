<?php

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * PR #92 review: scenarios belong to one checkout and one parallel worker, and nothing is signalled that is not a
 * scenario's own process.
 */
test('simulate:clean leaves a scenario running in another worker\'s root alone', function () {
    $starter = new Process([PHP_BINARY, '-r', 'require $argv[1]."/vendor/autoload.php"; $s = Mohar\Simulators\Kernel\Processes::start(Mohar\Simulators\Tests\Fixtures\EchoSimulator::class, detached: true); echo $s->directory;', base_path()], env: ['APP_ENV' => 'testing', 'XDEBUG_MODE' => 'off', 'TEST_TOKEN' => 'other'.getmypid()], timeout: 60);
    $starter->mustRun();
    $other = Scenario::load($starter->getOutput());

    try {
        expect(dirname($other->directory))->not->toBe(Scenario::root())
            ->and(dirname($other->directory))->toEndWith('-wother'.getmypid());

        $this->artisan('simulate:clean')->assertSuccessful();

        expect(Processes::running($other))->toBeTrue()
            ->and(is_dir($other->directory))->toBeTrue();
    } finally {
        foreach ($other->pids() as $pid) {
            posix_kill($pid, 15);
        }

        (new Process(['rm', '-rf', dirname($other->directory)]))->run();
    }
});

test('a pid listed in a scenario that is not one of its processes is never signalled', function () {
    $unrelated = new Process(['sleep', '30']);
    $unrelated->start();
    $scenario = Scenario::create(EchoSimulator::class);
    $scenario->withPids([(int) $unrelated->getPid()]);

    try {
        expect(Processes::belongs((int) $unrelated->getPid(), $scenario))->toBeFalse();

        Processes::stop($scenario);

        expect($unrelated->isRunning())->toBeTrue();
    } finally {
        $unrelated->stop(0);
    }
})->skip(! is_dir('/proc/self'), 'needs /proc');

test('a recording masks card data with the payment rule, and its private keys are readable by the owner only', function () {
    $scenario = Scenario::create(EchoSimulator::class);

    try {
        $path = (new Recorder($scenario->directory.'/recordings'))->record('payment_method', ['card' => ['number' => '4000056655665556', 'exp_month' => 12, 'last4' => '5556'], 'note' => 'paid with 4242 4242 4242 4242'], 'docs');
        $saved = (string) file_get_contents($path);

        expect($saved)->not->toContain('4000056655665556')->not->toContain('4242 4242 4242 4242')->toContain('5556')
            ->and(Recorder::differences(json_decode($saved, true)['response'], ['card' => ['number' => '4242424242424242', 'exp_month' => 1, 'last4' => '4242'], 'note' => 'x']))->toBe([])
            ->and(fileperms($scenario->directory.'/ca.key') & 0777)->toBe(0600)
            ->and(fileperms($scenario->ca()->leafPath()) & 0777)->toBe(0600);
    } finally {
        Processes::stop($scenario);
    }
});
