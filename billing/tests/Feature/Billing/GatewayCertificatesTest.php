<?php

/*
 * payments-gateways-expansion §9.2 (K1): a certificate setting is uploaded as a .pfx with its passphrase, only the
 * encrypted PEM key and its metadata are kept, and staff are warned before it expires.
 */

use App\Contracts\Billing\GatewayRegistry;
use App\Events\Billing\GatewayCredentialExpiring;
use App\Modules\Billing\Actions\CheckGatewayCredentials;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Notifications\Models\EmailMessage;
use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use App\Support\Dashboard\Dashboard;
use App\Support\Integrations\CertificateBundle;
use App\Support\Integrations\CertificateRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Billing\FakeSigningGatewayDriver;

require_once __DIR__.'/../Notifications/helpers.php';

/**
 * A PKCS#12 file with a self-signed RSA key, valid for $days from now (cached: key generation is slow).
 */
function testPfx(string $passphrase = 'pfx-pass-123', int $days = 365): string
{
    static $made = [];

    return $made[$passphrase.$days] ??= (function () use ($passphrase, $days): string {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'NP', 'organizationName' => 'Test Merchant', 'commonName' => 'CREDITOR'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], 4242);
        openssl_pkcs12_export($certificate, $pfx, $key, $passphrase);

        return $pfx;
    })();
}

function signingGatewayPayload(array $certificate = [], array $settings = []): array
{
    return [
        'display_name' => 'connectIPS', 'is_active' => false, 'show_on_order_form' => true,
        'settings' => ['merchant_id' => 'M-100', ...$settings],
        'certificates' => $certificate === [] ? [] : ['signing_key' => $certificate],
    ];
}

function uploadSigningCertificate(string $passphrase = 'pfx-pass-123', int $days = 365): void
{
    test()->put(route('admin.billing.gateways.update', 'fake_signing'), signingGatewayPayload([
        'file' => UploadedFile::fake()->createWithContent('CREDITOR.pfx', testPfx('pfx-pass-123', $days)),
        'passphrase' => $passphrase,
    ]))->assertSessionHasNoErrors();
}

beforeEach(function () {
    FakeSigningGatewayDriver::$providerRefs = [];
    FakeSigningGatewayDriver::$initiated = 0;
    app(GatewayRegistry::class)->register(new FakeSigningGatewayDriver);
    actingAsStaff();
    confirmPassword();
});

test('an uploaded .pfx is kept only as an encrypted PEM key with its metadata, never in props, session or audit', function () {
    uploadSigningCertificate();

    $gateway = Gateway::query()->where('key', 'fake_signing')->sole();
    $pem = (string) $gateway->setting('signing_key');
    $stored = (string) DB::table('gateways')->where('key', 'fake_signing')->value('config');

    expect($pem)->toStartWith('-----BEGIN PRIVATE KEY-----')
        ->and(openssl_pkey_get_private($pem))->not->toBeFalse()
        ->and($stored)->not->toContain('PRIVATE KEY')->not->toContain('CREDITOR')
        ->and(app(GatewayManager::class)->config($gateway)->certificate('signing_key'))
        ->subject->toContain('O=Test Merchant, CN=CREDITOR')
        ->notAfter->toDateString()->toBe(now()->addDays(365)->toDateString());

    $page = $this->get(route('admin.billing.gateways.edit', 'fake_signing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gateway.secrets_set.signing_key', true)
            ->where('gateway.settings.signing_key', '')
            ->where('gateway.certificates.signing_key.serial', '1092')
            ->where('fields.1.type', 'certificate'));

    $audit = json_encode(ActivityLog::query()->pluck('properties'));

    expect($page->getContent())->not->toContain('PRIVATE KEY')->not->toContain('pfx-pass-123')
        ->and(json_encode(session()->all()))->not->toContain('pfx-pass-123')
        ->and($audit)->not->toContain('PRIVATE KEY')->toContain('rotated');

    // A save without an upload keeps it, and text sent for the certificate setting is ignored.
    $this->put(route('admin.billing.gateways.update', 'fake_signing'), signingGatewayPayload(settings: ['signing_key' => 'pasted text']))->assertSessionHasNoErrors();

    expect($gateway->fresh()->setting('signing_key'))->toBe($pem);
});

