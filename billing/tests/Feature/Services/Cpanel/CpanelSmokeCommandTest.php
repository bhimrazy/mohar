<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Cpanel\Whm;

beforeEach(function () {
    $this->tokenFile = tempnam(sys_get_temp_dir(), 'whm');
    file_put_contents($this->tokenFile, Whm::TOKEN);
});

afterEach(fn () => @unlink($this->tokenFile));

test('the smoke command refuses to run without an explicit host and token, or a lifecycle run without its confirmation', function () {
    Whm::fake();

    $this->artisan('provisioning:cpanel-smoke', ['--token-file' => $this->tokenFile])->assertFailed();
    $this->artisan('provisioning:cpanel-smoke', ['--host' => Whm::HOST, '--no-interaction' => true])->assertFailed();
    $this->artisan('provisioning:cpanel-smoke', ['--host' => Whm::HOST, '--token-file' => $this->tokenFile, '--lifecycle' => true, '--domain' => 'smoke.example.test', '--package' => 'root_basic'])
        ->expectsOutputToContain('--confirm-lifecycle')
        ->assertFailed();

    Http::assertNothingSent();
});

test('without --lifecycle it only reads', function () {
    Whm::fake();

    $this->artisan('provisioning:cpanel-smoke', ['--host' => Whm::HOST, '--token-file' => $this->tokenFile])->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('a lifecycle run changes and removes only its own throwaway account, and records nothing secret', function () {
    // A pretend server that remembers the smoke account's state, so every check after a step can pass.
    $state = ['user' => null, 'suspended' => 0, 'removed' => false];
    $account = function () use (&$state): array {
        return ['user' => $state['user'], 'domain' => 'smoke.example.test', 'owner' => 'root', 'plan' => 'root_basic', 'suspended' => $state['suspended']];
    };
    Whm::fake([
        'createacct' => function (Request $request) use (&$state) {
            $state['user'] = $request->data()['username'];

            return Http::response(Whm::fixture('createacct_ok'));
        },
        'suspendacct' => function () use (&$state) {
            $state['suspended'] = 1;

            return Http::response(Whm::fixture('suspendacct_ok'));
        },
        'removeacct' => function () use (&$state) {
            $state['removed'] = true;

            return Http::response(Whm::fixture('removeacct_ok'));
        },
        'accountsummary' => fn () => Http::response(Whm::fixture('accountsummary', ['data' => ['acct' => [0 => $account()]]])),
        'listaccts' => function (Request $request) use (&$state, $account) {
            $search = Whm::params($request)['search'] ?? null;

            return Http::response(match (true) {
                $search === null => Whm::fixture('listaccts_example'),
                $state['removed'] || $search !== $state['user'] => Whm::fixture('listaccts_empty'),
                default => ['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => [$account()]]],
            });
        },
    ]);
    $directory = sys_get_temp_dir().'/whm-smoke-'.Str::random(6);

    $this->artisan('provisioning:cpanel-smoke', [
        '--host' => Whm::HOST, '--token-file' => $this->tokenFile, '--lifecycle' => true, '--confirm-lifecycle' => true,
        '--domain' => 'smoke.example.test', '--package' => 'root_basic', '--package2' => 'root_pro', '--record' => $directory,
    ])->expectsConfirmation('The server holds 1 accounts. The smoke test creates and removes only its own mhsmk account. Continue?', 'yes')
        ->expectsOutputToContain('Session for whm.example.test expires')
        ->doesntExpectOutputToContain('cpsess')
        ->assertSuccessful();

    $created = Whm::sent('createacct')[0]->data()['username'];
    expect($created)->toMatch('/^mhsmk[a-z0-9]{3}$/')
        ->and(array_map(fn ($request) => $request->data()['username'], Whm::sent('removeacct')))->toBe([$created])
        ->and(Whm::sent('changepackage')[0]->data()['pkg'])->toBe('root_pro');

    $recorded = (string) file_get_contents(glob("{$directory}/*.json")[0]);
    expect($recorded)->not->toContain(Whm::TOKEN)->and($recorded)->toContain('createacct');
});
