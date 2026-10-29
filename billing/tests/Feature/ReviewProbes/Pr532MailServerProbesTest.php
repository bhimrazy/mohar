<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

// Review probes for #532. The SMTP probes need an SMTP stub on 127.0.0.1:26525 that echoes every AUTH payload in its
// 535 refusal, and a tarpit (accepts, never greets) on 26526; skipped when the stub is not running.

function probeTransport(): EsmtpTransport
{
    return app('mail.manager')->mailer('smtp')->getSymfonyTransport();
}

function probeStubRunning(): bool
{
    $socket = @fsockopen('127.0.0.1', 26525, $code, $message, 1);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

test('an SMTP server that echoes AUTH PLAIN and AUTH LOGIN payloads leaks neither the password nor its base64', function (string $auth) {
    if (! probeStubRunning()) {
        $this->markTestSkipped('SMTP echo stub not running on 26525.');
    }

    $password = 'Pr532-Smtp-Pass-77';
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => '26525', 'mail.mailers.smtp.encryption' => 'auto', 'mail.mailers.smtp.username' => 'billing@example.com.np', 'mail.mailers.smtp.password' => $password]);
    app(MailServerSettings::class)->apply();
    config(['mail.mailers.smtp.auth_mode' => $auth]);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();

    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);

    $error = (string) session('inertia.flash_data.mailTest.error');
    $logged = json_encode(ActivityLog::query()->where('event', 'settings.mail_test')->sole()->properties);
    expect($error)->toContain('535');

    foreach ([$password, base64_encode($password), base64_encode("\0billing@example.com.np\0{$password}"), rtrim(base64_encode("\0billing@example.com.np\0{$password}"), '=')] as $form) {
        expect($error)->not->toContain($form)->and($logged)->not->toContain($form);
    }
})->with(['plain' => 'plain', 'login' => 'login']);

test('a silent mail server gives up after about 15 seconds', function () {
    if (! probeStubRunning()) {
        $this->markTestSkipped('SMTP tarpit not running on 26526.');
    }

    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => '26526']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();
    $start = microtime(true);
    $this->post(route('admin.platform.settings.mail.test'), ['to' => 'owner@example.com.np'])->assertSessionHas('inertia.flash_data.mailTest.ok', false);

    expect(microtime(true) - $start)->toBeLessThan(20);
});

test('MAIL_URL in .env silently overrides every value saved on the page', function () {
    config(['mail.mailers.smtp.url' => 'smtp://env-user:env-pass@env-relay.local:2525']);
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.port' => '587', 'mail.mailers.smtp.password' => 'Saved-Pass']);
    app(MailServerSettings::class)->apply();

    expect(probeTransport()->getStream()->getHost())->toBe('smtp.example.com.np');
});

test('choosing Automatic drops a MAIL_SCHEME set in .env, contrary to the config comment', function () {
    config(['mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.port' => 2525]);
    app(Settings::class)->set('mail.mailers.smtp.encryption', 'auto');
    app(MailServerSettings::class)->apply();

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps');
});

test('a worker sees a change saved by another process (memo reset per job, cache key forgotten there)', function () {
    app('mail.manager')->mailer('smtp');
    app(MailServerSettings::class)->apply();
    Setting::query()->updateOrCreate(['key' => 'mail.mailers.smtp.host'], ['value' => 'other-process.example.com.np']);
    Cache::store()->forget('core.settings');
    app()->forgetScopedInstances(); // the worker's resetScope before each job

    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()->allows(['payload' => []])));

    expect(probeTransport()->getStream()->getHost())->toBe('other-process.example.com.np');
});

test('a staff member with only core.settings.view reads the SMTP username without step-up', function () {
    app(Settings::class)->setMany(['mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.username' => 'AKIAPROBE532USERNAME']);
    actingAsStaff(['core.settings.view']);

    $this->get(route('admin.platform.settings.edit', ['section' => 'mail']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('section.editable', false)
        ->where('mailTest', null)
        ->where('section.fields.4.value', 'AKIAPROBE532USERNAME'));
});

test('a secret cannot be cleared: switching to an unauthenticated relay keeps the old SMTP password', function () {
    app(Settings::class)->setMany(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com.np', 'mail.mailers.smtp.username' => 'u', 'mail.mailers.smtp.password' => 'Old-Pass']);
    actingAsStaff(['core.settings.mail']);
    confirmPassword();
    $this->put(route('admin.platform.settings.update', ['section' => 'mail']), [
        'mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['host' => '127.0.0.1', 'port' => 25, 'encryption' => 'auto', 'username' => '', 'password' => '']]],
        'services' => ['ses' => ['key' => '', 'secret' => '', 'region' => '']],
    ])->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('mail.mailers.smtp.password'))->toBe('Old-Pass');
});
