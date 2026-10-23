<?php

use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\TldAttributes;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Enums\ReconcileStatus;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapClient;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapContacts;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapDriver;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The Namecheap driver's wire format and response mapping against hand-written XML shaped like the documented answers
 * (registrars.md §4, §7). The working scenarios against a stateful account run in
 * tools/simulators/tests/Namecheap/NamecheapDriverAgainstSimulatorTest.php.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    ncResetBudget();
});

/** The local request budget counts per API user in the cache; every scenario starts with a full one. */
function ncResetBudget(): void
{
    foreach (['sandbox:sandbox-user', 'live:live-user'] as $account) {
        foreach ([60, 3600, 86400] as $window) {
            RateLimiter::clear('namecheap:'.explode(':', $account)[0].':'.sha1(explode(':', $account)[1]).':'.$window);
        }
    }
}

function ncConfig(bool $test = true, array $settings = []): RegistrarConfig
{
    return new RegistrarConfig(NamecheapDriver::KEY, [
        'api_user' => 'live-user', 'api_key' => 'LIVE-KEY-1', 'client_ip' => '203.0.113.10',
        'sandbox_api_user' => 'sandbox-user', 'sandbox_api_key' => 'SANDBOX-KEY-2', ...$settings,
    ], $test, ['api_key', 'sandbox_api_key', 'http_proxy']);
}

function ncDriver(): NamecheapDriver
{
    return app(NamecheapDriver::class);
}

/** The documented envelope around a command's result (the API's namespace included). */
function ncXml(string $command, string $result = '', array $errors = []): string
{
    $status = $errors === [] ? 'OK' : 'ERROR';
    $list = $errors === [] ? '<Errors />' : '<Errors>'.implode('', array_map(fn (int $number): string => '<Error Number="'.$number.'">Message for '.$number.'</Error>', $errors)).'</Errors>';

    return '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="'.$status.'" xmlns="http://api.namecheap.com/xml.response">'.$list.'<Warnings />'
        .'<RequestedCommand>'.$command.'</RequestedCommand>'.($errors === [] ? '<CommandResponse Type="'.$command.'">'.$result.'</CommandResponse>' : '').'<Server>X</Server></ApiResponse>';
}

/**
 * Fake the API by command: a string is the answer, a list is one answer per call, a Closure gets the request.
 *
 * @param  array<string, string|list<string>|Closure>  $answers
 */
function ncFake(array $answers): void
{
    $calls = [];
    ncResetBudget();

    ncHttp(function (Request $request) use ($answers, &$calls) {
        $command = (string) ($request['Command'] ?? '');
        $answer = $answers[$command] ?? throw new RuntimeException("No fake answer for {$command}");
        $answer = is_array($answer) ? $answer[min($calls[$command] = ($calls[$command] ?? -1) + 1, count($answer) - 1)] : $answer;
        $answer = $answer instanceof Closure ? $answer($request) : $answer;

        return $answer instanceof Throwable ? throw $answer : Http::response($answer);
    });
}

/** A fresh fake: Http::fake() adds to the stubs already set, so a test that changes its answers starts over. */
function ncHttp(Closure|array $stubs = []): void
{
    app()->forgetInstance(Factory::class);
    Facade::clearResolvedInstance(Factory::class);
    Http::preventStrayRequests();
    Http::fake($stubs);
}

function ncSent(string $command): ?Request
{
    $found = Http::recorded(fn (Request $request): bool => $request['Command'] === $command);

    return $found->isEmpty() ? null : $found->last()[0];
}

function ncRef(array $attributes = []): DomainRef
{
    $base = ['id' => 9001, 'clientId' => 42, 'name' => 'asha-sim.com', 'sld' => 'asha-sim', 'tld' => '.com', 'status' => 'active', 'expiryDate' => CarbonImmutable::parse('2027-03-10'), 'registrarKey' => 'namecheap', 'remote' => ['domain_id' => 555], 'nameservers' => [], 'locked' => true];

    return new DomainRef(...[...$base, ...$attributes]);
}

function ncRequest(?DomainRef $ref = null, array $extra = []): RegistrationRequest
{
    $contact = [...array_fill_keys(ContactSet::FIELDS, null), 'first_name' => 'Asha', 'last_name' => 'Shrestha', 'email' => 'asha@example.test', 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'postcode' => '44600', 'country' => 'NP'];

    return new RegistrationRequest($ref ?? ncRef(['status' => 'pending', 'remote' => []]), 1, ['ns1.host.example.test', 'ns2.host.example.test'], new ContactSet(array_fill_keys(ContactSet::ROLES, $contact)), ...$extra);
}

const NC_EMPTY_LIST = '<DomainGetListResult /><Paging><TotalItems>0</TotalItems><CurrentPage>1</CurrentPage><PageSize>100</PageSize></Paging>';

function ncInfo(string $expires = '03/10/2027', string $status = 'Ok', string $extra = ''): string
{
    return '<DomainGetInfoResult Status="'.$status.'" ID="555" DomainName="asha-sim.com" OwnerName="u" IsOwner="true"><DomainDetails><CreatedDate>03/10/2026</CreatedDate><ExpiredDate>'.$expires.'</ExpiredDate></DomainDetails>'
        .'<Whoisguard Enabled="True"><ID>7001</ID></Whoisguard>'.$extra.'</DomainGetInfoResult>';
}

