<?php

use App\Data\Integrations\ResolvedAddress;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CertificateInspector;
use App\Modules\Services\Models\Server;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

const PRESENTED_PIN = 'k3Q1nF0VdR6pX2mYb8sT4wZc7hL9eJ5aG0uI3oN1qE8=';

beforeEach(function () {
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    Whm::fake(['version' => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate')]);
    app()->instance(CertificateInspector::class, new class extends CertificateInspector
    {
        public function inspect(ResolvedAddress $address, string $serverName, int $timeoutSeconds = 10): ?array
        {
            return ['subject' => 'CN=whm.example.test', 'issuer' => 'CN=whm.example.test', 'expires_at' => '2027-01-01T00:00:00Z', 'spki_sha256' => PRESENTED_PIN];
        }
    });
});

test('test connection shows the checklist and the untrusted certificate on the server form', function () {
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.test', $this->server))
        ->assertRedirect(route('admin.services.servers.edit', $this->server))
        ->assertSessionHasErrors('server')
        ->assertSessionHas('inertia.flash_data.connectionCheck.ok', false)
        ->assertSessionHas('inertia.flash_data.connectionCheck.steps.0.status', 'fail')
        ->assertSessionHas('inertia.flash_data.connectionCheck.details.certificate_spki_sha256', PRESENTED_PIN)
        ->assertSessionHas('inertia.flash_data.connectionCheck.can_trust', true);
});

test('staff trust exactly the fingerprint they were shown, once, and the change is audited', function () {
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.test', $this->server));

    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.services.servers.edit', $this->server));

    expect($this->server->refresh()->settings['spki_pin'])->toBe(PRESENTED_PIN)
        ->and(ActivityLog::query()->where('subject_id', $this->server->id)->latest('id')->first()?->properties['reason'] ?? null)->toBe('Trusted the certificate shown by Test connection');

    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])->assertSessionHasErrors('fingerprint');
});

test('a fingerprint the check did not show cannot be trusted', function () {
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])->assertSessionHasErrors('fingerprint');

    $this->post(route('admin.services.servers.test', $this->server));
    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => str_repeat('B', 43).'='])->assertSessionHasErrors('fingerprint');
    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => 'not-a-fingerprint'])->assertSessionHasErrors('fingerprint');

    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBeNull();
});

test('trusting a certificate needs a recent password and the sensitive server permission held explicitly', function () {
    actingAsStaff();
    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])->assertRedirect();
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBeNull();

    actingAsStaff(['services.*']);
    confirmPassword();
    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])->assertForbidden();
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBeNull();
});

test('a refused trust shows its reason on the server form after the redirect', function () {
    actingAsStaff();
    confirmPassword();

    $this->from(route('admin.services.servers.edit', $this->server))
        ->followingRedirects()
        ->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])
        ->assertInertia(fn ($page) => $page->component('admin/services/servers/form')->where('errors.fingerprint', fn (string $error) => str_contains($error, 'run Test connection again')));
});

test('a test connection that throws shows its error on the server form', function () {
    actingAsStaff();
    confirmPassword();
    app()->instance(CertificateInspector::class, new class extends CertificateInspector
    {
        public function inspect(ResolvedAddress $address, string $serverName, int $timeoutSeconds = 10): ?array
        {
            throw new RuntimeException('inspector exploded');
        }
    });

    $this->followingRedirects()
        ->post(route('admin.services.servers.test', $this->server))
        ->assertInertia(fn ($page) => $page->component('admin/services/servers/form')->where('errors.server', fn (string $error) => str_contains($error, 'Connection to '.Whm::HOST.' failed')));
});

test('an offered certificate is dropped when the server address is edited', function () {
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.test', $this->server));
    $this->server->update(['hostname' => 'whm2.example.test']);

    $this->post(route('admin.services.servers.trust-certificate', $this->server), ['fingerprint' => PRESENTED_PIN])->assertSessionHasErrors('fingerprint');
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBeNull();
});

