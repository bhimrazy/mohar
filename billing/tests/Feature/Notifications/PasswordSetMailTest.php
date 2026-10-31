<?php

use App\Models\User;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Notifications\SetPasswordNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/** The set-a-password mail goes through the template system like reset and verification (ADR 0018 §4). */
it('sends the set-password link from the logged, secret-bearing template, and the link works', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    Notification::assertNothingSent();
    $token = null;
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$token, $user): bool {
        preg_match('#/settings/password/set/([^?"\s]+)#', $mail->plainText, $match);
        $token = $match[1] ?? null;

        return $mail->hasTo($user->email);
    });
    $message = EmailMessage::query()->where('template_key', 'auth.password_set')->sole();
    expect($token)->not->toBeNull()
        ->and($message->body_text)->not->toContain((string) $token)
        ->and(SecretTemplatePolicy::isSecretTemplate('auth.password_set'))->toBeTrue();

    $this->actingAs($user)->get(route('password.set', $token))->assertInertia(fn (Assert $page) => $page->where('valid', true));
    $this->actingAs($user)->post(route('password.set.store', $token), ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'])
        ->assertRedirect(route('profile.edit'));
    expect($user->fresh()->hasPassword())->toBeTrue();
});

it('is editable in the template editor and sends the edited wording', function () {
    Mail::fake();
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'auth.password_set')->sole();

    $this->actingAs(User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create())
        ->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->put(route('admin.notifications.templates.update', $template), [
            'name' => $template->name, 'locale' => 'en', 'subject' => 'Pick your {{ company.name }} password', 'heading' => 'Pick a password',
            'action_label' => 'Pick a password', 'body' => 'Hello {{ user.first_name }}, choose a password with the button below.',
        ])->assertSessionHasNoErrors();

    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    expect(EmailMessage::query()->where('template_key', 'auth.password_set')->sole()->subject)->toStartWith('Pick your ');
});

it('falls back to the Laravel notification when no template is installed', function () {
    Mail::fake();
    Notification::fake();
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)->postJson(route('password.set-link'))->assertOk();

    Notification::assertSentTo($user, SetPasswordNotification::class);
    Mail::assertNothingSent();

    $html = (string) (new SetPasswordNotification('T'))->toMail($user)->render();
    expect($html)->toContain('data-mail-layout="brand"')->not->toContain('Laravel');
});
