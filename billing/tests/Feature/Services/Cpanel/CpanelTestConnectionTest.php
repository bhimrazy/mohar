<?php

use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\ResolvedAddress;
use App\Enums\CheckStatus;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CertificateInspector;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

/**
 * @return array<string, array{status: string, detail: string|null}>
 */
function checklist(ConnectionCheck $check): array
{
    $steps = [];

    foreach ($check->steps as $step) {
        $steps[$step->label] = ['status' => $step->status->value, 'detail' => $step->detail];
    }

    return $steps;
}

/**
 * @param  list<string>  $without
 * @return array<string, mixed>
 */
function tokenWithout(array $without, ?int $expiresAt = null): array
{
    $token = Whm::fixture('api_token_get_details');
    $token['data']['acls'] = array_values(array_diff($token['data']['acls'], $without));
    $token['data']['expires_at'] = $expiresAt;

    return $token;
}

test('a healthy root token passes every step and reports what it found', function () {
    Whm::fake();

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect($check->ok)->toBeTrue()
        ->and(array_column(checklist($check), 'status'))->each->toBe('pass')
        ->and(array_keys(checklist($check)))->toBe(['Address and certificate', 'Sign in', 'Permissions', 'Account kind', 'Packages', 'Name servers'])
        ->and($check->details)->toMatchArray([
            'version' => '11.124.0.12',
            'account_kind' => 'root',
            'sso_available' => 'yes',
            'nameservers' => 'ns1.example-host.test, ns2.example-host.test',
        ])
        ->and(checklist($check)['Packages']['detail'])->toBe('4 packages can be assigned.');
});

test('a token missing a required permission fails that line by name', function () {
    Whm::fake(['api_token_get_details' => tokenWithout(['kill-acct'])]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect($check->ok)->toBeFalse()
        ->and(checklist($check)['Permissions'])->toBe(['status' => 'fail', 'detail' => 'The token lacks: kill-acct (terminating accounts).']);
});

test('a token missing only create-user-session warns, and marks single sign-on unavailable', function () {
    Whm::fake(['api_token_get_details' => tokenWithout(['create-user-session'])]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect($check->ok)->toBeTrue()
        ->and(checklist($check)['Permissions']['status'])->toBe('warn')
        ->and(checklist($check)['Permissions']['detail'])->toBe('Missing create-user-session, so single sign-on will not work (the login buttons stay hidden).')
        ->and($check->details['sso_available'])->toBe('no');
});

test('a token that expires within 14 days warns and records the expiry', function () {
    $expires = now()->addDays(5)->getTimestamp();
    Whm::fake(['api_token_get_details' => tokenWithout([], $expires)]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect(checklist($check)['Permissions']['status'])->toBe('warn')
        ->and(checklist($check)['Permissions']['detail'])->toContain('expires on')
        ->and($check->details['token_expires_at'])->toBe(now()->setTimestamp($expires)->toIso8601String());
});

test('a reseller token that cannot read itself falls back to its privileges and shows its account counts', function () {
    Whm::fake([
        'api_token_get_details' => Http::response(Whm::fixture('access_denied')),
        'myprivs' => 'myprivs_reseller',
    ]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server(username: 'reseller1'));

    expect($check->ok)->toBeTrue()
        ->and($check->details['account_kind'])->toBe('reseller')
        ->and(checklist($check)['Account kind']['detail'])->toBe('Reseller connection: 12 active, 2 suspended, limit 50. Only accounts it owns are visible.');
});

test('an old WHM version and an empty package list warn without failing', function () {
    Whm::fake([
        'version' => Whm::fixture('version', ['data' => ['version' => '11.102.0.5']]),
        'listpkgs' => 'listpkgs_empty',
    ]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect($check->ok)->toBeTrue()
        ->and(checklist($check)['Sign in']['status'])->toBe('warn')
        ->and(checklist($check)['Packages'])->toBe(['status' => 'warn', 'detail' => 'There are no packages this user can assign.']);
});

test('bad credentials fail the sign-in line after the address and certificate passed', function () {
    Whm::fake(['version' => Http::response(Whm::raw('http_401.html'), 401)]);

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect(checklist($check)['Address and certificate']['status'])->toBe('pass')
        ->and(checklist($check)['Sign in']['status'])->toBe('fail')
        ->and($check->message)->toContain('refused the credentials');
});

test('an untrusted certificate fails with its subject, issuer, expiry and fingerprint for staff to compare', function () {
    Whm::fake(['version' => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate')]);
    app()->instance(CertificateInspector::class, new class extends CertificateInspector
    {
        public function inspect(ResolvedAddress $address, string $serverName, int $timeoutSeconds = 10): ?array
        {
            expect($address->ip)->toBe(Whm::IP)->and($serverName)->toBe(Whm::HOST);

            return ['subject' => 'CN=whm.example.test', 'issuer' => 'CN=whm.example.test', 'expires_at' => '2027-01-01T00:00:00Z', 'spki_sha256' => str_repeat('A', 43).'='];
        }
    });

    $check = app(CpanelDriver::class)->testConnection(Whm::server());

    expect($check->ok)->toBeFalse()
        ->and(checklist($check)['Address and certificate']['status'])->toBe(CheckStatus::Fail->value)
        ->and($check->details)->toMatchArray([
            'certificate_subject' => 'CN=whm.example.test',
            'certificate_spki_sha256' => str_repeat('A', 43).'=',
            'certificate_expires_at' => '2027-01-01T00:00:00Z',
        ]);
});

test('there is no setting that turns certificate verification off', function () {
    $keys = array_map(fn ($field) => $field->key, app(CpanelDriver::class)->metadata()->serverFields);

    expect($keys)->toBe(['login_method', 'spki_pin', 'sso_enabled', 'keep_dns_on_terminate', 'reserved_usernames']);
});
