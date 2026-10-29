<?php

use App\Modules\Core\Install\EnvironmentChecks;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Support\DeadlineSmtpTransport;
use App\Modules\Platform\Support\MailServerSettings;
use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\Mail\SmtpStub;

// Fixes from the review of #532 (ADR 0015 relay profile, MAIL_URL, Automatic, secret removal, masking, deadline).

beforeEach(fn () => app()->instance(OutboundAddressGuard::class, SmtpStub::guard()));

function relayPayload(array $smtp = [], array $top = []): array
{
    return [
        'mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['host' => 'smtp.example.com.np', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => '', ...$smtp]]],
        'services' => ['ses' => ['key' => '', 'secret' => '', 'region' => '']],
        ...$top,
    ];
}

function saveMail(array $payload): TestResponse
{
    return test()->put(route('admin.platform.settings.update', ['section' => 'mail']), $payload);
}

function realSmtp(): EsmtpTransport
{
    return app('mail.manager')->mailer('smtp')->getSymfonyTransport();
}

test('the SMTP host accepts loopback and private relays and refuses link-local, metadata, reserved and numeric hosts', function (string $host, bool $allowed) {
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $response = saveMail(relayPayload(['host' => $host]));

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('mail.mailers.smtp.host');
    expect(app(Settings::class)->get('mail.mailers.smtp.host') === $host)->toBe($allowed);
})->with([
    'public name' => ['smtp.example.com.np', true],
    'localhost' => ['localhost', true],
    'loopback address' => ['127.0.0.1', true],
    'loopback v6' => ['[::1]', true],
    'private address' => ['192.168.1.10', true],
    'name on the LAN' => ['lan-relay.example.np', true],
    'cloud metadata address' => ['169.254.169.254', false],
    'name that resolves to metadata' => ['metadata.example.np', false],
    'link-local v6' => ['fe80::1', false],
    'unspecified v6' => ['[::]', false],
    'numeric obfuscation' => ['0x7f.1', false],
]);

test('a host that is not an allowed relay is not checked while another mailer is chosen', function () {
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    saveMail(relayPayload(['host' => '169.254.169.254'], ['mail' => ['default' => 'log', 'mailers' => ['smtp' => ['host' => '169.254.169.254', 'port' => 587, 'encryption' => 'auto', 'username' => '', 'password' => '']]]]))->assertSessionHasNoErrors();
});

test('the test email refuses a stored host the relay profile forbids, before connecting', function () {
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '169.254.169.254', 'mail.mailers.smtp.port' => '25']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);

    expect((string) session('inertia.flash_data.mailTest.error'))->toContain('not allowed')
        ->and(ActivityLog::query()->where('event', 'settings.mail_test')->sole()->description)->toBe('Test email failed');
});

test('saved SMTP settings win over a MAIL_URL in .env, and the page says so', function () {
    config(['mail.mailers.smtp.url' => 'smtp://env-user:env-pass@env-relay.local:2525']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();
    $page = fn () => $this->get(route('admin.platform.settings.edit', ['section' => 'mail']));

    $page()->assertInertia(fn (Assert $p) => $p->where('mailTest.env_url', 'in effect'));

    saveMail(relayPayload(['password' => 'Saved-Pass']))->assertSessionHasNoErrors();

    $transport = realSmtp();
    expect($transport->getStream()->getHost())->toBe('smtp.example.com.np')
        ->and($transport->getPassword())->toBe('Saved-Pass');
    $page()->assertInertia(fn (Assert $p) => $p->where('mailTest.env_url', 'overridden'));
});

test('without a MAIL_URL the page shows no notice', function () {
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $p->where('mailTest.env_url', null));
});

test('Automatic keeps the MAIL_SCHEME from .env, while TLS and STARTTLS override it', function () {
    config(['mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.port' => 2525]);
    app(Settings::class)->set('mail.mailers.smtp.encryption', 'auto');
    app(MailServerSettings::class)->apply();

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(realSmtp()->getStream()->isTLS())->toBeTrue();

    app(Settings::class)->set('mail.mailers.smtp.encryption', 'starttls');
    app(MailServerSettings::class)->apply();
    expect(config('mail.mailers.smtp.scheme'))->toBe('smtp');

    app(Settings::class)->set('mail.mailers.smtp.encryption', 'auto');
    app(MailServerSettings::class)->apply();
    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps');
});

