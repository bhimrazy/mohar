<?php

use Mohar\Simulators\Kernel\CallbackDispatcher;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\SimulatorRegistry;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->scenario = Scenario::create(EchoSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

test('a scenario lives in its own temp directory with its own state, CA and ports, never port 8000', function () {
    expect($this->scenario->directory)->toStartWith(Scenario::root().'/echo-')
        ->and(Scenario::root())->toMatch('#^'.preg_quote(sys_get_temp_dir(), '#').'/mohar-sim/[0-9a-f]{16}(-w[A-Za-z0-9_-]+)?$#')
        ->and(is_file($this->scenario->ca()->bundlePath()))->toBeTrue()
        ->and(is_file($this->scenario->ca()->leafPath()))->toBeTrue()
        ->and($this->scenario->state()->get('config', 'webhook_secret'))->toBe('whsec_echo')
        ->and([$this->scenario->apiPort(), $this->scenario->backendPort(), $this->scenario->controlPort()])->not->toContain(8000)
        ->and(Scenario::FORBIDDEN_PORTS)->toContain(8000);
});

test('the scenario CA signs a leaf for 127.0.0.1 and localhost, and rotating it gives a new key', function () {
    $leaf = openssl_x509_parse((string) file_get_contents($this->scenario->ca()->leafPath()));
    $before = openssl_pkey_get_details(openssl_pkey_get_public((string) file_get_contents($this->scenario->ca()->leafPath())))['key'];

    $this->scenario->ca()->rotateLeaf();
    $after = openssl_pkey_get_details(openssl_pkey_get_public((string) file_get_contents($this->scenario->ca()->leafPath())))['key'];

    expect($leaf['extensions']['subjectAltName'])->toContain('IP Address:127.0.0.1')->toContain('DNS:localhost')
        ->and(openssl_x509_verify((string) file_get_contents($this->scenario->ca()->leafPath()), openssl_pkey_get_public((string) file_get_contents($this->scenario->ca()->bundlePath()))))->toBe(1)
        ->and($after)->not->toBe($before);
});

test('a fault planned for the nth call fires once; one without nth fires on every call', function () {
    $faults = $this->scenario->faults();
    $faults->add('create_item', Fault::status(503), nth: 2);
    $faults->add('get_item', Fault::latency(10));

    expect($faults->for('create_item', 1))->toBeNull()
        ->and($faults->for('create_item', 2)?->type)->toBe('status')
        ->and($faults->for('create_item', 2))->toBeNull()
        ->and($faults->for('get_item', 1)?->type)->toBe('latency')
        ->and($faults->for('get_item', 9)?->type)->toBe('latency')
        ->and(fn () => new Fault('meltdown'))->toThrow(InvalidArgumentException::class);
});

test('callbacks are delivered as signed, replayed, out of order, stale or with the wrong secret', function () {
    $outbox = $this->scenario->outbox();
    $first = $outbox->enqueue('https://app.test/hooks', '{"n":1}');
    $outbox->enqueue('https://app.test/hooks', '{"n":2}');
    $sent = [];
    $transport = function (string $url, string $body, array $headers) use (&$sent): int {
        $sent[] = [$body, $headers['Echo-Signature']];

        return 200;
    };

    expect(array_column($outbox->pending(newestFirst: true), 'body'))->toBe(['{"n":2}', '{"n":1}']);

    $delivery = $outbox->pending()[0];
    $outbox->deliver($delivery, $transport);
    $outbox->deliver($delivery, $transport);
    $outbox->deliver($delivery, $transport, 'stale');
    $outbox->deliver($delivery, $transport, 'wrong_secret');

    [$t] = sscanf(explode(',', $sent[2][1])[0], 't=%d');
    expect($delivery['id'])->toBe($first)
        ->and($sent[0])->toBe($sent[1])
        ->and($t)->toBeLessThanOrEqual(time() - CallbackDispatcher::STALE_SECONDS)
        ->and($sent[3][1])->not->toContain(hash_hmac('sha256', explode(',', $sent[3][1])[0].'.{"n":1}', 'whsec_echo'))
        ->and(array_column($outbox->pending(), 'body'))->toBe(['{"n":2}']);
});

test('the recorder keeps redacted responses with their source, and the shape check names differences', function () {
    $recorder = new Recorder($this->scenario->directory.'/recordings');
    $path = $recorder->record('create_item', ['id' => 'item_1', 'secret' => 'sk_live_x', 'tags' => [['n' => 1]]], 'docs');
    $saved = json_decode((string) file_get_contents($path), true);

    expect($saved['source'])->toBe('docs')
        ->and($saved['response']['secret'])->toBe('[redacted]')
        ->and(Recorder::shape(['a' => 1, 'b' => [['c' => 'x']]]))->toBe(['a' => 'int', 'b' => 'list', 'b.*' => 'object', 'b.*.c' => 'string'])
        ->and(Recorder::differences(['a' => 1, 'b' => 'x'], ['a' => '1', 'c' => true]))->toBe(['a is string, recorded int', 'missing b', 'extra c']);
});

test('the registry knows simulators by key and names the installed ones when asked for another', function () {
    $registry = new SimulatorRegistry;
    $registry->register(EchoSimulator::class);

    expect($registry->get('echo'))->toBe(EchoSimulator::class)
        ->and(fn () => $registry->get('stripe'))->toThrow(InvalidArgumentException::class, 'installed: echo')
        ->and(fn () => $registry->register(stdClass::class))->toThrow(InvalidArgumentException::class);
});
