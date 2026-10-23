<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Whmcs\WhmcsSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The whmcs simulator over its TLS port, as the public API reference describes it: credentials and the result/message
 * envelope, GetClients paging and filters, GetClientsDetails, the source time zone, and the paging scenarios the
 * connector must survive (a last partial page, many clients, a client added or removed mid-walk).
 */
beforeEach(function () {
    $this->scenario = Processes::start(WhmcsSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * @param  array<string, mixed>  $params
 */
function whmcsRaw(array $params, bool $credentials = true): Response
{
    $all = $credentials ? ['identifier' => WhmcsSimulator::IDENTIFIER, 'secret' => WhmcsSimulator::SECRET, 'responsetype' => 'json', ...$params] : $params;

    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath()])->timeout(10)->asForm()->post(test()->scenario->apiUrl().'/includes/api.php', $all);
}

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function whmcs(string $action, array $params = []): array
{
    return whmcsRaw(['action' => $action, ...$params])->json();
}

function whmcsAct(string $name, array $input = []): array
{
    return test()->scenario->simulator()->actions()[$name]($input, test()->scenario);
}

/**
 * Walk GetClients page by page, as an importer would.
 *
 * @param  array<string, mixed>  $params
 * @return list<int> the ids in the order they arrived
 */
function whmcsWalk(int $size, array $params = [], ?Closure $between = null): array
{
    $ids = [];

    for ($start = 0, $page = 1; ; $start += $size, $page++) {
        $answer = whmcs('GetClients', ['limitstart' => $start, 'limitnum' => $size, ...$params]);
        array_push($ids, ...array_column($answer['clients']['client'], 'id'));

        if ($answer['numreturned'] < $size || $page > 500) {
            return $ids;
        }

        $between?->__invoke($page);
    }
}

test('credentials are required, wrong ones fail, and errors are result=error inside HTTP 200', function () {
    $none = whmcsRaw(['action' => 'GetClients'], credentials: false);
    $wrong = whmcsRaw(['action' => 'GetClients', 'identifier' => WhmcsSimulator::IDENTIFIER, 'secret' => 'nope']);

    expect($none->status())->toBe(200)
        ->and($none->json())->toBe(['result' => 'error', 'message' => 'Authentication Failed'])
        ->and($wrong->json()['result'])->toBe('error')
        ->and(whmcs('GetClients')['result'])->toBe('success');

    $aliased = whmcsRaw(['action' => 'WhmcsDetails', 'username' => WhmcsSimulator::IDENTIFIER, 'password' => WhmcsSimulator::SECRET], credentials: false)->json();
    expect($aliased['whmcs']['version'])->toBe(WhmcsSimulator::VERSION)
        ->and(whmcs('NotAnAction')['result'])->toBe('error')
        ->and(whmcsRaw(['identifier' => WhmcsSimulator::IDENTIFIER, 'secret' => WhmcsSimulator::SECRET])->json()['result'])->toBe('error');
});

test('the log never holds the secret or the access key', function () {
    whmcsAct('configure', ['access_key' => 'key-123456789']);
    whmcsRaw(['action' => 'GetClients', 'accesskey' => 'key-123456789']);

    $log = json_encode($this->scenario->log()->entries());

    expect($log)->not->toContain(WhmcsSimulator::SECRET)->not->toContain('key-123456789');
});

test('an IP restriction refuses every call unless the access key is sent', function () {
    whmcsAct('configure', ['ip_restricted' => true, 'access_key' => 'key-123456789']);

    expect(whmcs('GetClients')['message'])->toStartWith('Invalid IP')
        ->and(whmcs('GetClients', ['accesskey' => 'wrong'])['result'])->toBe('error')
        ->and(whmcs('GetClients', ['accesskey' => 'key-123456789'])['result'])->toBe('success');

    whmcsAct('configure', ['ip_restricted' => false]);
    expect(whmcs('GetClients')['result'])->toBe('success');
});

test('an API role can deny an action', function () {
    whmcsAct('configure', ['denied_actions' => ['getclientsdetails']]);

    expect(whmcs('GetClientsDetails', ['clientid' => 1])['message'])->toContain('GetClientsDetails')
        ->and(whmcs('GetClients')['result'])->toBe('success');
});

test('GetClients pages with limitstart and limitnum and reports the totals', function () {
    whmcsAct('seed_clients', ['count' => 52]);

    $default = whmcs('GetClients');
    $second = whmcs('GetClients', ['limitstart' => 50, 'limitnum' => 10]);
    $past = whmcs('GetClients', ['limitstart' => 500]);

    expect($default['totalresults'])->toBe(57)
        ->and($default['startnumber'])->toBe(0)
        ->and($default['numreturned'])->toBe(25)
        ->and(array_column($default['clients']['client'], 'id'))->toBe(range(1, 25))
        ->and($second['startnumber'])->toBe(50)
        ->and($second['numreturned'])->toBe(7)
        ->and(array_column($second['clients']['client'], 'id'))->toBe(range(51, 57))
        ->and($past['numreturned'])->toBe(0)
        ->and($past['clients']['client'])->toBe([])
        ->and($past['totalresults'])->toBe(57);
});

test('a large list walks to the last partial page with every id once', function () {
    whmcsAct('seed_clients', ['count' => 2_495]);

    $ids = whmcsWalk(100);

    expect($ids)->toHaveCount(2_500)->and($ids)->toBe(range(1, 2_500));
});

test('status, search and ordering narrow and sort the list; totalresults counts the match', function () {
    whmcsAct('seed_clients', ['count' => 1, 'overrides' => ['firstname' => 'Zed', 'lastname' => 'Aaa', 'email' => 'zed.aaa@example.test', 'companyname' => 'Moon Hosting', 'status' => 'Inactive']]);

    $inactive = whmcs('GetClients', ['status' => 'Inactive', 'limitnum' => 100]);
    $byNameAsc = whmcs('GetClients', ['orderby' => 'lastname', 'limitnum' => 2]);
    $byNameDesc = whmcs('GetClients', ['orderby' => 'lastname', 'sorting' => 'DESC', 'limitnum' => 3]);
    $byCompany = whmcs('GetClients', ['search' => 'moon']);
    $byEmail = whmcs('GetClients', ['search' => 'zed.aaa']);

    expect(array_unique(array_column($inactive['clients']['client'], 'status')))->toBe(['Inactive'])
        ->and($inactive['totalresults'])->toBe(count($inactive['clients']['client']))
        ->and(array_column($byNameAsc['clients']['client'], 'lastname'))->toBe(['Aaa', 'Shrestha'])
        ->and(array_column($byNameDesc['clients']['client'], 'id'))->toBe([1, 2, 3])
        ->and(array_column($byCompany['clients']['client'], 'id'))->toBe([6])
        ->and($byEmail['totalresults'])->toBe(1)
        ->and(whmcs('GetClients', ['orderby' => 'datecreated', 'sorting' => 'DESC', 'limitnum' => 1])['clients']['client'][0]['id'])->toBe(6);
});

test('a client added mid-walk that sorts first is never fetched and one row repeats; the totals show the change', function () {
    whmcsAct('seed_clients', ['count' => 45]);
    whmcsAct('schedule_change', ['at_call' => 2, 'add_count' => 1, 'overrides' => ['firstname' => 'Aaron', 'lastname' => 'Aaa']]);

    $seen = [];
    $totals = [];

    for ($start = 0; $start < 60; $start += 10) {
        $answer = whmcs('GetClients', ['limitstart' => $start, 'limitnum' => 10, 'orderby' => 'firstname']);
        $totals[] = $answer['totalresults'];
        array_push($seen, ...array_column($answer['clients']['client'], 'id'));
    }

    expect(array_unique($totals))->toBe([50, 51])
        ->and(count($seen))->toBe(51)
        ->and(count(array_unique($seen)))->toBe(50)
        ->and($seen)->not->toContain(51);
});

test('a client added mid-walk by id order lands on the last page; one removed makes the walk skip a row', function () {
    whmcsAct('seed_clients', ['count' => 45]);
    whmcsAct('schedule_change', ['at_call' => 3, 'add_count' => 3, 'remove_ids' => [5]]);

    $ids = whmcsWalk(10);

    expect($ids)->toContain(51, 52, 53)->and($ids)->toContain(5)
        ->and(array_values(array_diff(range(1, 53), [5], $ids)))->toBe([21]);
});

test('GetClientsDetails finds a client by id or email and answers errors for the rest', function () {
    $byId = whmcs('GetClientsDetails', ['clientid' => 2]);
    $byEmail = whmcs('GetClientsDetails', ['email' => strtoupper($byId['email'])]);

    expect($byId['client']['id'])->toBe(2)
        ->and($byId['client']['email'])->toBe($byId['email'])
        ->and($byEmail['client']['id'])->toBe(2)
        ->and($byId['users'][0]['is_owner'])->toBeTrue()
        ->and($byId)->not->toHaveKey('stats')
        ->and(whmcs('GetClientsDetails', ['clientid' => 2, 'stats' => 'true']))->toHaveKey('stats')
        ->and(whmcs('GetClientsDetails', ['clientid' => 999])['result'])->toBe('error')
        ->and(whmcs('GetClientsDetails')['result'])->toBe('error');
});

test('times are shown in the source zone, which the scenario sets', function () {
    // 2026-03-01 03:30 UTC is still 28 February in New York, and 09:15 in Kathmandu.
    $created = gmmktime(3, 30, 0, 3, 1, 2026);
    whmcsAct('seed_clients', ['overrides' => ['created_at' => $created, 'lastlogin_at' => $created]]);

    $new = whmcs('GetClients', ['search' => '', 'limitstart' => 5, 'limitnum' => 1])['clients']['client'][0];
    $login = whmcs('GetClientsDetails', ['clientid' => $new['id']])['lastlogin'];

    expect($new['datecreated'])->toBe('2026-02-28')->and($login)->toBe('2026-02-28 22:30:00');

    whmcsAct('configure', ['timezone' => 'Asia/Kathmandu']);
    $later = whmcs('GetClients', ['limitstart' => 5, 'limitnum' => 1])['clients']['client'][0];

    expect($later['datecreated'])->toBe('2026-03-01')
        ->and(whmcs('GetClientsDetails', ['clientid' => $new['id']])['lastlogin'])->toBe('2026-03-01 09:15:00')
        ->and(fn () => whmcsAct('configure', ['timezone' => 'Mars/Base']))->toThrow(InvalidArgumentException::class);

    // A client that never logged in shows the zero date.
    expect(whmcs('GetClientsDetails', ['clientid' => 3])['lastlogin'])->toBe('0000-00-00 00:00:00');
});

test('response quirks a connector must normalise can be switched on', function () {
    whmcsAct('configure', ['single_item_as_object' => true, 'stringify_numbers' => true]);

    $one = whmcs('GetClients', ['limitnum' => 1]);
    $two = whmcs('GetClients', ['limitnum' => 2]);

    expect($one['clients']['client']['id'])->toBe('1')
        ->and($one['totalresults'])->toBe('5')
        ->and(array_is_list($two['clients']['client']))->toBeTrue();
});

test('faults and the reset action work on this simulator', function () {
    $this->scenario->faults()->add('get_clients', Fault::rateLimit(2), 1);

    expect(whmcsRaw(['action' => 'GetClients'])->status())->toBe(429)
        ->and(whmcs('GetClients')['result'])->toBe('success');

    whmcsAct('seed_clients', ['count' => 3]);
    whmcsAct('reset');

    expect(whmcs('GetClients')['totalresults'])->toBe(5);
});
