<?php

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Modules\Domains\Drivers\Registrars\Namecheap\NamecheapDriver;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Review probes for PR #376, adopted as regression tests: the findings they proved are fixed, so each now asserts the
 * corrected behaviour.
 */
function rbConfig(): RegistrarConfig
{
    return new RegistrarConfig(NamecheapDriver::KEY, ['sandbox_api_user' => 'probe-user', 'sandbox_api_key' => 'PROBE-KEY', 'client_ip' => '203.0.113.10'], true, ['api_key', 'sandbox_api_key', 'http_proxy']);
}

function rbRef(): DomainRef
{
    return new DomainRef(id: 1, clientId: 1, name: 'asha-sim.com', sld: 'asha-sim', tld: '.com', status: 'active', expiryDate: CarbonImmutable::parse('2027-03-10'), registrarKey: 'namecheap', remote: ['domain_id' => 555]);
}

function rbEnvelope(string $command, string $body, string $status = 'OK', string $errors = '<Errors />'): string
{
    return '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="'.$status.'" xmlns="http://api.namecheap.com/xml.response">'.$errors.'<RequestedCommand>'.$command.'</RequestedCommand>'.$body.'</ApiResponse>';
}

beforeEach(function () {
    foreach ([60, 3600, 86400] as $w) {
        RateLimiter::clear('namecheap:sandbox:'.sha1('probe-user').':'.$w);
    }
});

test('a getList answer without its list element is unreadable and retryable: sync neither marks the domain transferred away nor asks the registry', function () {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(match ($request['Command']) {
        // OK envelope, CommandResponse present, but no DomainGetListResult and no Paging.
        'namecheap.domains.getList' => rbEnvelope('namecheap.domains.getList', '<CommandResponse Type="namecheap.domains.getList" />'),
        'namecheap.domains.check' => rbEnvelope('namecheap.domains.check', '<CommandResponse><DomainCheckResult Domain="asha-sim.com" Available="false" ErrorNo="0" /></CommandResponse>'),
        default => throw new RuntimeException('unexpected '.$request['Command']),
    }));

    try {
        app(NamecheapDriver::class)->sync(rbConfig(), rbRef());
        $thrown = null;
    } catch (RegistrarException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RegistrarException::class)
        ->and($thrown->retryable)->toBeTrue()
        ->and(Http::recorded(fn (Request $r): bool => $r['Command'] === 'namecheap.domains.check')->count())->toBe(0);
});

test('an ERROR answer to a renew with no Error elements is unknown, not rejected: the renewal may have run', function () {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response(match ($request['Command']) {
        'namecheap.domains.getInfo' => rbEnvelope('namecheap.domains.getInfo', '<CommandResponse><DomainGetInfoResult Status="Ok" ID="555"><DomainDetails><CreatedDate>03/10/2026</CreatedDate><ExpiredDate>03/10/2027</ExpiredDate></DomainDetails><Whoisguard Enabled="False"><ID>1</ID></Whoisguard></DomainGetInfoResult></CommandResponse>'),
        'namecheap.domains.renew' => rbEnvelope('namecheap.domains.renew', '', 'ERROR', '<Errors />'),
    }));

    expect(fn () => app(NamecheapDriver::class)->renew(rbConfig(), rbRef(), 1))->toThrow(RegistrarTimeout::class);
});