test('a registration is sent as one POST to the sandbox with the sandbox credentials, all four contact roles and only declared details', function () {
    ncFake([
        'namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST),
        'namecheap.domains.create' => ncXml('namecheap.domains.create', '<DomainCreateResult Domain="asha-nexus.us" Registered="true" DomainID="8100001" OrderID="5100001" NonRealTimeDomain="false" />'),
    ]);
    $ref = ncRef(['name' => 'asha-nexus.us', 'sld' => 'asha-nexus', 'tld' => '.us', 'status' => 'pending', 'remote' => []]);

    $result = ncDriver()->register(ncConfig(), ncRequest($ref, ['idProtection' => true, 'tldAttributes' => ['RegistrantNexus' => 'C11', 'RegistrantPurpose' => 'P3', 'Injected' => 'x']]));
    $sent = ncSent('namecheap.domains.create');

    expect($result->status)->toBe('completed')
        ->and($result->expiryDate->toDateString())->toBe(CarbonImmutable::now('UTC')->addYear()->toDateString())
        ->and($result->remote)->toBe(['domain_id' => 8_100_001, 'order_id' => 5_100_001])
        ->and($sent->method())->toBe('POST')
        ->and($sent->url())->toBe('https://api.sandbox.namecheap.com/xml.response')
        ->and($sent['ApiUser'])->toBe('sandbox-user')
        ->and($sent['ApiKey'])->toBe('SANDBOX-KEY-2')
        ->and($sent['UserName'])->toBe('sandbox-user')
        ->and($sent['ClientIp'])->toBe('203.0.113.10')
        ->and($sent['DomainName'])->toBe('asha-nexus.us')
        ->and($sent['Years'])->toBe(1)
        ->and($sent['Nameservers'])->toBe('ns1.host.example.test,ns2.host.example.test')
        ->and($sent['AddFreeWhoisguard'])->toBe('yes')
        ->and($sent['WGEnabled'])->toBe('yes')
        ->and($sent['RegistrantNexus'])->toBe('C11')
        ->and($sent['RegistrantPurpose'])->toBe('P3')
        ->and($sent['AuxBillingFirstName'])->toBe('Asha')
        ->and($sent['AuxBillingPhone'])->toBe('+977.15550100')
        ->and($sent['TechStateProvince'])->toBe('Not Applicable')
        ->and($sent['AdminPostalCode'])->toBe('44600')
        ->and($sent->data())->not->toHaveKey('Injected')
        ->and($sent->data())->not->toHaveKey('RegistrantOrganizationName');
});

test('live mode sends the live credentials, and live and sandbox keys never cross', function () {
    ncFake(['namecheap.domains.getTldList' => ncXml('namecheap.domains.getTldList', '<Tlds />')]);

    expect(ncDriver()->testConnection(ncConfig(test: false))->ok)->toBeTrue();
    $live = ncSent('namecheap.domains.getTldList');

    expect($live->url())->toBe('https://api.namecheap.com/xml.response')
        ->and([$live['ApiUser'], $live['ApiKey']])->toBe(['live-user', 'LIVE-KEY-1']);

    $onlyLive = new RegistrarConfig(NamecheapDriver::KEY, ['api_user' => 'live-user', 'api_key' => 'LIVE-KEY-1', 'client_ip' => '203.0.113.10'], true, ['api_key']);
    ncHttp();

    expect(ncDriver()->testConnection($onlyLive)->message)->toContain('sandbox API user')
        ->and(fn () => ncDriver()->checkAvailability(ncConfig(settings: ['client_ip' => 'not-an-ip']), 'asha', ['.com']))->toThrow(RegistrarException::class, 'client IP');
    Http::assertNothingSent();
});

