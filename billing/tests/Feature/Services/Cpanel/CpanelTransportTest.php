<?php

use App\Data\Services\Provisioning\PreviousPackage;
use App\Enums\DriverOutcome;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmClient;
use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmFailure;
use App\Modules\Services\Models\Server;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\Redactor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

beforeEach(function () {
    $this->driver = app(CpanelDriver::class);
});

test('every call goes to the WHM JSON API over TLS with api.version=1 and the token header', function () {
    Whm::fake();

    $this->driver->testConnection(Whm::server());

    $request = Whm::sent('version')[0];
    expect($request->url())->toStartWith('https://'.Whm::HOST.':2087/json-api/version?')
        ->and(Whm::params($request))->toMatchArray(['api.version' => '1'])
        ->and($request->header('Authorization'))->toBe(['whm root:'.Whm::TOKEN]);
});

test('mutating calls are form posts, and password sign-in uses HTTP Basic', function () {
    Whm::fake();
    $server = Whm::server(['login_method' => 'password']);

    $this->driver->suspend(Whm::context(server: $server), 'Overdue invoice');

    $request = Whm::sent('suspendacct')[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toContain('api.version=1')
        ->and($request->data())->toMatchArray(['user' => 'example'])
        ->and($request->header('Authorization')[0])->toBe('Basic '.base64_encode('root:Fake-Root-Password-7'));
});

test('a password login that WHM answers with a two-factor demand says to use a token', function () {
    Whm::fake(['version' => Http::response('<p>This login requires two-factor authentication (TFA).</p>', 401)]);

    $check = $this->driver->testConnection(Whm::server(['login_method' => 'password']));

    expect($check->ok)->toBeFalse()
        ->and($check->message)->toContain('two-factor')->toContain('API token');
});

test('plain-HTTP ports are refused before anything is sent, and cannot be saved', function () {
    Whm::fake();

    $result = $this->driver->create(Whm::context(server: Whm::server(port: 2086)));

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toContain('Port 2086 is not allowed');
    Http::assertNothingSent();

    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.store'), [
        'name' => 'Plain', 'hostname' => 'whm.example.test', 'driver' => 'cpanel', 'port' => 2086,
        'max_accounts' => 0, 'monthly_cost' => 0, 'currency' => 'USD',
    ])->assertSessionHasErrors('port');
    expect(Server::query()->where('name', 'Plain')->exists())->toBeFalse();
});

test('hostnames resolving to loopback or link-local addresses are refused before connecting', function (string $ip) {
    Whm::fake(resolvesTo: $ip);

    expect($this->driver->create(Whm::context())->outcome())->toBe(DriverOutcome::Rejected)
        ->and($this->driver->testConnection(Whm::server())->ok)->toBeFalse();
    Http::assertNothingSent();
})->with(['127.0.0.1', '169.254.169.254', '::1']);

test('loopback is allowed only when the install policy allows it, and private addresses by default', function () {
    config(['integrations.outbound.allow_loopback' => true]);
    Whm::fake(resolvesTo: '127.0.0.1');
    expect($this->driver->testConnection(Whm::server())->ok)->toBeTrue();

    config(['integrations.outbound.allow_loopback' => false]);
    Whm::fake(resolvesTo: '10.0.0.5');
    expect($this->driver->testConnection(Whm::server())->ok)->toBeTrue();

    config(['integrations.outbound.allow_private_addresses' => false]);
    expect($this->driver->testConnection(Whm::server())->ok)->toBeFalse();
});

test('the hostname is resolved once per operation, so a changed DNS answer cannot redirect later calls', function () {
    Whm::fake();
    $lookups = 0;
    app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(function () use (&$lookups): array {
        $lookups++;

        return $lookups === 1 ? [Whm::IP] : ['169.254.169.254'];
    }));

    $result = $this->driver->create(Whm::context());

    expect($result->success)->toBeTrue()
        ->and($lookups)->toBe(1)
        ->and(count(Http::recorded()))->toBeGreaterThan(2);
});

test('connecting by IP address needs a trusted certificate pin', function () {
    Whm::fake();

    $result = $this->driver->create(Whm::context(server: Whm::server(hostname: '')));

    expect($result->outcome())->toBe(DriverOutcome::Rejected)
        ->and($result->message)->toContain('trust the certificate');
    Http::assertNothingSent();
});

