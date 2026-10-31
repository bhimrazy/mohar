<?php

use App\Contracts\Auth\AuthMailer;
use App\Data\Notifications\SensitiveValue;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\SecretTemplatePolicy;
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
    // Filter arguments (sign-off probes): literals and paths smuggled through default(…) / date(…).
    'default() literal with a URL' => ['body', '{{ staff.first_name | default("Reset here instead: https://evil.test/login") }}'],
    'date() format spelling a URL' => ['body', '{{ system.today | date("\\h\\t\\t\\p\\s://\\e\\v\\i\\l.\\t\\e\\s\\t/login") }}'],
    'default() domain in the button label' => ['action_label', '{{ staff.first_name | default("evil.test") }}'],
    'default() domain in the heading' => ['heading', '{{ staff.email | default("Go to evil.test") }}'],
    'secret field as a filter argument' => ['body', '{{ staff.first_name | default(auth.reset_url) }}'],
    // URL-like text the old pattern missed.
    'IDN domain' => ['body', 'Visit пример.рф for help'],
    'punycode domain' => ['body', 'Visit xn--e1afmkfd.xn--p1ai for help'],
    'bare IPv4 with a path' => ['body', 'Open 203.0.113.9/reset now'],
    'full-width dots' => ['body', 'Visit evil。test now'],
    // Caught only by the save-time checks (rendering strips them anyway): kept explicit so they stay tested.
    'non-allow-listed merge field' => ['body', 'Invoice {{ invoice.number }}'],
    'relative Markdown link' => ['body', 'Hello [click](reset) now'],
    'HTML tag without a URL' => ['body', 'Hello <b>there</b>'],
]);

function resetTranslationPayload(array $overrides = []): array
{
    return [
        'name' => 'Password reset link', 'locale' => 'en',
        'subject' => 'Reset your password', 'heading' => 'Reset your password', 'action_label' => 'Choose a new password',
        'body' => "Someone asked to reset the password for your {{ company.name }} account, {{ user.first_name }}.\n\nIf you didn't ask for this, ignore this email.",
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

        return ! preg_match('/evil\.test|evil。test|пример|xn--|203\.0\.113\.9/u', $html.$text)
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
        'auth' => ['reset_url' => new SensitiveValue('https://localhost/reset-password/LEAK1'), 'verify_url' => new SensitiveValue('https://localhost/verify/LEAK2'), 'token' => new SensitiveValue('LEAK3')],
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
    $context = ['auth' => ['reset_url' => new SensitiveValue(url('/reset-password/T')), 'verify_url' => new SensitiveValue(url('/verify/T')), 'invite_url' => new SensitiveValue(url('/invite/T')), 'expires_at' => 'soon']];

    foreach (array_keys(SecretTemplatePolicy::ACTION_LINKS) as $key) {
        app(NotificationMailer::class)->send(new EmailRequest($key, [$recipient], $context, 'cc|'.$key, $client->id));
    }
    // A duplicated template asked to carry a secret fails closed: nothing is sent, no copies recorded.
    app(NotificationMailer::class)->send(new EmailRequest($copy->key, [$recipient], [], 'cc|copy', $client->id, carriesSecret: true));

    $messages = EmailMessage::query()->whereIn('template_key', [...array_keys(SecretTemplatePolicy::ACTION_LINKS), $copy->key])->get();
    expect($messages)->toHaveCount(count(SecretTemplatePolicy::ACTION_LINKS) + 1)
        ->and($messages->pluck('cc')->filter()->all())->toBe([])
        ->and($messages->pluck('bcc')->filter()->all())->toBe([])
        ->and($messages->firstWhere('template_key', $copy->key)->status->value)->toBe('failed');
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

it('takes the allowed origin from configuration only, port and user info included', function () {
    config(['app.url' => 'https://billing.example.test']);

    expect(SecretTemplatePolicy::allowedOrigins())->toBe(['https://billing.example.test:443'])
        ->and(SecretTemplatePolicy::isAllowedLink('https://billing.example.test/reset-password/T'))->toBeTrue()
        ->and(SecretTemplatePolicy::isAllowedLink('https://billing.example.test:4444/reset-password/T'))->toBeFalse()
        ->and(SecretTemplatePolicy::isAllowedLink('https://evil.test\\@billing.example.test/reset-password/T'))->toBeFalse()
        ->and(SecretTemplatePolicy::isAllowedLink('https://user@billing.example.test/reset-password/T'))->toBeFalse()
        ->and(SecretTemplatePolicy::isAllowedLink('https://evil.test/reset-password/T'))->toBeFalse()
        ->and(fn () => SecretTemplatePolicy::actionUrl('auth.password_reset', ['auth' => ['reset_url' => new SensitiveValue('https://evil.test/reset/T')]]))
        ->toThrow(TemplateSyntaxError::class);
});

it('refuses a disallowed construct in the preview too, before rendering', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view']);

    $this->postJson(route('admin.notifications.templates.preview', secretTemplate()), resetTranslationPayload(['body' => 'Hello <b>there</b> {{ invoice.number }}']))
        ->assertOk()
        ->assertJsonPath('html', null)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'Plain text only') && str_contains($error, 'merge fields'));
});

it('treats a secret-looking plain value from a resolver as a bug (backstop)', function () {
    seedEmailTemplates();
    $owner = Client::factory()->create();
    $template = EmailTemplate::factory()->withBody('Hi', 'Body')->create(['key' => 'custom.backstop']);

    expect(fn () => app(NotificationMailer::class)->send(new EmailRequest($template->key, [app(RecipientResolver::class)->owner($owner)], ['client' => ['login_link' => 'https://localhost/magic/X']], 'backstop', $owner->id)))
        ->toThrow(LogicException::class, 'SensitiveValue');
});

it('links a guest\'s ticket only through the system button, never from custom mail, and never copies it', function () {
    Mail::fake();
    seedEmailTemplates();
    $guestUrl = url('/support/tickets/482913?key=GUESTKEY');
    $ticket = ['ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'requester_email' => 'guest@example.test', 'url' => new SensitiveValue($guestUrl, secret: 'GUESTKEY')]];
    $reply = EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole();
    $reply->update(['bcc' => ['archive@example.test']]);
    $reply->translations()->update(['body' => "![p](https://evil.test/p.png?k={{ ticket.url }})\n\nKey: {{ ticket.url }}\n\n[View ticket]({{ ticket.url }})"]);
    $custom = EmailTemplate::factory()->withBody('Copy', '[View]({{ ticket.url }})')->create(['key' => 'custom.ticket_copy']);
    $guest = app(RecipientResolver::class)->addresses(['guest@example.test']);

    app(NotificationMailer::class)->send(new EmailRequest('ticket.autoresponse', $guest, $ticket, 'guest|1'));
    app(NotificationMailer::class)->send(new EmailRequest($custom->key, $guest, $ticket, 'guest|2'));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains($mail->subjectLine, '482913')
        && substr_count((string) $mail->htmlBody, 'GUESTKEY') === 1 && str_contains((string) $mail->htmlBody, 'href="'.e($guestUrl).'"')
        && ! preg_match('/evil\.test[^"]*GUESTKEY/', (string) $mail->htmlBody) && $mail->bcc === []);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === 'Copy' && ! str_contains((string) $mail->htmlBody.$mail->plainText, 'GUESTKEY'));
    expect(EmailMessage::query()->where('template_key', 'ticket.autoresponse')->sole())
        ->has_redactions->toBeTrue()->bcc->toBeNull()
        ->body_html->not->toContain('GUESTKEY');
});