test('a wrong passphrase, a file that is not PKCS#12, an expired certificate or an oversize file is refused at the field', function () {
    $refuse = fn (array $certificate, string $message) => $this->put(route('admin.billing.gateways.update', 'fake_signing'), signingGatewayPayload($certificate))
        ->assertSessionHasErrors(['certificates.signing_key.file' => $message]);

    $wrong = 'This file could not be opened with that passphrase. Check the passphrase, and that the file is the .pfx or .p12 file the provider sent.';
    $refuse(['file' => UploadedFile::fake()->createWithContent('CREDITOR.pfx', testPfx()), 'passphrase' => 'not-the-passphrase'], $wrong);
    $refuse(['file' => UploadedFile::fake()->createWithContent('CREDITOR.pfx', 'not a certificate'), 'passphrase' => ''], $wrong);
    $refuse(['file' => UploadedFile::fake()->create('CREDITOR.pfx', 65), 'passphrase' => ''], 'The Signing certificate field must not be greater than 64 kilobytes.');

    $this->travel(2)->days();
    $refuse(['file' => UploadedFile::fake()->createWithContent('CREDITOR.pfx', testPfx(days: 1)), 'passphrase' => 'pfx-pass-123'], 'This certificate expired on '.now()->subDay()->format('M j, Y').'. Ask the provider for a new one.');

    expect(json_encode(session()->all()))->not->toContain('not-the-passphrase')
        ->and(Gateway::query()->where('key', 'fake_signing')->value('config'))->toBeNull();
});

test('the reader never puts the passphrase or key in its refusal', function () {
    try {
        CertificateBundle::fromPkcs12(testPfx(), 'secret-wrong-pass');
        $this->fail('Expected a refusal.');
    } catch (CertificateRefused $refused) {
        expect($refused->getMessage())->not->toContain('secret-wrong-pass')
            ->and(openssl_error_string())->toBeFalse();
    }
});

test('the daily check warns once per stage, catches up a missed stage, warns on expiry and restarts after a new upload', function () {
    Event::fake([GatewayCredentialExpiring::class]);
    uploadSigningCertificate(days: 40);
    Gateway::query()->where('key', 'fake_signing')->update(['is_active' => true]);
    $check = fn (int $daysFromNow): int => app(CheckGatewayCredentials::class)->handle(CalendarDate::parse(now()->addDays($daysFromNow)->toDateString()));

    expect($check(0))->toBe(0)       // 40 days left
        ->and($check(10))->toBe(1)   // 30 days left: the 30-day warning
        ->and($check(11))->toBe(0)   // 29: already warned for this stage
        ->and($check(35))->toBe(1)   // 5: the 7-day stage, caught up after missed days
        ->and($check(39))->toBe(1)   // 1
        ->and($check(40))->toBe(1)   // expired
        ->and($check(41))->toBe(0);

    Event::assertDispatched(GatewayCredentialExpiring::class, fn (GatewayCredentialExpiring $event): bool => $event->gateway === 'fake_signing'
        && $event->gatewayName === 'connectIPS' && $event->credential === 'signing_key' && $event->credentialLabel === 'Signing certificate' && $event->daysLeft === 5);
    Event::assertDispatched(GatewayCredentialExpiring::class, fn (GatewayCredentialExpiring $event): bool => $event->daysLeft === 0);

    uploadSigningCertificate(days: 20);
    Gateway::query()->where('key', 'fake_signing')->update(['is_active' => true]);

    expect($check(0))->toBe(1);
});

test('an inactive gateway is not checked, and the check is a task in the daily pipeline', function () {
    Event::fake([GatewayCredentialExpiring::class]);
    uploadSigningCertificate(days: 5);

    expect(app(CheckGatewayCredentials::class)->handle(CalendarDate::parse(now()->toDateString())))->toBe(0)
        ->and(app(Automation::class)->ordered())->toHaveKey('billing.gateway_credentials');
    Event::assertNotDispatched(GatewayCredentialExpiring::class);
});

test('the health line is amber within the first warning stage, red once expired, and absent without certificates', function () {
    $health = fn () => collect(app(Dashboard::class)->resolveHealth('admin', auth()->user()))->firstWhere('key', 'billing.gateway_credentials');

    expect($health())->toBeNull();

    uploadSigningCertificate(days: 40);
    Gateway::query()->where('key', 'fake_signing')->update(['is_active' => true]);

    expect($health())->tone->toBe('success');

    $this->travel(15)->days();
    expect($health())->tone->toBe('warning')->value->toBe('25 days left')
        ->detail->toBe('connectIPS certificate expires on '.now()->addDays(25)->format('M j, Y'));

    $this->travel(30)->days();
    expect($health())->tone->toBe('danger')->value->toBe('Expired');
});

test('staff who manage gateways get an email when a certificate is about to expire', function () {
    seedEmailTemplates();
    fullAdministrator('admin@example.test');

    event(new GatewayCredentialExpiring('fake_signing', 'connectIPS', 'signing_key', 'Signing certificate', now()->addDays(7)->toIso8601String(), 7));

    $messages = EmailMessage::query()->where('template_key', 'staff.gateway_credential_expiring')->get();

    expect($messages->pluck('recipient'))->toContain('admin@example.test')
        ->and($messages->first()->subject)->toBe('connectIPS certificate expires in 7 days');
});