test('a saved password or secret is removed only by the explicit control; blank keeps it', function () {
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.username' => 'u', 'mail.mailers.smtp.password' => 'Old-Pass', 'services.ses.secret' => 'Ses-Secret']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    saveMail(relayPayload(['username' => 'u']))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('mail.mailers.smtp.password'))->toBe('Old-Pass');

    saveMail([...relayPayload(['username' => '']), 'clear' => ['mail.mailers.smtp.password']])->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('mail.mailers.smtp.password'))->toBeNull()
        ->and(app(Settings::class)->get('services.ses.secret'))->toBe('Ses-Secret');
    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $p
        ->where('section.fields.5.key', 'mail.mailers.smtp.password')->where('section.fields.5.is_set', false));
    expect(json_encode(ActivityLog::query()->where('event', 'settings.updated')->latest('id')->first()->properties))->not->toContain('Old-Pass');
});

test('only a secret of the section can be named for removal', function () {
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    saveMail([...relayPayload(), 'clear' => ['mail.mailers.smtp.host']])->assertSessionHasErrors('clear.0');
});

test('staff who may only view settings see the SMTP username masked; staff who manage mail see it', function () {
    app(Settings::class)->setMany(['mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.username' => 'AKIAEXAMPLEUSER']);
    actingAsStaff(['core.settings.view']);
    $viewer = $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('section.editable', false)->where('section.fields.4.value', '••••••')->where('section.fields.4.is_set', true)
        ->where('section.fields.1.value', 'smtp.example.com.np'));
    expect($viewer->getContent())->not->toContain('AKIAEXAMPLEUSER');

    $this->flushSession();
    actingAsStaff(['core.settings.mail']);
    confirmPassword();
    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $p->where('section.fields.4.value', 'AKIAEXAMPLEUSER'));
});

test('the test mailer is an SMTP transport with an overall deadline', function () {
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.port' => '465', 'mail.mailers.smtp.username' => 'u', 'mail.mailers.smtp.password' => 'p']);
    app(MailServerSettings::class)->registerTestMailer();

    $transport = app('mail.manager')->mailer(MailServerSettings::TEST_MAILER)->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(DeadlineSmtpTransport::class)
        ->and($transport->getStream()->getHost())->toBe('smtp.example.com.np')
        ->and($transport->getStream()->isTLS())->toBeTrue()
        ->and($transport->getUsername())->toBe('u')
        ->and($transport->getStream()->getTimeout())->toBe((float) MailServerSettings::TEST_TIMEOUT);
});

test('a server that answers every step slowly is cut off by the deadline, not only by the read timeout', function () {
    $stub = SmtpStub::start('slow');

    try {
        $transport = new DeadlineSmtpTransport('127.0.0.1', $stub->port, false, 1.0);
        $started = microtime(true);

        expect(fn () => $transport->send(new RawMessage('Subject: x'), new Envelope(new Address('a@example.com.np'), [new Address('b@example.com.np')])))
            ->toThrow(TransportException::class, 'did not finish within 1 seconds');
        expect(microtime(true) - $started)->toBeLessThan(5);
    } finally {
        $stub->stop();
    }
});

test('a mail server that echoes the AUTH payloads leaks neither the password nor its base64 form', function () {
    $stub = SmtpStub::start('echo');
    $password = 'Pr532-Smtp-Pass-77';

    try {
        app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => (string) $stub->port, 'mail.mailers.smtp.encryption' => 'auto', 'mail.mailers.smtp.username' => 'billing@example.com.np', 'mail.mailers.smtp.password' => $password]);
        actingAsStaff(['core.settings.mail']);
        confirmPassword();

        $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);
    } finally {
        $stub->stop();
    }

    $error = (string) session('inertia.flash_data.mailTest.error');
    $logged = json_encode(ActivityLog::query()->where('event', 'settings.mail_test')->sole()->properties);
    expect($error)->toContain('535');

    foreach ([$password, base64_encode($password), base64_encode("\0billing@example.com.np\0{$password}"), rtrim(base64_encode("\0billing@example.com.np\0{$password}"), '=')] as $form) {
        expect($error)->not->toContain($form)->and($logged)->not->toContain($form);
    }
});

