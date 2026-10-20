<?php

/*
 * Probes from the PR #100 review (ADR 0016 S2). The first four fail at 0f7f4c8 and pin the fixes; the rest pin
 * protections that hold today.
 */

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\RecordingScanner;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Whm\LiveRecording;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Mohar\Simulators\SimulatorRegistry;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

/** A recording script that writes one leaking recording and then fails, as a live run that loses the server midway. */
final class ProbeHalfRecordingSimulator implements RecordsLive, Simulator
{
    public function key(): string
    {
        return 'probehalf';
    }

    public function reference(): array
    {
        return ['url' => 'https://example.test', 'version' => '1'];
    }

    public function routes(): array
    {
        return [];
    }

    public function seed(Scenario $scenario): void {}

    public function appConfiguration(Scenario $scenario): array
    {
        return ['env' => [], 'notes' => []];
    }

    public function operations(): array
    {
        return [];
    }

    public function recordLive(Recorder $recorder): void
    {
        $recorder->record('listaccts', ['data' => ['acct' => [['contact' => 'anna@alpha-books.com', 'server' => '8.8.8.8']]]], 'live');

        throw new RuntimeException('The server stopped answering.');
    }
}

test('simulate:record scans and removes what it wrote even when the recording run fails midway', function () {
    $directory = base_path('tools/simulators/probehalf');
    app(SimulatorRegistry::class)->register(ProbeHalfRecordingSimulator::class);

    try {
        $this->artisan('simulate:record', ['provider' => 'probehalf'])->assertFailed();

        expect(glob($directory.'/recordings/*.json') ?: [])->toBe([]);
    } finally {
        array_map('unlink', glob($directory.'/recordings/*') ?: []);
        @rmdir($directory.'/recordings');
        @rmdir($directory);
    }
});

test('a live listaccts row keeps no public IPv6 address or linked-node hostname', function () {
    $row = ['user' => 'anna', 'domain' => 'alpha-books.com', 'ip' => '8.8.4.4', 'ipv6' => ['2001:4860:4860::8888'], 'child_nodes' => [['alias' => 'mail-node', 'ipv4_address' => '8.8.8.8', 'hostname' => 'mx1.alpha-books.com']], 'plan' => 'anna_gold'];
    $directory = sys_get_temp_dir().'/probe-rec-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $path = (new Recorder($directory))->record('listaccts', LiveRecording::anonymise(['data' => ['acct' => [$row]]]), 'live');
        $saved = (string) file_get_contents($path);

        expect($saved)->not->toContain('2001:4860')
            ->and($saved)->not->toContain('alpha-books')
            ->and($saved)->not->toContain('8.8.8.8');
    } finally {
        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

test('the secret scan flags a cPanel session path', function () {
    $directory = sys_get_temp_dir().'/probe-scan-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/session.json', json_encode(['source' => 'live', 'response' => ['data' => ['note' => 'https://example.test:2087/cpsess1234567890/login/']]]));

    try {
        expect((new RecordingScanner)->scanFile($directory.'/session.json'))->not->toBe([]);
    } finally {
        unlink($directory.'/session.json');
        rmdir($directory);
    }
});

test('a state-store transaction can use increment (nested work, as S3 ids inside an idempotency check)', function () {
    $scenario = Scenario::create(EchoSimulator::class);

    try {
        $id = $scenario->state()->transaction(fn (): int => $scenario->state()->increment('ids', 'probe'));

        expect($id)->toBe(1);
    } finally {
        Processes::stop($scenario);
    }
});

describe('against a running whm scenario', function () {
    beforeEach(function () {
        $this->scenario = Processes::start(WhmSimulator::class);
        $this->call = fn (string $function, array $params = [], string $user = 'root', string $token = WhmSimulator::ROOT_TOKEN) => Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])
            ->withHeaders(['Authorization' => "whm {$user}:{$token}"])->asForm()->post($this->scenario->apiUrl()."/json-api/{$function}?api.version=1", $params);
    });

    afterEach(fn () => Processes::stop($this->scenario));

    test('a login link is single use even when followed concurrently', function () {
        $url = ($this->call)('create_user_session', ['user' => 'alpha', 'service' => 'cpaneld'])->json('data.url');
        $responses = Http::pool(fn (Pool $pool) => array_map(fn () => $pool->withOptions(['verify' => $this->scenario->ca()->bundlePath()])->get($url), range(1, 8)));

        expect(count(array_filter($responses, fn ($response) => $response->status() === 200)))->toBe(1);
    });

    test('a reseller token with every ACL still cannot exceed the reseller\'s own privileges and scope', function () {
        WhmSimulator::issueToken($this->scenario, 'reseller1', 'SIMRESELLER0ALL0TOKEN0SCENARIO00', ['all']);
        $reseller = fn (string $function, array $params = []) => ($this->call)($function, $params, 'reseller1', 'SIMRESELLER0ALL0TOKEN0SCENARIO00');

        expect(array_column($reseller('listaccts')->json('data.acct'), 'user'))->toBe(['bravo'])
            ->and($reseller('createacct', ['username' => 'shopz', 'domain' => 'shopz.test', 'plan' => 'root_pro', 'password' => 'Fake-Account-Pass-42!'])->json('metadata.result'))->toBe(0)
            ->and($reseller('removeacct', ['username' => 'alpha'])->json('metadata.result'))->toBe(0)
            ->and($reseller('create_user_session', ['user' => 'root', 'service' => 'whostmgrd'])->json('metadata.result'))->toBe(0)
            ->and($reseller('myprivs')->json('data.privileges.0.all'))->toBe(0)
            ->and($this->scenario->state()->get('accounts', 'alpha'))->not->toBeNull()
            ->and($this->scenario->state()->get('accounts', 'shopz'))->toBeNull();
    });

    test('a token is bound to its user: another user\'s name with it is refused', function () {
        expect(($this->call)('version', [], 'reseller1', WhmSimulator::ROOT_TOKEN)->status())->toBe(401)
            ->and(($this->call)('version', [], 'root', WhmSimulator::RESELLER_TOKEN)->status())->toBe(401);
    });

    test('the request log never holds the token, the account password or the login session', function () {
        ($this->call)('createacct', ['username' => 'shopq', 'domain' => 'shopq.test', 'plan' => 'root_basic', 'password' => 'Probe-Account-Pass-77!']);
        $session = ($this->call)('create_user_session', ['user' => 'shopq', 'service' => 'cpaneld'])->json('data');
        Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->get($session['url']);
        $log = (string) json_encode($this->scenario->log()->entries());

        expect($log)->not->toContain(WhmSimulator::ROOT_TOKEN)
            ->and($log)->not->toContain('Probe-Account-Pass-77!')
            ->and($log)->not->toContain(explode(',', $session['session'])[1])
            ->and($log)->not->toContain(trim($session['cp_security_token'], '/'));
    });
});
