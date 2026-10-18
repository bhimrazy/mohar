<?php

use App\Contracts\Auth\AuthMailer;
use App\Data\Notifications\SensitiveValue;
use App\Events\Billing\InvoicePaid;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Support\EventKey;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Modules\Notifications\Webhooks\UnsafeWebhookTarget;
use App\Modules\Notifications\Webhooks\WebhookUrlGuard;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * Regression tests for the PR #28 security review (B1 blocking; M1, M2; L1–L7).
 */

it('B1: staff with notifications.* cannot get a client\'s reset token by any route', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $staff = User::factory()->staff(['notifications.*'])->create();
    $victim = User::factory()->create(['email' => 'victim@example.test']);

    // Route 1: the global BCC setting (now its own sensitive section), even after confirming the password.
    $this->actingAs($staff);
    confirmPassword();
    $this
        ->put(route('admin.platform.settings.update', ['section' => 'email_delivery']), ['email_delivery' => ['notifications.mail_bcc' => 'thief@evil.test']])
        ->assertForbidden();
    expect(app(Settings::class)->get('notifications.mail_bcc'))->toBe('');

    // Route 2: CC/BCC or sender on the reset template (templates.manage is sensitive: the wildcard doesn't grant it).
    $this->put(route('admin.notifications.templates.update', EmailTemplate::query()->where('key', 'auth.password_reset')->sole()), [
        'name' => 'x', 'locale' => 'en', 'subject' => 'x', 'body' => 'x', 'bcc' => 'thief@evil.test',
    ])->assertForbidden();

    // Route 3: manual send of a link-carrying template (refused; manual sends never carry copies either).
    $this->post(route('admin.notifications.clients.email.store', Client::factory()->create()), ['template_key' => 'auth.password_reset'])->assertSessionHasErrors('template_key');

    auth()->logout();
    $this->post(route('password.email'), ['email' => $victim->email]);

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('victim@example.test') && $mail->bcc === [] && $mail->cc === []);
});

it('B1: secret-bearing mail is never copied, even when a global BCC and template copies are configured', function () {
    Mail::fake();
    seedEmailTemplates();
    app(Settings::class)->set('notifications.mail_bcc', 'archive@example.test');
    EmailTemplate::query()->whereIn('key', ['auth.password_reset', 'staff.password_reset', 'invoice.created'])->update(['cc' => json_encode(['cc@example.test']), 'bcc' => json_encode(['bcc@example.test'])]);
    $client = User::factory()->create(['email' => 'ann@example.test']);
    $admin = fullAdministrator('admin@example.test');
    $owner = Client::factory()->create(['email' => 'owner@example.test']);
    fakeMergeFields(['invoice' => ['number' => 'INV-1']]);

    $this->post(route('password.email'), ['email' => $client->email]);
    $this->post(route('password.email'), ['email' => $admin->email]);
    app(NotificationMailer::class)->send(new EmailRequest('invoice.created', [app(RecipientResolver::class)->owner($owner)], [], 'b1|invoice', $owner->id));

    foreach (['auth.password_reset', 'staff.password_reset'] as $key) {
        expect(EmailMessage::query()->where('template_key', $key)->sole())->cc->toBeNull()->bcc->toBeNull();
    }
    expect(EmailMessage::query()->where('template_key', 'invoice.created')->sole()->bcc)->toBe(['bcc@example.test', 'archive@example.test']);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('ann@example.test') && $mail->bcc === [] && $mail->cc === []);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('admin@example.test') && $mail->bcc === [] && $mail->cc === []);
});

it('M1: a pathological template fails fast in preview and at send time', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view']);
    $template = EmailTemplate::query()->where('key', 'service.welcome.other')->sole();
    $deep = str_repeat('{% for a in service.nameservers %}', 18).'x'.str_repeat('{% endfor %}', 18);

    $started = microtime(true);
    $this->postJson(route('admin.notifications.templates.preview', $template), ['subject' => 's', 'body' => $deep])
        ->assertOk()->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'nested more than'));
    expect(microtime(true) - $started)->toBeLessThan(2.0);

    $engine = new TemplateEngine;
    $wide = str_repeat('{% for a in list %}', 3).'x'.str_repeat('{% endfor %}', 3);
    expect(fn () => $engine->render($wide, ['list' => range(1, 30)]))->toThrow(TemplateSyntaxError::class, 'loops more than')
        ->and(fn () => $engine->render('{% for a in list %}{{ big }}{% endfor %}', ['list' => range(1, 20), 'big' => str_repeat('a', 20_000)]))->toThrow(TemplateSyntaxError::class, 'larger than');
});

it('M2: forgot-password answers the same for known and unknown addresses and is throttled', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

    expect($known->getSession()?->get('status'))->toBe($unknown->getSession()?->get('status'))
        ->and($known->getSession()?->get('status'))->not->toBeNull();
    $unknown->assertSessionHasNoErrors();

    // Five requests a minute per IP (two used above).
    foreach (range(1, 3) as $attempt) {
        $this->post(route('password.email'), ['email' => "other{$attempt}@example.test"])->assertRedirect();
    }
    $this->post(route('password.email'), ['email' => 'again@example.test'])->assertStatus(429);
});

it('L1: multi-line values cannot start headings or lists', function () {
    $html = (string) app(EmailComposer::class)->composeSource('s', "Reason: {{ cancellation.reason }}\n\n{{ cancellation.reason }}", ['cancellation' => ['reason' => "Acme\n\n# Heading\n- list\n1. one"]], 'en')->html;

    expect($html)->not->toContain('<h1>Heading')->not->toContain('<li>list')->not->toContain('<ol')
        ->toContain('# Heading');
});

