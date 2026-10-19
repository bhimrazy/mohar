<?php

use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmReasons;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The simulator over its real TLS port, checked against the driver's fixtures (tests/Fixtures/Cpanel, written from
 * the public reference and tagged source: docs): the same key paths and types, and refusals that the driver's
 * WhmReasons sorts into the same kind.
 */
beforeEach(function () {
    $this->scenario = Processes::start(WhmSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * One WHM API 1 call, verified against the scenario CA.
 *
 * @param  array<string, scalar>  $params
 */
function whmCall(string $function, array $params = [], string $user = 'root', string $token = WhmSimulator::ROOT_TOKEN, bool $post = false): Response
{
    $request = Http::withOptions(['verify' => test()->scenario->ca()->bundlePath()])->withHeaders(['Authorization' => "whm {$user}:{$token}"]);
    $url = test()->scenario->apiUrl()."/json-api/{$function}";

    return $post ? $request->asForm()->post($url.'?api.version=1', $params) : $request->get($url, ['api.version' => 1, ...$params]);
}

/**
 * @return array<string, mixed>
 */
function whmFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/Cpanel/{$name}.json")), true);
}

function resellerCall(string $function, array $params = [], bool $post = false): Response
{
    return whmCall($function, $params, 'reseller1', WhmSimulator::RESELLER_TOKEN, $post);
}

test('every call the driver makes answers in the shape of its fixture', function () {
    $create = ['username' => 'example', 'domain' => 'example-shop.test', 'plan' => 'root_basic', 'password' => 'Fake-Account-Pass-42!', 'contactemail' => 'owner@example-shop.test'];
    $calls = [
        'version' => fn () => whmCall('version'),
        'myprivs_root' => fn () => whmCall('myprivs'),
        'myprivs_reseller' => fn () => resellerCall('myprivs'),
        'api_token_get_details' => fn () => resellerCall('api_token_get_details', ['token' => WhmSimulator::RESELLER_TOKEN], post: true),
        'acctcounts' => fn () => resellerCall('acctcounts', ['user' => 'reseller1']),
        'listpkgs' => fn () => whmCall('listpkgs', ['want' => 'creatable']),
        'get_nameserver_config' => fn () => whmCall('get_nameserver_config'),
        'verify_new_username_ok' => fn () => whmCall('verify_new_username', ['user' => 'example']),
        'createacct_ok' => fn () => whmCall('createacct', $create, post: true),
        'listaccts_example' => fn () => whmCall('listaccts', ['searchtype' => 'user', 'searchmethod' => 'exact', 'search' => 'example']),
        'accountsummary' => fn () => whmCall('accountsummary', ['user' => 'example']),
        'showbw' => fn () => whmCall('showbw', ['month' => 10, 'year' => 2026]),
        'suspendacct_ok' => fn () => whmCall('suspendacct', ['user' => 'example', 'reason' => 'Overdue invoice'], post: true),
        'unsuspendacct_ok' => fn () => whmCall('unsuspendacct', ['user' => 'example'], post: true),
        'changepackage_ok' => fn () => whmCall('changepackage', ['user' => 'example', 'pkg' => 'root_pro'], post: true),
        'passwd_ok' => fn () => whmCall('passwd', ['user' => 'example', 'password' => 'An0ther-Strong-Pass!'], post: true),
        'create_user_session' => fn () => whmCall('create_user_session', ['user' => 'example', 'service' => 'cpaneld'], post: true),
        'removeacct_ok' => fn () => whmCall('removeacct', ['username' => 'example', 'keepdns' => 0], post: true),
    ];

    foreach ($calls as $fixture => $call) {
        $answer = $call()->json();

        expect(Recorder::differences(whmFixture($fixture), $answer))->toBe([], $fixture)
            ->and($answer['metadata']['result'])->toBe(1, $fixture);
    }
});

test('refusals use the reasons the driver classifies, before anything changes', function () {
    whmCall('createacct', ['username' => 'example', 'domain' => 'example-shop.test', 'plan' => 'root_basic', 'password' => 'Fake-Account-Pass-42!'], post: true);
    $strong = 'Fake-Account-Pass-42!';
    whmCall('suspendacct', ['user' => 'example', 'disallowun' => 1], post: true);
    WhmSimulator::issueToken($this->scenario, 'root', 'SIMNOCREATE0TOKEN0LOCAL0SCENARIO', ['list-accts']);

    $refusals = [
        'createacct_username_taken' => fn () => whmCall('createacct', ['username' => 'example', 'domain' => 'other.test', 'plan' => 'root_basic', 'password' => $strong], post: true),
        'createacct_domain_exists' => fn () => whmCall('createacct', ['username' => 'second', 'domain' => 'Example-Shop.test', 'plan' => 'root_basic', 'password' => $strong], post: true),
        'createacct_reserved' => fn () => whmCall('createacct', ['username' => 'testshop', 'domain' => 'shop.test', 'plan' => 'root_basic', 'password' => $strong], post: true),
        'createacct_package_not_allowed' => fn () => resellerCall('createacct', ['username' => 'shop', 'domain' => 'shop.test', 'plan' => 'root_pro', 'password' => $strong], post: true),
        'createacct_weak_password' => fn () => whmCall('createacct', ['username' => 'shop', 'domain' => 'shop.test', 'plan' => 'root_basic', 'password' => 'shop1'], post: true),
        'createacct_invalid_theme' => fn () => whmCall('createacct', ['username' => 'shop', 'domain' => 'shop.test', 'plan' => 'root_basic', 'password' => $strong, 'cpmod' => 'retro'], post: true),
        'access_denied' => fn () => whmCall('createacct', ['username' => 'shop', 'domain' => 'shop.test', 'plan' => 'root_basic', 'password' => $strong], token: 'SIMNOCREATE0TOKEN0LOCAL0SCENARIO', post: true),
        'accountsummary_missing' => fn () => whmCall('accountsummary', ['user' => 'nobody1']),
        'removeacct_missing' => fn () => whmCall('removeacct', ['username' => 'nobody1'], post: true),
        'suspendacct_already' => fn () => whmCall('suspendacct', ['user' => 'example'], post: true),
        'passwd_weak' => fn () => whmCall('passwd', ['user' => 'example', 'password' => 'abc'], post: true),
        'verify_new_username_taken' => fn () => whmCall('verify_new_username', ['user' => 'example']),
    ];

    foreach ($refusals as $fixture => $call) {
        $answer = $call()->json();

        expect(Recorder::differences(whmFixture($fixture), $answer))->toBe([], $fixture)
            ->and($answer['metadata']['result'])->toBe(0, $fixture)
            ->and(WhmReasons::classify($answer['metadata']['reason']))->toBe(WhmReasons::classify(whmFixture($fixture)['metadata']['reason']), $fixture);
    }

    expect(array_keys($this->scenario->state()->all('accounts')))->toBe(['alpha', 'bravo', 'example']);

    // A reseller cannot lift root's locked suspension of its own account.
    whmCall('suspendacct', ['user' => 'bravo', 'disallowun' => 1], post: true);
    expect(WhmReasons::classify(resellerCall('unsuspendacct', ['user' => 'bravo'], post: true)->json('metadata.reason')))->toBe(WhmReasons::classify(whmFixture('unsuspendacct_locked')['metadata']['reason']));

    $this->scenario->simulator()->actions()['set_licence'](['valid' => false], $this->scenario);
    expect(WhmReasons::classify(whmCall('createacct', ['username' => 'shop', 'domain' => 'shop.test', 'plan' => 'root_basic', 'password' => $strong], post: true)->json('metadata.reason')))->toBe(WhmReasons::LICENCE);
});

test('the username rules come from one table', function (string $username, ?string $kind) {
    $answer = whmCall('verify_new_username', ['user' => $username])->json('metadata');

    expect($answer['result'])->toBe($kind === null ? 1 : 0)
        ->and($kind === null ? null : WhmReasons::classify($answer['reason']))->toBe($kind);
})->with([
    'valid' => ['shop2026', null],
    'reserved name' => ['root', WhmReasons::RESERVED],
    'reserved prefix' => ['testing', WhmReasons::RESERVED],
    'reserved suffix' => ['mypassword', WhmReasons::RESERVED],
    'upper case' => ['Shop', WhmReasons::UNKNOWN],
    'starts with a digit' => ['1shop', WhmReasons::UNKNOWN],
    'too long' => [str_repeat('a', 17), WhmReasons::UNKNOWN],
    // Like the fixture, the reason names no kind: the driver acts on the result and then looks the account up.
    'taken' => ['alpha', WhmReasons::UNKNOWN],
]);

test('a reseller sees, changes and assigns only what it owns, root\'s packages only with viewglobalpackages, within its limit', function () {
    expect(array_column(resellerCall('listaccts')->json('data.acct'), 'user'))->toBe(['bravo'])
        ->and(resellerCall('accountsummary', ['user' => 'alpha'])->json('metadata.result'))->toBe(0)
        ->and(resellerCall('suspendacct', ['user' => 'alpha'], post: true)->json('metadata.result'))->toBe(0)
        ->and(array_column(resellerCall('listpkgs', ['want' => 'creatable'])->json('data.pkg'), 'name'))->toBe(['reseller1_starter'])
        ->and(resellerCall('createacct', ['username' => 'shopx', 'domain' => 'shopx.test', 'plan' => 'root_basic', 'password' => 'Fake-Account-Pass-42!'], post: true)->json('metadata.result'))->toBe(0)
        ->and(resellerCall('createacct', ['username' => 'shopx', 'domain' => 'shopx.test', 'plan' => 'reseller1_starter', 'ip' => 'y', 'password' => 'Fake-Account-Pass-42!'], post: true)->json('metadata.reason'))->toContain('delegated')
        ->and($this->scenario->state()->get('accounts', 'alpha')['suspended'])->toBe(0);

    foreach (['shopa', 'shopb'] as $user) {
        expect(resellerCall('createacct', ['username' => $user, 'domain' => "{$user}.test", 'plan' => 'reseller1_starter', 'password' => 'Fake-Account-Pass-42!'], post: true)->json('metadata.result'))->toBe(1);
    }

    // With "View Global Packages" on both the reseller and its token, root's packages become usable.
    $this->scenario->state()->put('users', 'reseller1', [...$this->scenario->state()->get('users', 'reseller1'), 'privileges' => [...WhmSimulator::RESELLER_ACLS, 'viewglobalpackages']]);
    WhmSimulator::issueToken($this->scenario, 'reseller1', WhmSimulator::RESELLER_TOKEN, [...WhmSimulator::RESELLER_ACLS, 'viewglobalpackages']);
    expect(array_column(resellerCall('listpkgs', ['want' => 'creatable'])->json('data.pkg'), 'name'))->toBe(['default', 'reseller1_starter', 'root_basic', 'root_pro']);

    expect(resellerCall('createacct', ['username' => 'shopc', 'domain' => 'shopc.test', 'plan' => 'root_basic', 'password' => 'Fake-Account-Pass-42!'], post: true)->json('metadata.reason'))->toContain('limit of 3')
        ->and(resellerCall('acctcounts', ['user' => 'reseller1'])->json('data.reseller'))->toMatchArray(['active' => 3, 'limit' => 3])
        ->and(whmCall('accountsummary', ['user' => 'shopa'])->json('data.acct.0.owner'))->toBe('reseller1');
});

test('a kept DNS zone blocks the domain until it is removed; paging counts from 1', function () {
    $create = fn (string $user) => whmCall('createacct', ['username' => $user, 'domain' => 'kept.test', 'plan' => 'root_basic', 'password' => 'Fake-Account-Pass-42!'], post: true)->json('metadata');

    expect($create('first')['result'])->toBe(1);
    whmCall('removeacct', ['username' => 'first', 'keepdns' => 1], post: true);
    expect(WhmReasons::classify($create('second')['reason']))->toBe(WhmReasons::DOMAIN_EXISTS);

    $this->scenario->state()->delete('zones', 'kept.test');
    expect($create('second')['result'])->toBe(1)
        ->and(array_column(whmCall('listaccts', ['api.chunk.enable' => 1, 'api.chunk.size' => 1, 'api.chunk.start' => 2])->json('data.acct'), 'user'))->toBe(['bravo']);
});

test('sign-in needs a valid token for its user, or the password; the token never exceeds its ACLs', function () {
    WhmSimulator::issueToken($this->scenario, 'root', 'SIMEXPIRED0TOKEN0LOCAL0SCENARIOX', ['all'], time() - 60);
    WhmSimulator::issueToken($this->scenario, 'root', 'SIMELSEWHERE0TOKEN0LOCAL0SCENARI', ['all'], whitelist: ['198.51.100.7']);

    expect(whmCall('version', token: 'WRONG0TOKEN')->status())->toBe(401)
        ->and(whmCall('version', user: 'reseller1')->status())->toBe(401)
        ->and(whmCall('version', token: 'SIMEXPIRED0TOKEN0LOCAL0SCENARIOX')->status())->toBe(401)
        ->and(whmCall('version', token: 'SIMELSEWHERE0TOKEN0LOCAL0SCENARI')->body())->toContain('allowed IP addresses')
        ->and(Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->withBasicAuth('root', WhmSimulator::ROOT_PASSWORD)->get($this->scenario->apiUrl().'/json-api/version?api.version=1')->json('data.version'))->toBe('11.124.0.12')
        ->and(Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->withHeaders(['Authorization' => 'whm root:'.WhmSimulator::ROOT_TOKEN])->get($this->scenario->apiUrl().'/json-api/version')->json())->toBe(['status' => 0, 'statusmsg' => 'WHM API 0 is not simulated; send api.version=1.'])
        ->and(whmCall('nosuchfunction')->json('metadata.result'))->toBe(0)
        ->and($this->scenario->log()->count('version'))->toBe(6);
});

test('a login link works once, in the browser, on the port the client used', function () {
    $url = whmCall('create_user_session', ['user' => 'alpha', 'service' => 'cpaneld'], post: true)->json('data.url');
    $browser = Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()]);

    expect($url)->toStartWith($this->scenario->apiUrl().'/cpsess')
        ->and($browser->get($url)->body())->toContain('Signed in to cPanel as alpha')
        ->and($browser->get($url)->status())->toBe(403)
        ->and(resellerCall('create_user_session', ['user' => 'alpha', 'service' => 'cpaneld'], post: true)->json('metadata.result'))->toBe(0);
});
