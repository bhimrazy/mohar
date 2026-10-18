<?php

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\TemplatedAuthMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * CTO decision 1: password reset and verification mail come from editable, logged templates; Laravel's own
 * notifications remain the fallback (no template installed here, no Notifications module — see tests/Feature/Auth).
 */

it('sends the password reset from the template and the logged link works', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $user = User::factory()->create(['email' => 'ann@example.test']);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertNothingSent();
    $link = null;
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$link): bool {
        preg_match('#/reset-password/([^?"\s]+)#', $mail->plainText, $match);
        $link = $match[1] ?? null;

        return $mail->hasTo('ann@example.test');
    });
    expect($link)->not->toBeNull()
        ->and(EmailMessage::query()->where('template_key', 'auth.password_reset')->sole()->body_text)->not->toContain((string) $link);

    $this->post(route('password.update'), ['token' => $link, 'email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasNoErrors();
});

it('sends email verification from the template', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'));

    Notification::assertNothingSent();
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo($user->email) && str_contains($mail->plainText, '/email/verify/'));
});

it('falls back to Laravel\'s notifications when no template is installed', function () {
    Mail::fake();
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->post(route('password.email'), ['email' => $user->email]);
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertSentTo($user, VerifyEmail::class);
    Mail::assertNothingSent();
});

it('binds the templated AuthMailer, over Core\'s Laravel default', function () {
    expect(app(AuthMailer::class))->toBeInstanceOf(TemplatedAuthMailer::class);
});

it('never puts the reset token or a signed link in a dispatched event', function () {
    Mail::fake();
    seedEmailTemplates();
    $user = User::factory()->unverified()->create();
    $payloads = [];
    Event::listen('*', function (string $name, array $data) use (&$payloads): void {
        foreach ($data as $item) {
            $payloads[] = $name.' '.(is_object($item) ? json_encode(get_object_vars($item)) : json_encode($item));
        }
    });

    $user->sendPasswordResetNotification('visible-token-123');
    $user->sendEmailVerificationNotification();

    expect(implode("\n", $payloads))->not->toContain('visible-token-123')->not->toContain('signature=');
});

it('sends disabled auth templates anyway', function () {
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'auth.password_reset')->update(['is_enabled' => false]);
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Mail::assertSent(TemplatedMail::class, 1);
});