test('a silent mail server ends the test send with an error instead of hanging', function () {
    $stub = SmtpStub::start('tarpit');
    config(['mail.mailers.smtp.timeout' => 1]);

    try {
        app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => (string) $stub->port]);
        actingAsStaff(['core.settings.mail']);
        confirmPassword();
        $started = microtime(true);
        $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);
    } finally {
        $stub->stop();
    }

    expect(microtime(true) - $started)->toBeLessThan(MailServerSettings::TEST_DEADLINE + MailServerSettings::TEST_TIMEOUT + 5);
});

test('a worker sees a change saved by another process after its per-job reset', function () {
    app('mail.manager')->mailer('smtp');
    app(MailServerSettings::class)->apply();
    Setting::query()->updateOrCreate(['key' => 'mail.mailers.smtp.host'], ['value' => 'other-process.example.com.np']);
    Cache::store()->forget('core.settings');
    app()->forgetScopedInstances();

    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()->allows(['payload' => []])));

    expect(realSmtp()->getStream()->getHost())->toBe('other-process.example.com.np');
});

test('the install check reads the mailer saved in the admin area, not only .env', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['mail.default' => 'log']);
    $warns = fn () => collect(app(EnvironmentChecks::class)->run())->contains(fn ($check) => $check->label === 'Mail');

    expect($warns())->toBeTrue();

    app(Settings::class)->set('mail.default', 'smtp');
    expect($warns())->toBeFalse();
});

test('a password that comes only from MAIL_URL is masked in the test error, the log and the page', function () {
    $stub = SmtpStub::start('echo');
    $password = 'Env-Url-Pass-5531';

    try {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => "smtp://env-user:{$password}@127.0.0.1:{$stub->port}"]);
        actingAsStaff(['core.settings.mail']);
        confirmPassword();

        $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);
    } finally {
        $stub->stop();
    }

    $error = (string) session('inertia.flash_data.mailTest.error');
    $logged = json_encode(ActivityLog::query()->where('event', 'settings.mail_test')->sole()->properties);
    $page = $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->getContent();
    $plain = base64_encode("\0env-user\0{$password}");
    expect($error)->toContain('535');

    foreach ([$password, base64_encode($password), $plain, rtrim($plain, '=')] as $form) {
        expect($error)->not->toContain($form)->and($logged)->not->toContain($form)->and($page)->not->toContain($form);
    }
});

test('"Remove the saved value" is offered only for a secret saved in the database, not one that only .env supplies', function () {
    config(['mail.mailers.smtp.password' => 'env-pass']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();
    $field = fn (Assert $p) => $p->where('section.fields.5.key', 'mail.mailers.smtp.password');

    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $field($p)->where('section.fields.5.saved', false));

    saveMail(relayPayload(['password' => 'Saved-Pass']))->assertSessionHasNoErrors();
    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $field($p)->where('section.fields.5.saved', true));

    saveMail([...relayPayload(), 'clear' => ['mail.mailers.smtp.password']])->assertSessionHasNoErrors();
    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $p) => $field($p)->where('section.fields.5.saved', false));
});

test('the test transport honours verify_peer and auto_tls from MAIL_URL like the real mailer', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://u:p@relay.example.com.np:2525?verify_peer=0&auto_tls=0']);
    $real = realSmtp();
    app(MailServerSettings::class)->registerTestMailer();
    $test = app('mail.manager')->mailer(MailServerSettings::TEST_MAILER)->getSymfonyTransport();

    expect($test->getStream()->getHost())->toBe('relay.example.com.np')
        ->and($test->getStream()->getPort())->toBe(2525)
        ->and($test->isAutoTls())->toBeFalse()->toBe($real->isAutoTls())
        ->and($test->getStream()->isTLS())->toBeFalse()->toBe($real->getStream()->isTLS())
        ->and($test->getStream()->getStreamOptions()['ssl']['verify_peer'])->toBeFalse()
        ->and($test->getStream()->getStreamOptions())->toEqual($real->getStream()->getStreamOptions());
});
