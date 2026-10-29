<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** A full section save, as the page sends it. */
function mailServerPayload(array $smtp = [], string $mailer = 'smtp'): array
{
    return [
        'mail' => ['default' => $mailer, 'mailers' => ['smtp' => [
            'host' => 'smtp.example.com.np', 'port' => 587, 'encryption' => 'starttls', 'username' => 'billing@example.com.np', 'password' => '', ...$smtp,
        ]]],
        'services' => ['ses' => ['key' => '', 'secret' => '', 'region' => 'ap-south-1']],
    ];
}

/** The SMTP transport the default mailer now builds. */
function smtpTransport(): EsmtpTransport
{
    $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    return $transport;
}

test('changing the mail server needs the sensitive permission and a recent password, keeps the password out of the page and the log', function () {
    Notification::fake();
    $otherAdmin = User::factory()->staff(['*'])->create();
    actingAsStaff(['core.settings.view', 'core.settings.*']);
    confirmPassword();
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload())->assertForbidden();

    $this->flushSession();
    actingAsStaff(['core.settings.mail']);
    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertRedirect(route('password.confirm'));
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload(['password' => 'Smtp-Pass-9876']))
        ->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input');

    confirmPassword();
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload(['password' => 'Smtp-Pass-9876']))->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('mail.mailers.smtp.password'))->toBe('Smtp-Pass-9876')
        ->and(Setting::query()->where('key', 'mail.mailers.smtp.password')->value('value'))->toStartWith(Settings::ENCRYPTED_PREFIX)
        ->and(json_encode(ActivityLog::query()->where('event', 'settings.updated')->sole()->properties))->not->toContain('Smtp-Pass-9876');
    Notification::assertSentTo($otherAdmin, SecurityNotice::class);

    $page = $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertInertia(fn (Assert $page) => $page
        ->where('mailTest.saved_here', true)
        ->where('section.fields.5.key', 'mail.mailers.smtp.password')
        ->where('section.fields.5.value', null)
        ->where('section.fields.5.is_set', true));
    expect($page->getContent())->not->toContain('Smtp-Pass-9876');
});

test('a host is required for SMTP and a malformed host is refused', function () {
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload(['host' => '']))->assertSessionHasErrors('mail.mailers.smtp.host');
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload(['host' => 'smtp.example.com/x?y']))->assertSessionHasErrors('mail.mailers.smtp.host');
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), mailServerPayload(['host' => ''], 'log'))->assertSessionHasNoErrors();
});

test('saved values replace the .env ones in the mailer; values never saved keep .env', function () {
    config(['mail.mailers.smtp.host' => 'env-relay.local', 'mail.mailers.smtp.port' => 2525, 'mail.mailers.smtp.username' => 'env-user', 'mail.mailers.smtp.scheme' => null]);
    expect(smtpTransport()->getStream()->getHost())->toBe('env-relay.local');

    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.port' => '465', 'mail.mailers.smtp.encryption' => 'tls', 'mail.mailers.smtp.password' => 'Smtp-Pass-9876']);
    app(MailServerSettings::class)->apply();

    $transport = smtpTransport();
    expect(config('mail.default'))->toBe('smtp')
        ->and($transport->getStream()->getHost())->toBe('smtp.example.com.np')
        ->and($transport->getStream()->getPort())->toBe(465)
        ->and($transport->getStream()->isTLS())->toBeTrue()
        ->and($transport->getUsername())->toBe('env-user')
        ->and($transport->getPassword())->toBe('Smtp-Pass-9876');

    app(Settings::class)->setMany(['mail.mailers.smtp.port' => '587', 'mail.mailers.smtp.encryption' => 'starttls']);
    app(MailServerSettings::class)->apply();
    $transport = smtpTransport();
    expect($transport->getStream()->isTLS())->toBeFalse()
        ->and((fn () => $this->requireTls)->call($transport))->toBeTrue();
});

test('a running queue worker uses changed settings from its next job, without a restart', function () {
    app('mail.manager')->mailer('smtp');
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'changed.example.com.np']);

    expect(smtpTransport()->getStream()->getHost())->not->toBe('changed.example.com.np');

    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()->allows(['payload' => []])));

    expect(smtpTransport()->getStream()->getHost())->toBe('changed.example.com.np');
});

test('a test email is sent at once through the saved mailer and logged', function () {
    app(Settings::class)->set('mail.default', 'log');
    $staff = actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])
        ->assertRedirect(route('admin.platform.settings.edit', ['section' => 'mail']))
        ->assertSessionHas('inertia.flash_data.mailTest.ok', true);

    $log = ActivityLog::query()->where('event', 'settings.mail_test')->sole();
    expect($log->description)->toBe('Test email sent')
        ->and($log->user_id)->toBe($staff->id)
        ->and($log->properties)->toMatchArray(['recipient' => 'owner@example.com.np', 'mailer' => 'log']);
});

test('a failed test email shows the mail server error with its credentials masked', function () {
    // The password doubles as the host, so the connection error carries it.
    $secret = 'smtp-secret-7731.invalid';
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $secret, 'mail.mailers.smtp.port' => 25, 'mail.mailers.smtp.password' => $secret]);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])
        ->assertSessionHas('inertia.flash_data.mailTest.ok', false);

    $error = (string) session('inertia.flash_data.mailTest.error');
    $log = ActivityLog::query()->where('event', 'settings.mail_test')->sole();
    expect($error)->toContain('[redacted]')->not->toContain($secret)
        ->and($log->description)->toBe('Test email failed')
        ->and(json_encode($log->properties))->not->toContain($secret);
});

test('test emails need the permission and a recent password, and are rate limited', function () {
    app(Settings::class)->set('mail.default', 'log');
    actingAsStaff(['core.settings.view']);
    confirmPassword();
    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertForbidden();

    $this->flushSession();
    actingAsStaff(['core.settings.mail']);
    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'not-an-address'])->assertSessionHasErrors('to');

    foreach (range(1, 5) as $attempt) {
        $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHasNoErrors();
    }

    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHasErrors(['to' => 'Too many test emails. Try again in 10 minutes.']);
    expect(ActivityLog::query()->where('event', 'settings.mail_test')->count())->toBe(5);
});