test('availability maps each row, asks 50 names at a time, and prices premium names in cents', function () {
    ncFake(['namecheap.domains.check' => fn (Request $request) => ncXml('namecheap.domains.check', collect(explode(',', $request['DomainList']))->map(fn (string $name): string => match (true) {
        str_starts_with($name, 'free') => '<DomainCheckResult Domain="'.$name.'" Available="true" ErrorNo="0" IsPremiumName="false" />',
        str_starts_with($name, 'gold') => '<DomainCheckResult Domain="'.$name.'" Available="true" ErrorNo="0" IsPremiumName="true" PremiumRegistrationPrice="120.5000" PremiumRenewalPrice="99.9900" />',
        str_starts_with($name, 'down') => '<DomainCheckResult Domain="'.$name.'" Available="false" ErrorNo="3031510" Description="registry" />',
        default => '<DomainCheckResult Domain="'.$name.'" Available="false" ErrorNo="0" />',
    })->implode(''))]);

    $free = ncDriver()->checkAvailability(ncConfig(), 'free', ['.com']);
    $gold = ncDriver()->checkAvailability(ncConfig(), 'gold', ['.com'])[0];
    $mixed = ncDriver()->checkAvailability(ncConfig(), 'down', ['.com']);
    $taken = ncDriver()->checkAvailability(ncConfig(), 'taken', ['.com']);

    expect($free[0]->status)->toBe('available')
        ->and($gold->isPremium)->toBeTrue()
        ->and([$gold->premiumRegisterCost, $gold->premiumRenewCost])->toBe([12_050, 9_999])
        ->and($mixed[0]->status)->toBe('unknown')
        ->and($taken[0]->status)->toBe('registered');

    $tlds = array_map(fn (int $i): string => ".t{$i}", range(1, 120));
    ncDriver()->checkAvailability(ncConfig(), 'many', $tlds);

    expect(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.check' && str_contains($request['DomainList'], 'many.t'))->count())->toBe(3);
});

test('errors are told apart: refused, allow-list, funds, and the provider errors that leave a change unknown', function () {
    $register = function (array $errors): void {
        ncFake(['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.create' => ncXml('namecheap.domains.create', errors: $errors)]);
        ncDriver()->register(ncConfig(), ncRequest());
    };

    expect(fn () => $register([2011170]))->toThrow(RegistrarException::class, 'refused the request (2011170)')
        ->and(fn () => $register([3019166]))->toThrow(RegistrarException::class, '3019166')
        ->and(fn () => $register([1011150]))->toThrow(RegistrarException::class, 'allow-list')
        ->and(fn () => $register([1011102]))->toThrow(RegistrarException::class, 'credentials')
        ->and(fn () => $register([2033409]))->toThrow(RegistrarException::class, 'no funds');

    foreach ([3011511, 3031900, 3050900, 5050900, 3028166, 3031510, 4023166, 4024167, 4022337, 4026312, 3099999] as $number) {
        $thrown = null;

        try {
            $register([$number]);
        } catch (RegistrarException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(RegistrarTimeout::class, "error {$number}");
    }

    try {
        $register([1011150]);
    } catch (RegistrarException $refused) {
        expect($refused)->not->toBeInstanceOf(RegistrarTimeout::class)->and($refused->retryable)->toBeFalse();
    }

    // A read with the same provider error is not a doubt about a change: it is refused, not unknown.
    ncFake(['namecheap.domains.check' => ncXml('namecheap.domains.check', errors: [3011511])]);
    expect(fn () => ncDriver()->checkAvailability(ncConfig(), 'a', ['.com']))->toThrow(RegistrarException::class)
        ->and(fn () => ncDriver()->checkAvailability(ncConfig(), 'a', ['.com']))->not->toThrow(RegistrarTimeout::class);
});

test('transport failures: nothing-sent is retryable, a timeout after sending is unknown for changes and retryable for reads', function () {
    $create = ['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST)];

    ncFake([...$create, 'namecheap.domains.create' => new ConnectionException('cURL error 7: Failed to connect')]);
    try {
        ncDriver()->register(ncConfig(), ncRequest());
    } catch (RegistrarException $refused) {
        expect($refused)->not->toBeInstanceOf(RegistrarTimeout::class)->and($refused->retryable)->toBeTrue();
    }

    ncFake([...$create, 'namecheap.domains.create' => new ConnectionException('cURL error 28: Operation timed out')]);
    expect(fn () => ncDriver()->register(ncConfig(), ncRequest()))->toThrow(RegistrarTimeout::class);

    foreach (['', 'not xml at all', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><ApiResponse Status="OK"/>', '<html>502</html>'] as $body) {
        ncFake([...$create, 'namecheap.domains.create' => $body]);
        expect(fn () => ncDriver()->register(ncConfig(), ncRequest()))->toThrow(RegistrarTimeout::class);
    }

    ncFake(['namecheap.domains.check' => new ConnectionException('cURL error 28: Operation timed out')]);
    try {
        ncDriver()->checkAvailability(ncConfig(), 'a', ['.com']);
    } catch (RegistrarException $unreachable) {
        expect($unreachable->retryable)->toBeTrue();
    }

    ncHttp(['*' => Http::response('slow down', 429)]);
    try {
        ncDriver()->checkAvailability(ncConfig(), 'a', ['.com']);
    } catch (RegistrarException $limited) {
        expect($limited->retryable)->toBeTrue()->and($limited->getMessage())->toContain('limiting');
    }
});

test('the local request budget keeps a write reserve: reads stop first, nothing is sent once a limit is reached', function () {
    ncHttp(fn () => Http::response(ncXml('namecheap.domains.check', '<DomainCheckResult Domain="a.com" Available="true" ErrorNo="0" />')));
    $config = ncConfig();

    foreach (range(1, 30) as $ignored) {
        ncDriver()->checkAvailability($config, 'a', ['.com']);
    }

    expect(Http::recorded()->count())->toBe(30)
        ->and(fn () => ncDriver()->checkAvailability($config, 'a', ['.com']))->toThrow(function (RegistrarException $exception): void {
            expect($exception->retryable)->toBeTrue()->and($exception->getMessage())->toContain('kept for registrations');
        })
        ->and(Http::recorded()->count())->toBe(30);

    // The reserve is for changes: 10 more pass, then they stop too.
    ncHttp(fn () => Http::response(ncXml('namecheap.domains.setRegistrarLock', '<DomainSetRegistrarLockResult Domain="asha-sim.com" IsSuccess="true" />')));

    foreach (range(1, 10) as $ignored) {
        ncDriver()->setLock($config, ncRef(), true);
    }

    expect(fn () => ncDriver()->setLock($config, ncRef(), true))->toThrow(RegistrarException::class, 'is used up')
        ->and(Http::recorded()->count())->toBe(10);
});

test('a renewal is sent only when Namecheap\'s expiry is our expiry; when it is a term ahead nothing is bought twice', function () {
    $renewed = '<DomainRenewResult DomainName="asha-sim.com" Renew="true" OrderID="1"><DomainDetails><ExpiredDate>03/10/2028</ExpiredDate><NumYears>1</NumYears></DomainDetails></DomainRenewResult>';

    ncFake(['namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo('03/10/2027')), 'namecheap.domains.renew' => ncXml('namecheap.domains.renew', $renewed)]);
    $done = ncDriver()->renew(ncConfig(), ncRef(), 1);

    expect($done->status)->toBe('completed')
        ->and($done->expiryDate->toDateString())->toBe('2028-03-10')
        ->and(ncSent('namecheap.domains.renew')['Years'])->toBe(1);

    // Namecheap is a whole term ahead: the renewal already ran and its answer was lost.
    ncHttp(fn () => Http::response(ncXml('namecheap.domains.getInfo', ncInfo('03/10/2028'))));
    $already = ncDriver()->renew(ncConfig(), ncRef(), 1);

    expect($already->status)->toBe('completed')
        ->and($already->expiryDate->toDateString())->toBe('2028-03-10')
        ->and($already->message)->toContain('nothing was ordered again');

    foreach (['06/10/2027' => 'less than the 1-year term', '03/10/2026' => 'before ours'] as $theirs => $reason) {
        ncHttp(fn () => Http::response(ncXml('namecheap.domains.getInfo', ncInfo($theirs))));
        expect(fn () => ncDriver()->renew(ncConfig(), ncRef(), 1))->toThrow(RegistrarException::class, $reason);
    }

    ncHttp(fn () => Http::response(ncXml('namecheap.domains.getInfo', ncInfo('03/10/2027', 'Expired'))));
    expect(fn () => ncDriver()->renew(ncConfig(), ncRef(), 1))->toThrow(RegistrarException::class, 'restore it')
        ->and(fn () => ncDriver()->renew(ncConfig(), ncRef(['expiryDate' => null]), 1))->toThrow(RegistrarException::class, 'restore it');

    ncHttp(fn () => Http::response(ncXml('namecheap.domains.getInfo', errors: [2016166])));
    expect(fn () => ncDriver()->renew(ncConfig(), ncRef(), 1))->toThrow(RegistrarException::class, 'not in the Namecheap account');
});

test('a renewal whose answer is not confirmed is unknown, not failed', function () {
    ncFake(['namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo()), 'namecheap.domains.renew' => ncXml('namecheap.domains.renew', '<DomainRenewResult DomainName="asha-sim.com" Renew="false" />')]);

    expect(fn () => ncDriver()->renew(ncConfig(), ncRef(), 1))->toThrow(RegistrarTimeout::class);
});

test('a registration is completed, queued (non-real-time) or unknown, and a name in the account is adopted only with the held id', function () {
    $create = fn (string $attributes) => ['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.create' => ncXml('namecheap.domains.create', '<DomainCreateResult Domain="asha-sim.com" DomainID="1" OrderID="2" '.$attributes.' />')];

    ncFake($create('Registered="false" NonRealTimeDomain="true"'));
    $queued = ncDriver()->register(ncConfig(), ncRequest());
    ncFake($create('Registered="false" NonRealTimeDomain="false"'));

    expect($queued->status)->toBe('pending')
        ->and($queued->remote)->toBe(['domain_id' => 1, 'order_id' => 2])
        ->and(fn () => ncDriver()->register(ncConfig(), ncRequest()))->toThrow(RegistrarTimeout::class);

    $listed = '<DomainGetListResult><Domain ID="555" Name="asha-sim.com" Created="03/10/2026" Expires="03/10/2027" IsExpired="false" IsLocked="true" /></DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging>';
    ncHttp(fn () => Http::response(ncXml('namecheap.domains.getList', $listed)));

    expect(fn () => ncDriver()->register(ncConfig(), ncRequest()))->toThrow(RegistrarException::class, 'link it by hand');
    Http::assertSentCount(1);

    $held = ncDriver()->register(ncConfig(), ncRequest(ncRef(['status' => 'pending_registration', 'remote' => ['domain_id' => 555]])));

    expect($held->status)->toBe('completed')
        ->and($held->expiryDate->toDateString())->toBe('2027-03-10')
        ->and(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.create')->count())->toBe(0);
});

test('a name past the first list pages is confirmed with getInfo, and a name not listed but owned is found', function () {
    $page = fn (int $total) => ncXml('namecheap.domains.getList', '<DomainGetListResult><Domain ID="1" Name="asha-sim.com.other" Created="01/01/2026" Expires="01/01/2027" IsExpired="false" /></DomainGetListResult><Paging><TotalItems>'.$total.'</TotalItems></Paging>');
    ncFake(['namecheap.domains.getList' => $page(450), 'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo())]);

    $sync = ncDriver()->sync(ncConfig(), ncRef());

    expect($sync->active)->toBeTrue()
        ->and($sync->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($sync->remote)->toBe(['domain_id' => 555])
        ->and(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.getList')->count())->toBe(3);

    ncFake(['namecheap.domains.getList' => $page(450), 'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', errors: [2016166]), 'namecheap.domains.check' => ncXml('namecheap.domains.check', '<DomainCheckResult Domain="asha-sim.com" Available="false" ErrorNo="0" />')]);

    expect(ncDriver()->sync(ncConfig(), ncRef())->transferredAway)->toBeTrue();
});

test('sync reads the list once; an expired name is not active; a name that is gone is judged by availability', function () {
    $row = fn (string $expired) => ncXml('namecheap.domains.getList', '<DomainGetListResult><Domain ID="555" Name="asha-sim.com" Created="03/10/2020" Expires="03/10/2027" IsExpired="'.$expired.'" IsLocked="true" /></DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging>');

    ncFake(['namecheap.domains.getList' => $row('false')]);
    expect(ncDriver()->sync(ncConfig(), ncRef())->active)->toBeTrue();
    Http::assertSentCount(1);

    ncFake(['namecheap.domains.getList' => $row('true')]);
    expect(ncDriver()->sync(ncConfig(), ncRef())->active)->toBeFalse();

    foreach (['true' => [true, false], 'false' => [false, true]] as $available => [$cancelled, $away]) {
        ncFake(['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.check' => ncXml('namecheap.domains.check', '<DomainCheckResult Domain="asha-sim.com" Available="'.$available.'" ErrorNo="0" />')]);
        $gone = ncDriver()->sync(ncConfig(), ncRef());

        expect([$gone->cancelled, $gone->transferredAway])->toBe([$cancelled, $away]);
    }

    ncFake(['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST)]);
    expect(ncDriver()->sync(ncConfig(), ncRef(['status' => 'pending_registration']))->active)->toBeFalse();
    Http::assertSentCount(1);
});

test('transfer sync maps every documented status: 5 completes, the cancelled set fails with the reason, the rest wait', function (int $status, string $expected) {
    ncFake([
        'namecheap.domains.transfer.getStatus' => ncXml('namecheap.domains.transfer.getStatus', '<DomainTransferGetStatusResult TransferID="9" Status="Some reason" StatusID="'.$status.'" />'),
        'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo('03/10/2027')),
    ]);

    $sync = ncDriver()->transferSync(ncConfig(), ncRef(['status' => 'pending_transfer', 'remote' => ['transfer_id' => 9]]));

    expect(match (true) {
        $sync->completed => 'completed',
        $sync->failed => 'failed',
        default => 'pending',
    })->toBe($expected)
        ->and($sync->completed ? $sync->expiryDate->toDateString() : $sync->reason)->toBe($sync->completed ? '2027-03-10' : ($sync->failed ? 'Namecheap ended the transfer: Some reason.' : null));
})->with([
    'completed' => [5, 'completed'],
    'rejected' => [2, 'failed'], 'timed out' => [4, 'failed'], 'refused' => [8, 'failed'], 'range' => [20, 'failed'], 'range end' => [27, 'failed'], 'negative cancel' => [-4, 'failed'], 'late range' => [51, 'failed'],
    'waiting' => [-1, 'pending'], 'waiting 2' => [-2, 'pending'], 'waiting 5' => [-5, 'pending'], 'waiting for the code' => [-22, 'pending'], 'unknown id' => [999, 'pending'],
]);

test('a transfer sends the code (base64 when it has special characters), refuses what the API cannot take, and never doubles an in-progress transfer', function () {
    $list = fn (string $rows) => ncXml('namecheap.domains.transfer.getList', '<TransferGetListResult>'.$rows.'</TransferGetListResult>');
    $created = ncXml('namecheap.domains.transfer.create', '<DomainTransferCreateResult Transfer="true" TransferID="400001" StatusID="-1" OrderID="5100002" DomainName="asha-sim.com" />');
    $answers = ['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.transfer.getList' => $list(''), 'namecheap.domains.transfer.create' => $created];
    $transfer = fn (string $code, ?DomainRef $ref = null) => ncDriver()->transfer(ncConfig(), ncRequest($ref ?? ncRef(['status' => 'pending_transfer', 'remote' => []]), ['eppCode' => $code]));

    ncFake($answers);
    $plain = $transfer('Abc123xyz');
    $special = $transfer('p@ss/word=1');

    expect($plain->status)->toBe('pending')
        ->and($plain->remote)->toBe(['transfer_id' => 400_001, 'order_id' => 5_100_002])
        ->and(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.transfer.create')->map(fn (array $pair): string => $pair[0]['EPPCode'])->values()->all())->toBe(['Abc123xyz', 'base64:'.base64_encode('p@ss/word=1')])
        ->and($special->status)->toBe('pending')
        ->and(fn () => $transfer(''))->toThrow(RegistrarException::class, 'transfer (EPP) code')
        ->and(fn () => ncDriver()->transfer(ncConfig(), ncRequest(ncRef(['name' => 'asha.xyz', 'sld' => 'asha', 'tld' => '.xyz', 'remote' => []]), ['eppCode' => 'x'])))->toThrow(RegistrarException::class, 'through its API');

    $running = $list('<Transfer ID="400001" DomainName="asha-sim.com" TransferDate="10/23/2026" StatusID="-1" Status="Waiting" OrderID="5100002" />');
    ncFake([...$answers, 'namecheap.domains.transfer.getList' => $running]);

    expect(fn () => $transfer('Abc123xyz'))->toThrow(RegistrarException::class, 'link it by hand');

    $again = $transfer('Abc123xyz', ncRef(['status' => 'pending_transfer', 'remote' => ['transfer_id' => 400_001]]));

    expect($again->status)->toBe('pending')
        ->and($again->message)->toContain('not sent again')
        ->and(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.transfer.create')->count())->toBe(0);

    ncFake([...$answers, 'namecheap.domains.getList' => ncXml('namecheap.domains.getList', '<DomainGetListResult><Domain ID="555" Name="asha-sim.com" Created="03/10/2026" Expires="03/10/2027" IsExpired="false" /></DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging>')]);
    expect(fn () => $transfer('Abc123xyz'))->toThrow(RegistrarException::class, 'already in the Namecheap account');
});

test('.np and internationalised names never reach Namecheap, even when the driver is called directly', function () {
    ncHttp();

    expect(fn () => ncDriver()->register(ncConfig(), ncRequest(ncRef(['name' => 'asha.com.np', 'sld' => 'asha', 'tld' => '.com.np']))))->toThrow(RegistrarException::class, '.np domains')
        ->and(fn () => ncDriver()->register(ncConfig(), ncRequest(ncRef(['name' => 'xn--abc.com', 'sld' => 'xn--abc']))))->toThrow(RegistrarException::class, 'language code');
    Http::assertNothingSent();
});

test('findSubmission settles a lost register or renew from our own account and never sends a change', function () {
    $since = CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC');
    $listed = fn (string $created, string $id = '555') => ncXml('namecheap.domains.getList', '<DomainGetListResult><Domain ID="'.$id.'" Name="asha-sim.com" Created="'.$created.'" Expires="10/23/2027" IsExpired="false" /></DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging>');
    $ref = ncRef(['status' => 'pending_registration', 'remote' => []]);
    $find = fn (string $command = 'register', ?DomainRef $domain = null) => ncDriver()->findSubmission(ncConfig(), $domain ?? $ref, $command, $since);

    ncFake(['namecheap.domains.getList' => $listed('10/23/2026')]);
    $mine = $find();

    expect($mine->status)->toBe(ReconcileStatus::Executed)
        ->and($mine->expiryDate->toDateString())->toBe('2027-10-23')
        ->and($mine->remote)->toBe(['domain_id' => 555]);

    ncFake(['namecheap.domains.getList' => $listed('08/01/2026')]);
    expect($find()->status)->toBe(ReconcileStatus::Conflict)
        ->and($find('register', ncRef(['remote' => ['domain_id' => 555]]))->status)->toBe(ReconcileStatus::Executed);

    $empty = ncXml('namecheap.domains.getList', NC_EMPTY_LIST);
    foreach ([['true', ReconcileStatus::NotExecuted], ['false', ReconcileStatus::Conflict]] as [$available, $status]) {
        ncFake(['namecheap.domains.getList' => $empty, 'namecheap.domains.check' => ncXml('namecheap.domains.check', '<DomainCheckResult Domain="asha-sim.com" Available="'.$available.'" ErrorNo="0" />')]);
        expect($find()->status)->toBe($status);
    }

    ncFake(['namecheap.domains.getList' => $empty, 'namecheap.domains.check' => ncXml('namecheap.domains.check', '<DomainCheckResult Domain="asha-sim.com" Available="false" ErrorNo="3031510" />')]);
    expect($find()->status)->toBe(ReconcileStatus::Unknown);

    // Renew: executed only when Namecheap's date moved past ours.
    foreach (['03/10/2028' => ReconcileStatus::Executed, '03/10/2027' => ReconcileStatus::NotExecuted] as $theirs => $status) {
        ncFake(['namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo($theirs))]);
        expect($find('renew', ncRef())->status)->toBe($status);
    }

    expect(Http::recorded(fn (Request $request): bool => in_array($request['Command'], ['namecheap.domains.create', 'namecheap.domains.renew', 'namecheap.domains.transfer.create'], true))->count())->toBe(0);
});

test('findSubmission for a transfer follows the transfer row: in progress waits, done is executed, cancelled is not executed', function () {
    $since = CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC');
    $row = fn (int $status, string $date = '10/23/2026') => ncXml('namecheap.domains.transfer.getList', '<TransferGetListResult><Transfer ID="400001" DomainName="asha-sim.com" TransferDate="'.$date.'" StatusID="'.$status.'" Status="Because" OrderID="3" /></TransferGetListResult>');
    $find = fn () => ncDriver()->findSubmission(ncConfig(), ncRef(['status' => 'pending_transfer', 'remote' => []]), 'transfer', $since);

    foreach ([[-1, ReconcileStatus::InProgress], [5, ReconcileStatus::Executed], [8, ReconcileStatus::NotExecuted]] as [$status, $expected]) {
        ncFake(['namecheap.domains.transfer.getList' => $row($status)]);
        $lookup = $find();

        expect($lookup->status)->toBe($expected)->and($lookup->remote)->toBe(['transfer_id' => 400_001, 'order_id' => 3]);
    }

    // An older transfer of the same name (before the submission's window) is not this submission's.
    ncFake(['namecheap.domains.transfer.getList' => $row(8, '09/01/2026'), 'namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.check' => ncXml('namecheap.domains.check', '<DomainCheckResult Domain="asha-sim.com" Available="false" ErrorNo="0" />')]);

    expect($find()->status)->toBe(ReconcileStatus::NotExecuted);
});

test('contacts are read per role and written as all four with the billing role as AuxBilling; a registrant change flags the 60-day lock on a generic extension only', function () {
    $contact = fn (string $role, string $email) => '<'.$role.' ReadOnly="false"><OrganizationName>Acme</OrganizationName><FirstName>Asha</FirstName><LastName>Shrestha</LastName><Address1>1 Durbar Marg</Address1><Address2 /><City>Kathmandu</City><StateProvince>Bagmati</StateProvince><PostalCode>44600</PostalCode><Country>np</Country><Phone>+977.15550100</Phone><EmailAddress>'.$email.'</EmailAddress></'.$role.'>';
    $contacts = ncXml('namecheap.domains.getContacts', '<DomainContactsResult Domain="asha-sim.com">'.$contact('Registrant', 'old@example.test').$contact('Tech', 'tech@example.test').$contact('Admin', 'a@example.test').$contact('AuxBilling', 'bill@example.test').'</DomainContactsResult>');
    ncFake(['namecheap.domains.getContacts' => $contacts, 'namecheap.domains.setContacts' => ncXml('namecheap.domains.setContacts', '<DomainSetContactResult Domain="asha-sim.com" IsSuccess="true" />')]);

    $read = ncDriver()->getContacts(ncConfig(), ncRef());

    expect(array_keys($read->toArray()))->toEqualCanonicalizing(ContactSet::ROLES)
        ->and($read->role('billing')['email'])->toBe('bill@example.test')
        ->and($read->role('registrant')['country'])->toBe('NP')
        ->and($read->role('registrant')['company'])->toBe('Acme')
        ->and($read->role('registrant')['address_2'])->toBeNull();

    $changed = ncDriver()->setContacts(ncConfig(), ncRef(), ncRequest()->contacts);
    $sent = ncSent('namecheap.domains.setContacts');

    expect($changed->status)->toBe('completed')
        ->and($changed->meta['transfer_lock_until'])->toBe(CarbonImmutable::today()->addDays(60)->toDateString())
        ->and($sent['AuxBillingEmailAddress'])->toBe('asha@example.test')
        ->and($sent['RegistrantEmailAddress'])->toBe('asha@example.test')
        ->and($sent['DomainName'])->toBe('asha-sim.com');

    $country = ncDriver()->setContacts(ncConfig(), ncRef(['name' => 'asha-sim.in', 'sld' => 'asha-sim', 'tld' => '.in']), ncRequest()->contacts);

    expect($country->meta)->toBe([]);
});

test('contacts Namecheap cannot take are refused before anything is sent, and phone numbers become +NNN.NNNN', function () {
    ncHttp();
    $without = fn (string $field) => new ContactSet(array_map(fn (array $contact): array => [...$contact, $field => null], ncRequest()->contacts->toArray()));

    expect(fn () => ncDriver()->setContacts(ncConfig(), ncRef(), $without('city')))->toThrow(RegistrarException::class, 'no city')
        ->and(fn () => ncDriver()->setContacts(ncConfig(), ncRef(), $without('country')))->toThrow(RegistrarException::class, 'no country')
        ->and(fn () => ncDriver()->setContacts(ncConfig(), ncRef(), new ContactSet(['admin' => ncRequest()->contacts->role('admin')])))->toThrow(RegistrarException::class, 'no registrant');
    Http::assertNothingSent();

    expect(NamecheapContacts::phone('+977.15550100', 'NP'))->toBe('+977.15550100')
        ->and(NamecheapContacts::phone('+977 1-5550100', 'NP'))->toBe('+977.15550100')
        ->and(NamecheapContacts::phone('01-5550100', 'NP'))->toBe('+977.15550100')
        ->and(NamecheapContacts::phone('(415) 555-0100', 'US'))->toBe('+1.4155550100')
        ->and(fn () => NamecheapContacts::phone('555 0100', 'ZZ'))->toThrow(RegistrarException::class, 'country code')
        ->and(fn () => NamecheapContacts::phone('+977.12', 'NP'))->toThrow(RegistrarException::class, 'valid length');
});

test('lock, name servers, privacy and restore are sent as documented; privacy needs the service id and the registrant email', function () {
    $contacts = ncXml('namecheap.domains.getContacts', '<DomainContactsResult Domain="asha-sim.com"><Registrant><FirstName>A</FirstName><LastName>S</LastName><EmailAddress>owner@example.test</EmailAddress></Registrant></DomainContactsResult>');
    ncFake([
        'namecheap.domains.setRegistrarLock' => ncXml('namecheap.domains.setRegistrarLock', '<DomainSetRegistrarLockResult IsSuccess="true" />'),
        'namecheap.domains.dns.setCustom' => ncXml('namecheap.domains.dns.setCustom', '<DomainDNSSetCustomResult Domain="asha-sim.co.uk" Update="true" />'),
        'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo()),
        'namecheap.domains.getContacts' => $contacts,
        'namecheap.whoisguard.enable' => ncXml('namecheap.whoisguard.enable', '<WhoisguardEnableResult IsSuccess="true" />'),
        'namecheap.whoisguard.disable' => ncXml('namecheap.whoisguard.disable', '<WhoisguardDisableResult IsSuccess="true" />'),
        'namecheap.domains.reactivate' => ncXml('namecheap.domains.reactivate', '<DomainReactivateResult Domain="asha-sim.com" IsSuccess="true" />'),
    ]);

    ncDriver()->setLock(ncConfig(), ncRef(), false);
    ncDriver()->setNameservers(ncConfig(), ncRef(['name' => 'asha-sim.co.uk', 'sld' => 'asha-sim', 'tld' => '.co.uk']), ['a.dns.example.test', 'b.dns.example.test']);
    ncDriver()->setIdProtection(ncConfig(), ncRef(), true);
    ncDriver()->setIdProtection(ncConfig(), ncRef(), false);
    $restored = ncDriver()->restore(ncConfig(), ncRef(), 2);

    expect(ncSent('namecheap.domains.setRegistrarLock')['LockAction'])->toBe('UNLOCK')
        ->and(ncSent('namecheap.domains.dns.setCustom')->data())->toMatchArray(['SLD' => 'asha-sim', 'TLD' => 'co.uk', 'Nameservers' => 'a.dns.example.test,b.dns.example.test'])
        ->and(ncSent('namecheap.whoisguard.disable')['WhoisguardID'])->toBe('7001')
        ->and(Http::recorded(fn (Request $request): bool => $request['Command'] === 'namecheap.whoisguard.enable')->first()[0]->data())->toMatchArray(['WhoisguardID' => '7001', 'ForwardedToEmail' => 'owner@example.test'])
        ->and(ncSent('namecheap.domains.reactivate')['YearsToAdd'])->toBe(2)
        ->and($restored->status)->toBe('completed')
        ->and($restored->expiryDate->toDateString())->toBe('2027-03-10');

    ncFake(['namecheap.domains.setRegistrarLock' => ncXml('namecheap.domains.setRegistrarLock', '<DomainSetRegistrarLockResult IsSuccess="false" />')]);

    expect(fn () => ncDriver()->setLock(ncConfig(), ncRef(), true))->toThrow(RegistrarException::class, 'not changed');
});

test('info combines getInfo, the name servers and the lock, and a lock that cannot be read leaves it unknown', function () {
    $answers = [
        'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo()),
        'namecheap.domains.dns.getList' => ncXml('namecheap.domains.dns.getList', '<DomainDNSGetListResult Domain="asha-sim.com"><Nameserver>NS1.Host.Example.Test</Nameserver><Nameserver>ns2.host.example.test</Nameserver></DomainDNSGetListResult>'),
        'namecheap.domains.getRegistrarLock' => ncXml('namecheap.domains.getRegistrarLock', '<DomainGetRegistrarLockResult Domain="asha-sim.com" RegistrarLockStatus="true" />'),
    ];
    ncFake($answers);
    $info = ncDriver()->info(ncConfig(), ncRef());

    expect([$info->status, $info->locked, $info->idProtection])->toBe(['active', true, true])
        ->and($info->nameservers)->toBe(['ns1.host.example.test', 'ns2.host.example.test'])
        ->and($info->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($info->remote)->toBe(['domain_id' => 555]);

    ncFake([...$answers, 'namecheap.domains.getRegistrarLock' => ncXml('namecheap.domains.getRegistrarLock', errors: [2030280])]);
    expect(ncDriver()->info(ncConfig(), ncRef())->locked)->toBeNull();

    ncFake([...$answers, 'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', errors: [2019166])]);
    expect(ncDriver()->info(ncConfig(), ncRef())->exists())->toBeFalse();

    ncFake([...$answers, 'namecheap.domains.getInfo' => ncXml('namecheap.domains.getInfo', ncInfo(status: 'Expired'))]);
    expect(ncDriver()->info(ncConfig(), ncRef())->status)->toBe('expired');
});

test('the connection test names the allow-list and the sandbox', function () {
    ncFake(['namecheap.domains.getTldList' => ncXml('namecheap.domains.getTldList', errors: [1011150])]);

    $refused = ncDriver()->testConnection(ncConfig());

    expect($refused->ok)->toBeFalse()->and($refused->message)->toContain('allow-list');

    ncFake(['namecheap.domains.getTldList' => ncXml('namecheap.domains.getTldList', '<Tlds />')]);

    expect(ncDriver()->testConnection(ncConfig())->message)->toBe('Connected to the Namecheap sandbox.');
});

test('Namecheap declares extension details for .us, .ca and .eu only, as plain required non-secret inputs', function () {
    $driver = ncDriver();
    $keys = fn (string $tld): array => array_map(fn ($field) => $field->key, $driver->tldAttributeFields($tld));

    expect($driver)->toBeInstanceOf(DeclaresTldAttributes::class)
        ->and(app(RegistrarDriverRegistry::class)->supports('namecheap', 'tld_attributes'))->toBeTrue()
        ->and($keys('.us'))->toBe(['RegistrantPurpose', 'RegistrantNexus'])
        ->and($keys('.CA'))->toBe(['CIRALegalType', 'CIRAWhoisDisplay', 'CIRALanguage'])
        ->and($keys('.eu'))->toBe(['EUAgreeWhoisPolicy', 'EUAgreeDeletePolicy'])
        ->and($keys('.com'))->toBe([])
        ->and($keys('.co.uk'))->toBe([])
        ->and($driver->tldAttributeFields('.us')[1]->help)->toContain('connection to the United States');

    foreach (['.us', '.ca', '.eu'] as $tld) {
        foreach ($driver->tldAttributeFields($tld) as $field) {
            expect($field->required)->toBeTrue()->and($field->isSecret())->toBeFalse()->and($field->optionsSource)->toBeNull();
        }
    }
});

test('the host cleans extension answers against the declared fields, and a boolean confirmation is sent as YES', function () {
    Tld::factory()->extension('.eu')->priced()->create(['registrar_key' => 'namecheap']);
    $errors = function (array $input): array {
        try {
            app(TldAttributes::class)->clean('.eu', $input);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    };

    expect($errors([]))->toHaveKeys(['tld_attributes.EUAgreeWhoisPolicy', 'tld_attributes.EUAgreeDeletePolicy'])
        ->and($errors(['EUAgreeWhoisPolicy' => '1', 'EUAgreeDeletePolicy' => '1']))->toBe([]);

    ncFake(['namecheap.domains.getList' => ncXml('namecheap.domains.getList', NC_EMPTY_LIST), 'namecheap.domains.create' => ncXml('namecheap.domains.create', '<DomainCreateResult Domain="asha-sim.eu" Registered="true" DomainID="1" OrderID="2" />')]);
    ncDriver()->register(ncConfig(), ncRequest(ncRef(['name' => 'asha-sim.eu', 'tld' => '.eu', 'status' => 'pending', 'remote' => []]), ['tldAttributes' => ['EUAgreeWhoisPolicy' => 'true', 'EUAgreeDeletePolicy' => 'false']]));
    $sent = ncSent('namecheap.domains.create');

    expect($sent['EUAgreeWhoisPolicy'])->toBe('YES')
        ->and($sent->data())->not->toHaveKey('EUAgreeDeletePolicy');
});

test('the API key never appears in an error or a result', function () {
    ncFake(['namecheap.domains.getTldList' => ncXml('namecheap.domains.getTldList', errors: [2011170])]);
    $message = ncDriver()->testConnection(ncConfig())->message;

    expect($message)->not->toContain('SANDBOX-KEY-2')->not->toContain('LIVE-KEY-1');
});

test('the client reads the answer with no network access to external entities', function () {
    expect(app(NamecheapClient::class))->toBeInstanceOf(NamecheapClient::class);
    ncFake(['namecheap.domains.getTldList' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><ApiResponse Status="OK"><CommandResponse>&x;</CommandResponse></ApiResponse>']);

    expect(ncDriver()->testConnection(ncConfig())->ok)->toBeFalse();
});