test('a trusted certificate is removed with a recent password and the explicit permission, and audited', function () {
    $this->server->update(['settings' => ['spki_pin' => PRESENTED_PIN]]);

    actingAsStaff();
    $this->delete(route('admin.services.servers.forget-certificate', $this->server))->assertRedirect();
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBe(PRESENTED_PIN);

    actingAsStaff(['services.*']);
    confirmPassword();
    $this->delete(route('admin.services.servers.forget-certificate', $this->server))->assertForbidden();
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBe(PRESENTED_PIN);

    actingAsStaff();
    $this->delete(route('admin.services.servers.forget-certificate', $this->server))->assertRedirect(route('admin.services.servers.edit', $this->server));
    expect($this->server->refresh()->settings['spki_pin'] ?? null)->toBeNull()
        ->and(ActivityLog::query()->where('subject_id', $this->server->id)->latest('id')->first()?->properties['reason'] ?? null)->toBe('Removed the trusted certificate');
});

test('the server form shows only the connection fields the driver reads', function () {
    actingAsStaff();

    $this->get(route('admin.services.servers.edit', $this->server))
        ->assertInertia(fn ($page) => $page
            ->where('driverConnectionFields.cpanel.api_token', ['login_method' => 'token'])
            ->where('driverConnectionFields.cpanel.password', ['login_method' => 'password'])
            ->missing('driverConnectionFields.cpanel.secure')
            ->missing('driverConnectionFields.cpanel.access_hash')
            ->where('driverConnectionFields.none', null));
});

test('the server form never sets or changes a pin', function () {
    actingAsStaff();
    confirmPassword();
    $this->server->update(['settings' => ['login_method' => 'token', 'spki_pin' => PRESENTED_PIN]]);
    $currency = Currency::factory()->create()->code;
    $form = ['name' => 'Shared 1', 'hostname' => Whm::HOST, 'driver' => 'cpanel', 'username' => 'root', 'max_accounts' => 0, 'monthly_cost' => 0, 'currency' => $currency];

    $this->put(route('admin.services.servers.update', $this->server), [...$form, 'settings' => ['login_method' => 'token', 'spki_pin' => str_repeat('B', 43).'=']])->assertSessionHasNoErrors();
    expect($this->server->refresh()->settings['spki_pin'])->toBe(PRESENTED_PIN);

    $this->post(route('admin.services.servers.store'), [...$form, 'name' => 'New', 'api_token' => Whm::TOKEN, 'settings' => ['spki_pin' => str_repeat('B', 43).'=']])->assertSessionHasNoErrors();
    expect(Server::query()->where('name', 'New')->sole()->settings['spki_pin'] ?? null)->toBeNull();
});

test('switching the sign-in method removes the credential no longer used, and audits it', function () {
    actingAsStaff();
    confirmPassword();
    $this->server->update(['password' => 'Old-Root-Password', 'settings' => null]);
    $currency = Currency::factory()->create()->code;
    $form = ['name' => 'Shared 1', 'hostname' => Whm::HOST, 'driver' => 'cpanel', 'username' => 'root', 'max_accounts' => 0, 'monthly_cost' => 0, 'currency' => $currency];

    // No sign-in setting stored: it reads as its default (token), so the token stays and the unused password goes.
    $this->put(route('admin.services.servers.update', $this->server), $form)->assertSessionHasNoErrors();
    expect($this->server->refresh()->api_token)->toBe(Whm::TOKEN)->and($this->server->password)->toBeNull();

    $this->put(route('admin.services.servers.update', $this->server), [...$form, 'password' => 'New-Root-Password', 'settings' => ['login_method' => 'password']])->assertSessionHasNoErrors();
    expect($this->server->refresh()->api_token)->toBeNull()
        ->and($this->server->password)->toBe('New-Root-Password')
        ->and(ActivityLog::query()->where('subject_id', $this->server->id)->latest('id')->first()?->properties['changes']['api_token'] ?? null)->toBe('removed');
});

test('switching to password sign-in without a password is refused, and the token stays', function () {
    actingAsStaff();
    confirmPassword();
    $this->server->update(['password' => null]);
    $currency = Currency::factory()->create()->code;
    $form = ['name' => 'Shared 1', 'hostname' => Whm::HOST, 'driver' => 'cpanel', 'username' => 'root', 'max_accounts' => 0, 'monthly_cost' => 0, 'currency' => $currency];

    $this->put(route('admin.services.servers.update', $this->server), [...$form, 'settings' => ['login_method' => 'password']])
        ->assertSessionHasErrors(['password' => 'Enter the password, or keep signing in with the API token.']);
    expect($this->server->refresh()->api_token)->toBe(Whm::TOKEN);

    $this->post(route('admin.services.servers.store'), [...$form, 'name' => 'No credential'])
        ->assertSessionHasErrors(['api_token' => 'Enter the API token, or keep signing in with the password.']);
});
