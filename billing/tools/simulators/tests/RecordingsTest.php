<?php

use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\RecordingScanner;
use Mohar\Simulators\Providers\Whm\LiveRecording;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/recordings-test-'.bin2hex(random_bytes(4)).'/whm/recordings';
    mkdir($this->directory, 0700, true);
});

afterEach(function () {
    array_map('unlink', glob($this->directory.'/*') ?: []);
    rmdir($this->directory);
    rmdir(dirname($this->directory));
    rmdir(dirname($this->directory, 2));
});

test('the recordings committed to the package pass the secret scan', function () {
    expect((new RecordingScanner)->scanPackage())->toBe([]);

    $this->artisan('simulate:scan')->assertSuccessful();
});

test('the scan finds credentials and personal data that redaction by key would miss', function (array $response, string $finding) {
    file_put_contents($this->directory.'/leak.json', json_encode(['source' => 'live', 'response' => $response]));

    expect(implode("\n", (new RecordingScanner)->scan($this->directory)))->toContain($finding);
})->with([
    'a WHM token in text' => [['note' => 'sent whm root:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'], 'a WHM authorization value'],
    'a bare cPanel token' => [['data' => ['value' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345']], 'a cPanel API token'],
    'a Stripe key' => [['secret_note' => 'sk_live_abcdefgh1234'], 'a Stripe key or secret'],
    'a private key' => [['pem' => "-----BEGIN EC PRIVATE KEY-----\nabc"], 'a private key'],
    'an unmasked password' => [['password' => 'hunter2'], 'an unmasked value under "password"'],
    'a customer email' => [['email' => 'anna@alpha-books.com'], 'a real-looking email address under "email"'],
    'a public address' => [['ip' => '8.8.8.8'], 'a public IP address under "ip"'],
    'a public IPv6 address' => [['ipv6' => ['2001:4860:4860::8888']], 'a public IPv6 address under "0"'],
    'a real host name' => [['child_nodes' => [['hostname' => 'mx1.alpha-books.com']]], 'a real-looking host name under "hostname"'],
    'a domain used as a key' => [['data' => ['alpha-books.com' => ['diskused' => '12M']]], 'a real-looking host name a key'],
    'a cPanel session path' => [['url' => 'https://host.example.test:2083/cpsess1234567890/'], 'a cPanel session path'],
]);

test('placeholder data passes: example domains, documentation addresses, masked values, versions', function () {
    (new Recorder($this->directory))->record('version', ['data' => ['version' => '11.124.0.12', 'ip' => '203.0.113.20', 'email' => 'owner@example-shop.test', 'token' => 'x']], 'docs');

    expect((new RecordingScanner)->scan($this->directory))->toBe([]);

    file_put_contents($this->directory.'/notes.txt', 'anything');
    expect((new RecordingScanner)->scan($this->directory))->toBe(['whm/recordings/notes.txt: not a recording (a JSON file with source "docs" or "live")']);
});

test('a live recording keeps the shape and replaces what names a customer or the server', function () {
    $answer = ['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => [['user' => 'anna', 'domain' => 'alpha-books.com', 'email' => 'Anna@Alpha-Books.com', 'ip' => '8.8.4.4', 'suspended' => 0, 'diskused' => '120M']]]];
    $anonymised = LiveRecording::anonymise($answer);

    (new Recorder($this->directory))->record('listaccts', $anonymised, 'live', ['secret-token']);

    expect(Recorder::differences($answer, $anonymised))->toBe([])
        ->and((string) json_encode($anonymised))->not->toContain('anna')->not->toContain('alpha-books')->not->toContain('8.8.4.4')
        ->and($anonymised['data']['acct'][0])->toMatchArray(['ip' => '192.0.2.10', 'email' => 'owner@example.test', 'suspended' => 0, 'diskused' => '120M'])
        ->and(LiveRecording::anonymise(['data' => ['phone' => '5551234567', 'limit' => '50']])['data'])->toBe(['phone' => '0', 'limit' => '50'])
        ->and((new RecordingScanner)->scan($this->directory))->toBe([]);
});

test('simulate:record needs the real server\'s credentials from the environment and records nothing without them', function () {
    $recordings = base_path('tools/simulators/whm/recordings');
    $before = glob($recordings.'/*') ?: [];

    $this->artisan('simulate:record', ['provider' => 'whm'])->expectsOutputToContain('WHM_RECORD_HOST')->assertFailed();

    expect(glob($recordings.'/*') ?: [])->toBe($before)
        ->and(new WhmSimulator)->toBeInstanceOf(RecordsLive::class);
});
