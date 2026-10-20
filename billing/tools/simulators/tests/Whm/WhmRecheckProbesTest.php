<?php

/*
 * Re-check probes from the PR #100 review (head c0583f2): attempts to get customer data past the allow-list
 * anonymisation and the secret scan. A value is safe when it is either gone from the saved recording or the scan
 * flags the file (simulate:record then saves nothing). The data-in-keys case fails at c0583f2.
 */

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\RecordingScanner;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Whm\LiveRecording;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Whether $needle reaches a recording that passes the scan, through the live path (anonymise, record, scan).
 *
 * @param  array<array-key, mixed>  $answer
 */
function leaksPastRecording(array $answer, string $needle): bool
{
    $directory = sys_get_temp_dir().'/probe-recheck-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    try {
        $path = (new Recorder($directory, payments: false))->record('probe', LiveRecording::anonymise($answer), 'live', ['SIMTOKEN', 'whm.host.test', 'root', '']);

        return str_contains((string) file_get_contents($path), $needle) && (new RecordingScanner)->scanFile($path) === [];
    } finally {
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }
}

test('customer data cannot reach a recording that passes the scan', function (array $answer, string $needle) {
    expect(leaksPastRecording($answer, $needle))->toBeFalse();
})->with([
    'a value under an unknown key' => [['data' => ['custom_field' => 'Anna Smith']], 'Anna Smith'],
    'deeply nested' => [['data' => ['acct' => [['meta' => ['notes' => ['Anna Smith, VIP']]]]]], 'Anna Smith'],
    'an email inside reason' => [['metadata' => ['result' => 0, 'reason' => 'Mail to anna@alpha-books.com failed.']], 'anna@alpha-books.com'],
    'a domain inside reason' => [['metadata' => ['result' => 0, 'reason' => 'The domain alpha-books.com is already parked.']], 'alpha-books.com'],
    'bracketed IPv6 with a port inside reason' => [['metadata' => ['result' => 0, 'reason' => 'Could not reach [2001:4860::8888]:2087.']], '2001:4860::8888'],
    'bracketed IPv6 with a port under an unknown key' => [['data' => ['listen' => '[2001:4860::8888]:2087']], '2001:4860::8888'],
    'an IPv4-mapped IPv6 address inside reason' => [['metadata' => ['result' => 0, 'reason' => 'Peer ::ffff:8.8.8.8 refused.']], '8.8.8.8'],
    'an upper-case IPv6 address under a list' => [['data' => ['ipv6' => ['2001:4860:4860::8888'.'']]], '2001:4860'],
    'a host name in a URL under a safe key' => [['metadata' => ['result' => 0, 'reason' => 'See https://mx1.alpha-books.com/status']], 'alpha-books.com'],
    'a domain used as a key' => [['data' => ['alpha-books.com' => ['diskused' => '12M']]], 'alpha-books.com'],
]);

test('a nested transaction that fails rolls back only its own work', function () {
    $scenario = Scenario::create(EchoSimulator::class);
    $state = $scenario->state();

    try {
        $state->transaction(function () use ($state): void {
            $state->put('probe', 'outer', 1);

            try {
                $state->transaction(function () use ($state): void {
                    $state->put('probe', 'inner', 1);

                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }

            $state->transaction(fn () => $state->transaction(fn () => $state->put('probe', 'deep', 1)));
        });

        expect($state->get('probe', 'outer'))->toBe(1)
            ->and($state->get('probe', 'inner'))->toBeNull()
            ->and($state->get('probe', 'deep'))->toBe(1)
            ->and($state->increment('ids', 'after'))->toBe(1);
    } finally {
        Processes::stop($scenario);
    }
});