test('transport failures map to the outcome vocabulary', function (mixed $answer, DriverOutcome $outcome, string $message) {
    Whm::fake(['createacct' => $answer]);

    $result = $this->driver->create(Whm::context());

    expect($result->outcome())->toBe($outcome)
        ->and($result->message)->toContain($message);
})->with([
    'DNS failure' => [fn () => Http::failedConnection('cURL error 6: Could not resolve host: whm.example.test'), DriverOutcome::Retryable, 'Could not connect'],
    'connection refused' => [fn () => Http::failedConnection('cURL error 7: Failed to connect to whm.example.test port 2087: Connection refused'), DriverOutcome::Retryable, 'Could not connect'],
    'connect timeout' => [fn () => Http::failedConnection('cURL error 28: Connection timed out after 10001 milliseconds'), DriverOutcome::Retryable, 'in time'],
    'untrusted certificate' => [fn () => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate'), DriverOutcome::Rejected, 'not trusted'],
    'TLS handshake' => [fn () => Http::failedConnection('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL'), DriverOutcome::Rejected, 'not trusted'],
    'pin mismatch' => [fn () => Http::failedConnection('cURL error 90: SSL: public key does not match pinned public key'), DriverOutcome::Rejected, 'Server certificate changed'],
    'read timeout after sending' => [fn () => Http::failedConnection('cURL error 28: Operation timed out after 50001 milliseconds with 0 bytes received'), DriverOutcome::Unknown, 'may have run'],
    'connection reset after sending' => [fn () => Http::failedConnection('cURL error 56: Recv failure: Connection reset by peer'), DriverOutcome::Unknown, 'may have run'],
    'HTTP 500' => [fn () => Http::response('Internal Server Error', 500), DriverOutcome::Unknown, 'HTTP 500'],
    'truncated body' => [fn () => Http::response(Whm::raw('truncated-body.txt')), DriverOutcome::Unknown, 'did not answer like the WHM API'],
    'HTML 401' => [fn () => Http::response(Whm::raw('http_401.html'), 401), DriverOutcome::Rejected, 'credentials'],
    'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.test/']), DriverOutcome::Rejected, 'redirect'],
    'access denied' => ['access_denied', DriverOutcome::Rejected, 'permission (ACL)'],
    'licence' => ['license_invalid', DriverOutcome::Rejected, 'licence'],
    'unrecognised reason' => ['unrecognised', DriverOutcome::Rejected, 'WHM refused createacct'],
]);

test('the same failures on a read are retryable, never unknown', function (mixed $answer) {
    Whm::fake(['listaccts' => $answer]);

    try {
        $this->driver->usage(Whm::server());
        $this->fail('usage() should throw');
    } catch (WhmFailure $failure) {
        expect($failure->outcome)->toBe(DriverOutcome::Retryable);
    }
})->with([
    'read timeout' => [fn () => Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received')],
    'HTTP 503' => [fn () => Http::response('busy', 503)],
    'truncated body' => [fn () => Http::response(Whm::raw('truncated-body.txt'))],
]);

test('module log entries never hold the token, passwords, session values or long raw output', function () {
    Whm::fake();
    $context = Whm::context();
    $redactor = new Redactor;
    $logged = [];

    foreach ([
        $this->driver->create($context),
        $this->driver->changePassword($context, 'N3w-Secret-Pass!'),
        $this->driver->changePackage($context, new PreviousPackage),
    ] as $result) {
        $secrets = [...$context->secrets(), 'N3w-Secret-Pass!'];
        $logged[] = json_encode([$redactor->redact($result->request, $secrets), $redactor->redact($result->response, $secrets), $result->message]);
    }

    $this->driver->serviceSsoLink($context);
    $all = implode("\n", $logged);

    expect($all)->not->toContain(Whm::TOKEN)
        ->not->toContain(Whm::PASSWORD)
        ->not->toContain('N3w-Secret-Pass!')
        ->not->toContain('cpsess')
        ->not->toContain('CREATE_USER_SESSION');

    $output = collect(json_decode($logged[0], true)[1]['calls'])->firstWhere('function', 'createacct')['output'];
    expect(strlen($output))->toBeLessThanOrEqual(4096)
        ->and(Whm::sent('createacct')[0]->data())->toHaveKey('password');
    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), Whm::PASSWORD));
});

test('a call with no time left in the operation is not sent, so it stays safely retryable', function () {
    Whm::fake();
    $client = new WhmClient(Whm::server(), app(OutboundAddressGuard::class), microtime(true) + 1);

    expect(fn () => $client->write('createacct', ['username' => 'example']))
        ->toThrow(fn (WhmFailure $failure) => expect($failure->outcome)->toBe(DriverOutcome::Retryable));
    Http::assertNothingSent();
});
