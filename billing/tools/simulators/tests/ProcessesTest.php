<?php

use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The real transport, end to end: a running scenario (TLS front, php -S API backend, control plane) reached by the app's
 * HTTP client through Endpoints::for(), with verification on against the scenario CA and the connection pinned by the
 * outbound guard. No fakes.
 */
beforeEach(function () {
    $this->scenario = Processes::start(EchoSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => json_encode(['echo.api' => $this->scenario->apiUrl()]),
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function echoApi(): PendingRequest
{
    $endpoint = Endpoints::for('echo.api', 'https://api.echo.example');

    return Http::withOptions($endpoint->options)->withToken(EchoSimulator::KEY)->baseUrl($endpoint->url())->timeout(5);
}

function control(object $test, string $method, string $path, array $data = [], ?string $token = null): Response
{
    return Http::withHeaders(['X-Sim-Token' => $token ?? $test->scenario->token()])->{$method}($test->scenario->controlUrl().$path, $data);
}

test('the app reaches the simulator over verified TLS, state persists across calls, and every call is logged redacted', function () {
    $created = echoApi()->post('/v1/items', ['name' => 'first'])->throw()->json();
    $fetched = echoApi()->get('/v1/items/'.$created['id'])->throw()->json();

    expect($fetched)->toBe($created)
        ->and($this->scenario->log()->count('create_item'))->toBe(1)
        ->and($this->scenario->log()->entries('create_item')[0]['headers']['authorization'])->toBe('[redacted]')
        ->and(json_encode($this->scenario->log()->entries()))->not->toContain(EchoSimulator::KEY);
});

test('a certificate the scenario CA did not sign is refused: verification stays on', function () {
    config(['integrations.endpoints.ca_bundle' => null]);
    app()->forgetInstance(Endpoints::class);

    expect(fn () => echoApi()->post('/v1/items', ['name' => 'x']))->toThrow(ConnectionException::class);
    expect($this->scenario->log()->count('create_item'))->toBe(0);
});

test('faults reach the client on a real socket', function () {
    $faults = $this->scenario->faults();
    $faults->add('create_item', Fault::status(503, '{"error":"busy"}'), nth: 1);
    $faults->add('create_item', Fault::rateLimit(7), nth: 2);
    $faults->add('create_item', Fault::truncate(5), nth: 3);
    $faults->add('create_item', Fault::resetBeforeCommit(), nth: 4);
    $faults->add('create_item', Fault::commitThenHang(3), nth: 5);

    expect(echoApi()->post('/v1/items')->status())->toBe(503);
    expect(echoApi()->post('/v1/items')->header('Retry-After'))->toBe('7');
    expect(fn () => echoApi()->post('/v1/items'))->toThrow(ConnectionException::class);
    expect(fn () => echoApi()->post('/v1/items'))->toThrow(ConnectionException::class);
    // The work is done, then the answer comes too late for the client.
    expect(fn () => echoApi()->timeout(1)->post('/v1/items', ['name' => 'slow']))->toThrow(ConnectionException::class);

    $items = $this->scenario->state()->all('items');
    expect(array_column($items, 'name'))->toContain('slow')
        ->and($items)->toHaveCount(2); // the truncated call committed too; the reset one did not
});

test('rotating the certificate changes the key the server presents', function () {
    $presented = function (): string {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'cafile' => $this->scenario->ca()->bundlePath(), 'peer_name' => 'localhost']]);
        $socket = stream_socket_client('ssl://127.0.0.1:'.$this->scenario->apiPort(), $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'];
        fclose($socket);

        return openssl_pkey_get_details(openssl_pkey_get_public($certificate))['key'];
    };
    $before = $presented();

    control($this, 'post', '/__sim/rotate-certificate')->throw();

    expect($presented())->not->toBe($before);
});

test('the control plane needs the scenario token, plans faults and runs provider actions', function () {
    expect(control($this, 'get', '/__sim/health', token: 'wrong')->status())->toBe(403)
        ->and(control($this, 'post', '/__sim/faults', ['operation' => 'get_item', 'type' => 'status', 'params' => ['status' => 500]])->status())->toBe(201)
        ->and(echoApi()->get('/v1/items/none')->status())->toBe(500)
        ->and(control($this, 'post', '/__sim/actions/emit', ['url' => 'https://app.test/hooks'])->json('delivery'))->toBe(1)
        ->and(control($this, 'get', '/__sim/outbox')->json('0.url'))->toBe('https://app.test/hooks')
        ->and(Http::get('http://127.0.0.1:'.$this->scenario->controlPort().'/__sim/health')->status())->toBe(403);
});

test('stopping a scenario ends its processes and removes its files', function () {
    $pids = $this->scenario->pids();

    Processes::stop($this->scenario);
    usleep(300_000);

    expect(array_filter($pids, fn (int $pid) => posix_kill($pid, 0)))->toBe([])
        ->and(is_dir($this->scenario->directory))->toBeFalse();
});
