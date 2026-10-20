<?php

/*
 * Security and isolation probes from the PR #92 review (ADR 0016 S1). The first three fail at 9784842 and pin the
 * fixes; the rest pin protections that hold today.
 */

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A scenario started by another PHP process (another test worker, an agent, a developer's test run) that stays alive
 * until killed.
 *
 * @return array{0: Process, 1: Scenario}
 */
function foreignScenario(): array
{
    $file = sys_get_temp_dir().'/probe-starter-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($file, '<?php require $argv[1]."/vendor/autoload.php"; $s = Mohar\Simulators\Kernel\Processes::start(Mohar\Simulators\Tests\Fixtures\EchoSimulator::class); echo $s->directory, "\n"; flush(); sleep(120);');
    $process = new Process([PHP_BINARY, $file, base_path()], env: ['APP_ENV' => 'testing', 'XDEBUG_MODE' => 'off'], timeout: 180);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, "\n"));
    @unlink($file);

    return [$process, Scenario::load(trim($process->getOutput()))];
}

/**
 * @return list<int>
 */
function alivePids(Scenario $scenario): array
{
    return array_values(array_filter($scenario->pids(), fn (int $pid): bool => posix_kill($pid, 0)));
}

test('running a framework test file leaves scenarios started by other processes alone', function () {
    [$owner, $scenario] = foreignScenario();

    try {
        (new Process([PHP_BINARY, 'vendor/bin/pest', '--compact', 'tools/simulators/tests/CommandsTest.php'], base_path(), ['XDEBUG_MODE' => 'off'], timeout: 120))->run();

        expect(alivePids($scenario))->toHaveCount(3)
            ->and(is_dir($scenario->directory))->toBeTrue();
    } finally {
        $owner->stop(0, 9);
        Processes::stop($scenario);
    }
});

test('a scenario started by a test process does not outlive that process', function () {
    [$owner, $scenario] = foreignScenario();

    try {
        $owner->stop(0, 9); // SIGKILL: no finally, no afterEach, as when a runner times out or is interrupted
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline && alivePids($scenario) !== []) {
            usleep(100_000);
        }

        expect(alivePids($scenario))->toBe([]);
    } finally {
        Processes::stop($scenario);
    }
});

test('the request log masks card data with the payment redaction rule', function () {
    $scenario = Scenario::create(EchoSimulator::class);

    try {
        $scenario->log()->record('create_payment_method', new Request('POST', '/v1/payment_methods', [], ['content-type' => 'application/x-www-form-urlencoded'], 'type=card&card[number]=4242424242424242&card[cvc]=123&card[exp_month]=12&card[exp_year]=2030'), Response::json(['card' => ['number' => '4000056655665556', 'last4' => '5556']]));
        $logged = (string) json_encode($scenario->log()->entries());

        expect($logged)->not->toContain('4242424242424242')
            ->and($logged)->not->toContain('4000056655665556')
            ->and($logged)->toContain('5556'); // last 4 stay visible, as in the gateway log
    } finally {
        Processes::stop($scenario);
    }
});

test('the request log masks credentials in headers, query and body with the kernel rules', function () {
    $scenario = Scenario::create(EchoSimulator::class);

    try {
        $scenario->log()->record('createacct', new Request('GET', '/json-api/createacct', ['username' => 'bob', 'password' => 'Hunter2-pass'], ['authorization' => 'whm root:WHMTOKEN123', 'x-api-key' => 'APIKEY123', 'cookie' => 'whostmgrsession=COOKIE123', 'stripe-signature' => 't=1,v1=SIG123']), Response::json(['client_secret' => 'pi_SECRET123', 'raw' => 'password=PW123']));
        $logged = (string) json_encode($scenario->log()->entries());

        foreach (['Hunter2-pass', 'WHMTOKEN123', 'APIKEY123', 'COOKIE123', 'SIG123', 'pi_SECRET123', 'PW123'] as $secret) {
            expect($logged)->not->toContain($secret);
        }
    } finally {
        Processes::stop($scenario);
    }
});

test('scenario cleanup removes symlinks, never what they point at, and never leaves the temp root', function () {
    $outside = sys_get_temp_dir().'/probe-outside-'.bin2hex(random_bytes(4));
    mkdir($outside);
    file_put_contents($outside.'/keep.txt', 'keep');

    $scenario = Scenario::create(EchoSimulator::class);
    symlink($outside, $scenario->directory.'/link-dir');
    symlink($outside.'/keep.txt', $scenario->directory.'/link-file');
    $planted = Scenario::root().'/planted-'.bin2hex(random_bytes(4));
    symlink($outside, $planted);

    try {
        Processes::stop($scenario);
        expect(is_file($outside.'/keep.txt'))->toBeTrue()->and(is_dir($scenario->directory))->toBeFalse();

        $this->artisan('simulate:stop', ['scenario' => '../../'.basename($outside)])->assertFailed();
        expect(is_file($outside.'/keep.txt'))->toBeTrue();

        $this->artisan('simulate:stop', ['scenario' => basename($planted)])->assertFailed();
        expect(is_file($outside.'/keep.txt'))->toBeTrue();
    } finally {
        @unlink($planted);
        @unlink($outside.'/keep.txt');
        @rmdir($outside);
    }
});

test('a running scenario listens on 127.0.0.1 only, and its CA and keys stay in its own 0700 temp directory', function () {
    $scenario = Processes::start(EchoSimulator::class);

    try {
        $listening = [];

        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
            foreach (array_slice(file($table) ?: [], 1) as $line) {
                $fields = preg_split('/\s+/', trim($line));
                [$address, $port] = explode(':', $fields[1]);

                if ($fields[3] === '0A' && in_array(hexdec($port), [$scenario->apiPort(), $scenario->backendPort(), $scenario->controlPort()], true)) {
                    $listening[] = $address;
                }
            }
        }

        $keysInPackage = array_filter(
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS))),
            fn (SplFileInfo $file): bool => preg_match('/\.(pem|key|crt|sqlite)$/', $file->getFilename()) === 1,
        );

        expect($listening)->toHaveCount(3)->each->toBe('0100007F')
            ->and(fileperms($scenario->directory) & 0777)->toBe(0700)
            ->and(dirname($scenario->ca()->bundlePath()))->toBe($scenario->directory)
            ->and(dirname($scenario->ca()->leafPath()))->toBe($scenario->directory)
            ->and($keysInPackage)->toBe([]);
    } finally {
        Processes::stop($scenario);
    }
})->skip(! is_dir('/proc/net'), 'needs /proc');
