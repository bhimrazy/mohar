<?php

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

/*
 * Security review PR #28, B1 final design: one-time links reach only the fixed system templates, and those are
 * structure-only. Each disallowed construct is rejected on save, and a template written straight to the
 * database still renders none of it in the HTML or text part.
 */

dataset('disallowed constructs', [
    'Markdown image' => ['body', 'Hello ![pixel](https://evil.test/p.png)'],
    'raw HTML tag' => ['body', 'Hello <img src="https://evil.test/p.png"> there'],
    'Markdown link' => ['body', 'Hello [click here](https://evil.test/x) now'],
    'autolink' => ['body', 'Hello <https://evil.test/x> now'],
    'bare URL' => ['body', 'Visit https://evil.test/x today'],
    'protocol-relative URL' => ['body', 'Visit //evil.test/x today'],
    'data URI' => ['body', 'Open data:text/html;base64,PHNjcmlwdD4= now'],
    'bare domain' => ['body', 'Visit evil.test for help'],
    'secret field in the body' => ['body', 'Your link: {{ auth.reset_url }}'],
    'secret field in the subject' => ['subject', 'Reset {{ auth.reset_url }}'],
    'secret field in the heading' => ['heading', 'Use {{ auth.reset_url }}'],
    'link in the button label' => ['action_label', 'Go to https://evil.test'],
]);

function resetTranslationPayload(array $overrides = []): array
{
    return [
        'name' => 'Password reset link', 'locale' => 'en',
        'subject' => 'Reset your password', 'heading' => 'Reset your password', 'action_label' => 'Choose a new password',
        'body' => "Someone asked to reset the password for {{ user.email }}.\n\nIf you didn't ask for this, ignore this email.",
        ...$overrides,
    ];
}

function secretTemplate(string $key = 'auth.password_reset'): EmailTemplate
{
    return EmailTemplate::query()->where('key', $key)->sole();
}

it('rejects the construct on save', function (string $field, string $value) {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->put(route('admin.notifications.templates.update', secretTemplate()), resetTranslationPayload([$field => $value]))
        ->assertSessionHasErrors($field);
})->with('disallowed constructs');

it('accepts plain-text wording with allow-listed merge fields', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->put(route('admin.notifications.templates.update', secretTemplate()), resetTranslationPayload())->assertSessionHasNoErrors();

    expect(secretTemplate()->translations()->where('locale', 'en')->sole())
        ->heading->toBe('Reset your password')->action_label->toBe('Choose a new password');
});

it('renders none of it when the template was written to the database directly', function (string $field, string $value) {
    Mail::fake();
    seedEmailTemplates();
    secretTemplate()->translations()->update([$field => $value]);
    $user = User::factory()->create(['email' => 'ann@example.test']);

    app(AuthMailer::class)->sendPasswordReset($user, 'TOKEN123');

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        $html = (string) $mail->htmlBody;
        $text = $mail->plainText;
        $body = substr($html, (int) strpos($html, 'class="content-cell"'));

        return ! str_contains($html.$text, 'evil.test')
            && ! str_contains($html.$text, 'data:text')
            && ! preg_match('/<img(?![^>]*brand-logo)/i', $body)
            && substr_count($html, 'TOKEN123') === 1      // the system button only
            && substr_count($text, 'TOKEN123') === 1      // the system "Label: url" line only
            && ! str_contains($mail->subjectLine, 'TOKEN123');
    });
})->with('disallowed constructs');

it('writes the action as a system line in the text part', function () {
    Mail::fake();
    seedEmailTemplates();
    $user = User::factory()->create();

    app(AuthMailer::class)->sendPasswordReset($user, 'TOKEN123');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => preg_match('#^Choose a new password: https?://\S+/reset-password/TOKEN123\S*$#m', $mail->plainText) === 1);
});

it('rejects one-time links in custom templates and renders them empty', function () {
    Mail::fake();
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->post(route('admin.notifications.templates.store'), ['key' => 'custom.reset_copy', 'type' => 'general', 'name' => 'Copy', 'locale' => 'en', 'subject' => 'Reset', 'body' => '[Reset]({{ auth.reset_url }})'])
        ->assertSessionHasErrors('body');

    // A renamed or duplicated copy written to the database directly never receives the link.
    $copy = EmailTemplate::factory()->withBody('Reset {{ auth.verify_url }}', "[Reset]({{ auth.reset_url }})\n\nToken: {{ auth.token }}")->create(['key' => 'custom.reset_copy']);
    $owner = Client::factory()->create(['email' => 'owner@example.test']);
    app(NotificationMailer::class)->send(new EmailRequest($copy->key, [app(RecipientResolver::class)->owner($owner)], [
        'auth' => ['reset_url' => 'https://localhost/reset-password/LEAK1', 'verify_url' => 'https://localhost/verify/LEAK2', 'token' => 'LEAK3'],
    ], 'copy', $owner->id));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => ! preg_match('/LEAK[123]/', $mail->subjectLine.$mail->htmlBody.$mail->plainText));
});

it('strips CC and BCC from every secret template, and from a duplicated one sent with a secret', function () {
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->update(['cc' => json_encode(['cc@example.test']), 'bcc' => json_encode(['bcc@example.test'])]);
    $copy = EmailTemplate::factory()->withBody('Reset', 'Reset your password.')->create(['key' => 'custom.reset_copy', 'bcc' => ['bcc@example.test']]);
    $client = Client::factory()->create(['email' => 'owner@example.test']);
    $recipient = app(RecipientResolver::class)->owner($client);
    $context = ['auth' => ['reset_url' => url('/reset-password/T'), 'verify_url' => url('/verify/T'), 'invite_url' => url('/invite/T'), 'expires_at' => 'soon']];

    foreach (array_keys(SecretTemplatePolicy::ACTION_LINKS) as $key) {
        app(NotificationMailer::class)->send(new EmailRequest($key, [$recipient], $context, 'cc|'.$key, $client->id));
    }
    app(NotificationMailer::class)->send(new EmailRequest($copy->key, [$recipient], [], 'cc|copy', $client->id, carriesSecret: true));

    $messages = EmailMessage::query()->whereIn('template_key', [...array_keys(SecretTemplatePolicy::ACTION_LINKS), $copy->key])->get();
    expect($messages)->toHaveCount(6)
        ->and($messages->pluck('cc')->filter()->all())->toBe([])
        ->and($messages->pluck('bcc')->filter()->all())->toBe([]);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->cc === [] && $mail->bcc === []);
});

it('previews and test-sends with a fake link and never issues a real reset token', function () {
    Mail::fake();
    seedEmailTemplates();
    $staff = actingAsStaff(['notifications.templates.view']);

    $this->postJson(route('admin.notifications.templates.preview', secretTemplate()), resetTranslationPayload())
        ->assertOk()->assertJsonPath('error', null)
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'SAMPLE-LINK-NOT-VALID'));
    $this->post(route('admin.notifications.templates.test', secretTemplate()))->assertRedirect();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo($staff->email) && str_contains((string) $mail->htmlBody, 'SAMPLE-LINK-NOT-VALID'));
});

it('takes the allowed link host from configuration only', function () {
    config(['app.url' => 'https://billing.example.test']);

    expect(SecretTemplatePolicy::allowedHosts())->toBe(['billing.example.test'])
        ->and(fn () => SecretTemplatePolicy::actionUrl('auth.password_reset', ['auth' => ['reset_url' => 'https://evil.test/reset/T']]))
        ->toThrow(TemplateSyntaxError::class);
});
