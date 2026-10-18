<?php

use App\Enums\DriverOutcome;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CertificateInspector;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\Cpanel\Whm;

/**
 * Real TLS, no Http::fake: a local `openssl s_server` with a self-signed certificate stands in for WHM, and the
 * hostname resolves (through the guard's pinned address) to 127.0.0.1. Proves the handshake checks, the SPKI pin
 * and the address pin against curl itself rather than against the options we pass it.
 */
beforeEach(function () {
    $this->openssl = (new ExecutableFinder)->find('openssl');

    if ($this->openssl === null) {
        $this->markTestSkipped('openssl is not installed.');
    }

    config(['integrations.outbound.allow_loopback' => true]);
    Whm::bindGuard('127.0.0.1');
    $this->servers = [];
});

afterEach(function () {
    foreach ($this->servers ?? [] as $process) {
        $process->stop(0);
    }
});

/**
 * Start a TLS server with a fresh self-signed key for whm.example.test; returns [port, SPKI pin].
 *
 * @return array{int, string}
 */
function startTlsServer(object $test): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => Whm::HOST], $key);
    $certificate = openssl_csr_sign($csr, null, $key, 2);
    $directory = sys_get_temp_dir().'/mohar-tls-'.bin2hex(random_bytes(4));
    mkdir($directory);
    openssl_x509_export_to_file($certificate, "{$directory}/cert.pem");
    openssl_pkey_export_to_file($key, "{$directory}/key.pem");
    $port = random_int(20000, 40000);

    $process = new Process([$test->openssl, 's_server', '-accept', "127.0.0.1:{$port}", '-cert', "{$directory}/cert.pem", '-key', "{$directory}/key.pem", '-www', '-quiet']);
    $process->start();
    $test->servers[] = $process;

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100_000);
    }

    return [$port, CertificateInspector::spkiPin((string) openssl_pkey_get_details($key)['key'])];
}

test('a self-signed certificate without a pin fails the handshake, and its fingerprint is offered for comparison', function () {
    [$port, $pin] = startTlsServer($this);

    $check = app(CpanelDriver::class)->testConnection(Whm::server(port: $port));

    expect($check->ok)->toBeFalse()
        ->and($check->steps[0]->label)->toBe('Address and certificate')
        ->and($check->message)->toContain('not trusted')
        ->and($check->details['certificate_spki_sha256'])->toBe($pin)
        ->and($check->details['certificate_subject'])->toStartWith('CN='.Whm::HOST);
});

test('a trusted pin lets the handshake succeed, and a changed key is rejected as a changed certificate', function () {
    [$port, $pin] = startTlsServer($this);
    $driver = app(CpanelDriver::class);

    // openssl's -www answers with an HTML status page, so getting as far as "not the WHM API" proves TLS passed.
    $pinned = $driver->testConnection(Whm::server(['spki_pin' => $pin], port: $port));
    expect($pinned->steps[0]->status->value)->toBe('pass')
        ->and($pinned->message)->toContain('did not answer like the WHM API');

    [$otherPort] = startTlsServer($this);
    $result = $driver->create(Whm::context(server: Whm::server(['spki_pin' => $pin], port: $otherPort)));

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toStartWith('Server certificate changed');
});