it('L2: the occurrence id survives serialisation before its first read', function () {
    $event = new InvoicePaid(7, 1, []);
    $copy = unserialize(serialize($event));

    expect(EventKey::of($copy))->toBe(EventKey::of($event))
        ->and(EventKey::of(unserialize(serialize($event))))->toBe(EventKey::of($event));
});

it('L3: a custom template can\'t put the one-time link in its subject (it renders empty)', function () {
    Mail::fake();
    $template = EmailTemplate::factory()->withBody('Reset via {{ auth.reset_url }}', 'Body')->create(['key' => 'custom.reset_subject']);
    $owner = Client::factory()->create(['email' => 'owner@example.test']);

    app(NotificationMailer::class)->send(new EmailRequest($template->key, [app(RecipientResolver::class)->owner($owner)], ['auth' => ['reset_url' => new SensitiveValue('https://billing.test/reset/SECRET')]], 'l3', $owner->id));

    expect(EmailMessage::query()->sole()->subject)->toBe('Reset via');
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->subjectLine === 'Reset via');
});

it('L4: non-owner logins only see email history addressed to them', function () {
    [$owner, $client] = actingAsClient();
    $member = User::factory()->create(['email' => 'member@example.test']);
    $client->users()->attach($member, ['is_owner' => false, 'permissions' => json_encode(['profile'])]);
    $toOwner = EmailMessage::factory()->create(['client_id' => $client->id, 'recipient' => $owner->email]);
    $toMember = EmailMessage::factory()->create(['client_id' => $client->id, 'user_id' => $member->id, 'recipient' => 'member@example.test']);

    $this->get(route('client.notifications.emails.index'))->assertInertia(fn ($page) => $page->has('messages.data', 2));

    $this->actingAs($member);
    $this->get(route('client.notifications.emails.index'))->assertInertia(fn ($page) => $page->has('messages.data', 1)->where('messages.data.0.uuid', $toMember->uuid));
    $this->get(route('client.notifications.emails.show', $toOwner->uuid))->assertNotFound();
});

it('L6: blocks 6to4 and Teredo targets and non-web ports', function (string $url) {
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['2002:7f00:1::'];
        }
    });

    expect(fn () => app(WebhookUrlGuard::class)->vet($url))->toThrow(UnsafeWebhookTarget::class);
})->with(['https://[2002:7f00:1::]/', 'https://[2001::1]/', 'https://hooks.example.com:22/', 'https://hooks.example.com:8443/', 'https://hooks.example.com/']);

it('L7: webhook URLs are logged without path, query or credentials', function () {
    actingAsStaff(['notifications.webhooks.manage']);
    confirmPassword();

    $this->post(route('admin.notifications.webhooks.store'), ['url' => 'https://hooks.example.com/services/T000/B000/SECRETTOKEN?key=abc', 'events' => ['*']]);

    $logged = json_encode(ActivityLog::query()->where('description', 'Webhook endpoint created')->sole()->properties);
    expect($logged)->not->toContain('SECRETTOKEN')->not->toContain('key=abc')->toContain('hooks.example.com');
});

it('B1: the logged copy of a reset email shows •••• and never the token', function () {
    Mail::fake();
    seedEmailTemplates();
    $user = User::factory()->create();

    app(AuthMailer::class)->sendPasswordReset($user, 'LOGGED-TOKEN-1');

    $logged = EmailMessage::query()->where('template_key', 'auth.password_reset')->sole();
    expect($logged->has_redactions)->toBeTrue()
        ->and($logged->subject.$logged->body_html.$logged->body_text)->not->toContain('LOGGED-TOKEN-1')
        ->and($logged->body_text)->toContain('••••');
});

it('B1: senders, copies and the account-link host need notifications.delivery.manage, not templates or settings rights', function () {
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage', 'notifications.settings.manage']);
    confirmPassword();

    $this->put(route('admin.platform.settings.update', ['section' => 'email_delivery']), ['email_delivery' => ['notifications.auth_link_host' => 'evil.test']])
        ->assertForbidden();
    expect(app(Settings::class)->get('notifications.auth_link_host'))->toBe('');
});

it('B1: a forged Host on forgot-password never produces a foreign link, on either mail path', function (bool $templated) {
    Mail::fake();
    Notification::fake();
    config(['app.url' => 'https://billing.example.test']);
    seedEmailTemplates();
    if (! $templated) {
        app()->instance(AuthMailer::class, new LaravelAuthMailer);
    }
    $user = User::factory()->create(['email' => 'victim@example.test']);

    $this->post('https://evil.test/forgot-password', ['email' => $user->email]);

    if ($templated) {
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'https://billing.example.test/reset-password/')
            && ! str_contains((string) $mail->htmlBody.$mail->plainText, 'evil.test'));
    } else {
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://billing.example.test/reset-password/') && ! str_contains($url, 'evil.test');
        });
    }
})->with(['templated (Notifications)' => [true], 'Laravel default (no Notifications)' => [false]]);

it('B1: a forged Host never produces a foreign verification link either', function () {
    Mail::fake();
    config(['app.url' => 'https://billing.example.test']);
    seedEmailTemplates();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('https://evil.test/email/verification-notification');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'https://billing.example.test/email/verify/')
        && ! str_contains((string) $mail->htmlBody.$mail->plainText, 'evil.test'));
});
