<?php

use App\Contracts\Notifications\SystemMailer;
use App\Data\Notifications\SensitiveValue;
use App\Enums\BillableType;
use App\Enums\SystemMailOutcome;
use App\Events\Services\ServiceSuspended;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Modules\Notifications\Mail\MailFramer;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Staff\InviteStaff;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Core\Notifications\SecurityNotice;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use App\Modules\Platform\Support\AccountMail;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Support\Services\MarkdownRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/*
 * Fixes from the PR #72 reviews (security, review board, product). The security reviewer's probes are kept as
 * regression tests (P1–P6).
 */

beforeEach(fn () => seedEmailTemplates());

function reviewCompose(string $key, array $context): ComposedEmail
{
    return app(EmailComposer::class)->compose(EmailTemplate::query()->with('translations')->where('key', $key)->sole(), 'en', $context);
}

function inProduction(Closure $callback): mixed
{
    app()['env'] = 'production';

    try {
        return $callback();
    } finally {
        app()['env'] = 'testing';
    }
}

/**
 * A SystemMailer that answers every request with one outcome.
 */
function systemMailerSaying(SystemMailOutcome $outcome): void
{
    app()->instance(SystemMailer::class, new class($outcome) implements SystemMailer
    {
        public function __construct(private SystemMailOutcome $outcome) {}

        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return $this->outcome;
        }
    });
}

function staffInvite(): StaffInvitation
{
    $admin = User::factory()->staff(['*'])->create();

    return app(InviteStaff::class)->handle($admin, 'new.agent@example.test', 'New Agent', Role::factory()->withPermissions(['support.tickets.view'])->create());
}

// ── B1: a refused invitation is never sent another way ──────────────────────────────────────────────────────────

it('P1a refuses a staff invitation before creating it when its link could not be on the site address', function () {
    Mail::fake();
    Notification::fake();
    config(['app.url' => 'http://billing.test']);

    expect(fn () => inProduction(fn () => staffInvite()))->toThrow(ValidationException::class, 'Invitation not sent: the link host isn\'t your site address.');

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect(StaffInvitation::query()->count())->toBe(0);
});

it('sends nothing and tells staff when the template system refuses a staff invitation', function () {
    Notification::fake();
    systemMailerSaying(SystemMailOutcome::Refused);

    expect(fn () => staffInvite())->toThrow(ValidationException::class, 'Invitation not sent: its link failed the security check.');

    Notification::assertNothingSent();
    expect(StaffInvitation::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'security')->sole()->description)->toBe('Staff invitation not sent: its link failed the security check');
});

it('sends the staff invitation notification, with its link on APP_URL, only when no template system can take it', function () {
    Notification::fake();
    systemMailerSaying(SystemMailOutcome::Unavailable);
    request()->headers->set('host', 'evil.test');

    staffInvite();

    Notification::assertSentOnDemand(StaffInvitationNotification::class, fn (StaffInvitationNotification $notification): bool => str_starts_with(
        (string) $notification->acceptUrl, rtrim((string) config('app.url'), '/').'/staff-invitation/'
    ));
});

it('P1b refuses a client invitation before creating the login when its link could not be mailed', function () {
    Mail::fake();
    Notification::fake();
    config(['app.url' => 'http://billing.test']);
    $client = Client::factory()->create();

    expect(fn () => inProduction(fn () => app(ManageClientLogins::class)->invite($client, 'helper@example.test', 'Hari Helper', ['billing'])))
        ->toThrow(ValidationException::class, 'Invitation not sent');

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect(User::query()->where('email', 'helper@example.test')->exists())->toBeFalse();
});

it('does not fall back to the password-reset email when the template system refuses a client invitation', function () {
    Notification::fake();
    systemMailerSaying(SystemMailOutcome::Refused);
    $client = Client::factory()->create();

    expect(fn () => app(ManageClientLogins::class)->invite($client, 'helper@example.test', 'Hari', ['billing']))->toThrow(ValidationException::class, 'the invitation was not sent');

    Notification::assertNothingSent();
    expect(ActivityLog::query()->where('event', 'security')->sole()->description)->toBe('Invitation not sent: its link failed the security check');
});

it('P1c invitation mail is never copied, is masked in the log and is never resent', function () {
    Mail::fake();
    Notification::fake();
    app(Settings::class)->set('notifications.mail_bcc', 'audit@example.test');
    EmailTemplate::query()->whereIn('key', ['auth.user_invitation', 'staff.invitation'])->update(['cc' => ['cc@example.test'], 'bcc' => ['bcc@example.test']]);

    app(ManageClientLogins::class)->invite(Client::factory()->create(), 'helper@example.test', 'Hari Helper', ['billing']);
    staffInvite();

    foreach (EmailMessage::query()->whereIn('template_key', ['auth.user_invitation', 'staff.invitation'])->get() as $message) {
        expect($message->cc)->toBeNull()->and($message->bcc)->toBeNull()->and($message->has_redactions)->toBeTrue()
            ->and($message->body_html.$message->body_text)->not->toContain('reset-password/', 'staff-invitation/')
            ->and(fn () => app(NotificationMailer::class)->resend($message))->toThrow(LogicException::class);
    }
});

it('P1d keeps a tampered staff invitation template structure-only', function () {
    Mail::fake();
    Notification::fake();
    EmailTemplate::query()->where('key', 'staff.invitation')->sole()->translations()->update([
        'body' => "Hi <a href=\"https://evil.test/x\">click</a> [go](https://evil.test/y) evil.test/z {{ auth.invite_url }} {{ staff.first_name }}\n\nMore",
        'preheader' => 'see https://evil.test/p',
        'heading' => '<b>Accept</b> evil.test',
    ]);

    staffInvite();

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => ! str_contains($mail->htmlBody.$mail->plainText.$mail->subjectLine, 'evil.test')
        && substr_count((string) $mail->htmlBody, '/staff-invitation/') === 1 && substr_count($mail->plainText, '/staff-invitation/') === 1);
});

// ── B2: only the template or the system makes the button ────────────────────────────────────────────────────────

dataset('staff ticket mail', ['staff.ticket_new', 'staff.ticket_client_reply', 'staff.ticket_mention']);

it('keeps the admin link as the button when a client message holds a single link', function (string $key) {
    $body = app(MarkdownRenderer::class)->render('[Open ticket](https://evil.test/admin/login)');
    $admin = url('/admin/support/tickets/9');
    $composed = reviewCompose($key, ['ticket' => ['number' => '9', 'subject' => 'Help', 'requester_name' => 'Guest', 'department' => 'Sales', 'priority' => 'Low', 'admin_url' => $admin], 'reply' => ['body_html' => $body, 'author_name' => 'Guest']]);
    $html = (string) $composed->html;

    preg_match_all('#<a [^>]*class="button"[^>]*>#', $html, $buttons);
    expect($buttons[0])->toHaveCount(1)
        ->and($buttons[0][0])->toContain('href="'.$admin.'"')
        ->and(strpos($html, 'class="button"'))->toBeLessThan(strpos($html, '<blockquote'))
        ->and($composed->text)->toContain('Open ticket: '.$admin)->not->toContain('Open ticket: https://evil.test');
})->with('staff ticket mail');

it('never turns a merged value into a button', function () {
    $composed = app(EmailComposer::class)->composeSource('s', "{{ note }}\n\n[Pay now]({{ url }})", ['note' => 'https://evil.test/pay', 'url' => 'https://billing.test/i/1'], 'en');

    preg_match_all('#<a [^>]*class="button"[^>]*>#', (string) $composed->html, $buttons);
    expect($buttons[0])->toHaveCount(1)->and($buttons[0][0])->toContain('href="https://billing.test/i/1"');
});

// ── M1, M2: the text part follows the HTML; untrusted names never become links ───────────────────────────────────

function reviewGuestReply(string $replyHtml): array
{
    $context = [
        'ticket' => ['number' => '482913', 'subject' => 'Help', 'department' => 'Support', 'priority' => 'High', 'requester_email' => 'guest@example.test', 'url' => new SensitiveValue(url('/support/tickets/482913?key=GUESTKEY'), secret: 'GUESTKEY')],
        'recipient' => ['email' => 'guest@example.test'],
        'reply' => ['author_name' => 'Sam', 'body_html' => $replyHtml, 'excerpt' => ''],
    ];

    try {
        $composed = reviewCompose('ticket.reply', $context);

        return ['html' => (string) $composed->html, 'text' => $composed->text, 'stored' => (string) $composed->storedHtml.$composed->storedText, 'blocked' => false];
    } catch (TemplateSyntaxError $error) {
        return ['html' => '', 'text' => '', 'stored' => '', 'blocked' => $error->getMessage()];
    }
}

it('P2a keeps the guest key to the system line of the text part', function (string $reply) {
    $result = reviewGuestReply($reply);

    if ($result['blocked'] !== false) {
        expect($result['blocked'])->toBeString();

        return;
    }

    expect(SecretTemplatePolicy::secretCount($result['text'], 'GUESTKEY'))->toBe(1)
        ->and(SecretTemplatePolicy::secretCount($result['stored'], 'GUESTKEY'))->toBe(0);
})->with([
    'plain' => ['<p>key GUESTKEY</p>'],
    'entity' => ['<p>&#71;&#85;ESTKEY</p>'],
    'percent in href' => ['<p><a href="http://localhost/x?k=%47UESTKEY">x</a></p>'],
    'double percent' => ['<p><a href="https://evil.test/?k=%2547UESTKEY">x</a></p>'],
    'img alt' => ['<p><img alt="GUESTKEY" src="x"></p>'],
    'textarea' => ['<textarea>GUESTKEY</textarea>'],
    'noscript' => ['<noscript>GUESTKEY</noscript>'],
    'span split' => ['<p>GUEST<span>KEY</span></p>'],
    'link text equals href' => ['<p><a href="http://localhost/support/tickets/482913?key=GUESTKEY">http://localhost/support/tickets/482913?key=GUESTKEY</a></p>'],
    'mixed case' => ['<p>guestkey</p>'],
    'pre block' => ['<pre>GUESTKEY</pre>'],
    'title tag' => ['<title>GUESTKEY</title>'],
]);

it('P2b drops an outside link from the text part when the HTML drops it, keeping its text', function () {
    $result = reviewGuestReply('<p>Read <a href="https://evil.test/login">our guide</a> and <img alt="logo" src="https://evil.test/p.png"></p>');

    expect($result['html'])->not->toContain('evil.test')
        ->and($result['text'])->not->toContain('evil.test')->toContain('Read our guide');
});

it('P3b never turns a ticket subject or requester name into a link, in either part', function () {
    $composed = reviewCompose('staff.ticket_new', ['ticket' => ['number' => '9', 'subject' => "<a href=\"https://evil.test/a\">Open ticket</a>\r\nX-Injected: 1", 'requester_name' => '[Open](https://evil.test/b)', 'department' => 'Sales', 'priority' => 'Low', 'admin_url' => url('/admin/support/tickets/9')], 'reply' => ['body_html' => '<p>hi</p>', 'author_name' => 'x']]);

    expect($composed->subject)->not->toMatch('/[\r\n]/')
        ->and((string) $composed->html)->not->toContain('href="https://evil.test', 'evil.test/')
        ->and($composed->text)->not->toContain('evil.test/')->toContain('evil[.]test');
});

it('leaves names that merely contain a domain as typed, and defangs only links', function () {
    expect(SecretTemplatePolicy::defang('Example.com Ltd'))->toBe('Example.com Ltd')
        ->and(SecretTemplatePolicy::defang('Help with example.com'))->toBe('Help with example.com')
        ->and(SecretTemplatePolicy::defang('Pay at https://evil.example/pay'))->toBe('Pay at https[:]//evil[.]example/pay')
        ->and(SecretTemplatePolicy::defang('see evil.example/pay or www.evil.example'))->toBe('see evil[.]example/pay or www[.]evil[.]example')
        ->and(SecretTemplatePolicy::defang('mailto:x@evil.example'))->toBe('mailto[:]x@evil[.]example')
        ->and(SecretTemplatePolicy::defang('exämple。test/pay'))->toBe('exämple[.]test/pay');

    $composed = reviewCompose('client.welcome', ['client' => ['first_name' => 'Ann', 'company_name' => 'Example.com Ltd'], 'recipient' => ['first_name' => 'Ann']]);
    expect(SecretTemplatePolicy::neutraliseUntrusted(['client' => ['company_name' => 'Example.com Ltd']])['client']['company_name'])->toBe('Example.com Ltd')
        ->and($composed->text)->not->toContain('[.]');
});

it('never shows the button token, in any bundled template or its log copy', function () {
    Mail::fake();
    $client = Client::factory()->create(['email' => 'owner@example.test']);
    $samples = app(SampleContext::class);

    foreach (EmailTemplate::query()->with('translations')->get() as $template) {
        if ($template->carriesSecret()) {
            continue;
        }

        $template->translations->first()->update(['preheader' => null]);
        $composed = app(EmailComposer::class)->compose($template->fresh('translations'), 'en', $samples->build($template->audience->value === 'staff', $template->key));

        expect($composed->subject.$composed->html.$composed->text.$composed->storedHtml.$composed->storedText)->not->toContain('zzbtn', $template->key);
    }

    app(NotificationMailer::class)->send(new EmailRequest('invoice.created', [app(RecipientResolver::class)->owner($client)], $samples->build(false, 'invoice.created'), 'token|1', $client->id));
    expect(EmailMessage::query()->sole()->only('subject', 'body_html', 'body_text'))->each->not->toContain('zzbtn');
});

it('P3c escapes and defangs a client account name in the login-added notice', function () {
    Mail::fake();
    $client = Client::factory()->create(['company_name' => '<b>Evil</b> evil.test/login']);
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $client->users()->attach($owner, ['is_owner' => true]);

    app(ManageClientLogins::class)->invite($client, 'helper@example.test', 'Hari', ['billing']);

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('owner@example.test')
        && ! str_contains((string) $mail->htmlBody, '<b>Evil') && ! str_contains($mail->htmlBody.$mail->plainText, 'evil.test/login'));
});

// ── P5, P6: demo suppression and headers ─────────────────────────────────────────────────────────────────────────

it('P5 a demo seeder refuses production before suppressing anything, and never leaves suppression on', function () {
    Mail::fake();
    $seeder = new class extends DemoSeeder
    {
        public function run(): void {}
    };

    inProduction(fn () => expect(fn () => $seeder())->toThrow(RuntimeException::class));
    expect(DemoSeeder::running())->toBeFalse();

    $broken = new class extends DemoSeeder
    {
        public function run(): void
        {
            throw new RuntimeException('boom');
        }
    };
    expect(fn () => $broken())->toThrow(RuntimeException::class)
        ->and(DemoSeeder::running())->toBeFalse();
});

it('P6 template text cannot add, duplicate or remove headers', function () {
    config(['mail.default' => 'array']);
    EmailTemplate::query()->where('key', 'client.welcome')->sole()->translations()->update(['subject' => "Hi\r\nAuto-Submitted: no\r\nBcc: x@example.test", 'body' => "Auto-Submitted: no\n\nHi"]);
    $client = Client::factory()->create(['email' => 'c@example.test']);

    app(NotificationMailer::class)->send(new EmailRequest('client.welcome', [app(RecipientResolver::class)->owner($client)], [], 'hdr|1', $client->id));

    $message = collect(app('mailer')->getSymfonyTransport()->messages()->all())->last()->getOriginalMessage();
    expect($message->getHeaders()->get('Auto-Submitted')->getBodyAsString())->toBe('auto-generated')
        ->and(iterator_to_array($message->getHeaders()->all('Auto-Submitted')))->toHaveCount(1)
        ->and($message->getBcc())->toBe([]);
});

it('does not mark a staff-written ticket reply as automatic', function () {
    expect(TemplatedMail::isAutomatic('ticket.reply'))->toBeFalse()
        ->and(TemplatedMail::isAutomatic('ticket.autoresponse'))->toBeTrue()
        ->and(TemplatedMail::isAutomatic(null))->toBeFalse();
});

// ── Review board ─────────────────────────────────────────────────────────────────────────────────────────────────

it('always sends invitations and security notices, even when someone disabled the template', function () {
    Notification::fake();
    EmailTemplate::query()->whereIn('key', EmailTemplate::ALWAYS_SENT)->update(['is_enabled' => false]);
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $client = Client::factory()->create();
    $client->users()->attach($owner, ['is_owner' => true]);

    app(ManageClientLogins::class)->invite($client, 'helper@example.test', 'Hari', ['billing']);

    expect(EmailMessage::query()->whereIn('template_key', ['auth.user_invitation', 'auth.login_added'])->pluck('status')->map->value->unique()->all())->not->toContain('suppressed')->not->toBeEmpty()
        ->and(collect(['staff.email_changed', 'staff.two_factor_reset', 'staff.settings_changed', 'auth.login_added', 'auth.access_granted', 'auth.owner_changed', 'auth.two_factor_reset', 'auth.user_invitation', 'staff.invitation'])->diff(EmailTemplate::ALWAYS_SENT)->all())->toBe([]);
});

it('does not let staff switch an always-sent template off', function () {
    $staff = User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create();
    $template = EmailTemplate::query()->where('key', 'auth.login_added')->sole();

    $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.notifications.templates.toggle', $template))->assertStatus(422);
    $this->actingAs($staff)->get(route('admin.notifications.templates.edit', $template))
        ->assertInertia(fn ($page) => $page->where('template.always_sent', true));

    expect($template->fresh()->is_enabled)->toBeTrue();
});

it('falls back only for the recipient the template system could not take', function () {
    Notification::fake();
    app()->instance(SystemMailer::class, new class implements SystemMailer
    {
        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return $recipient === 'old@example.test' ? SystemMailOutcome::Unavailable : SystemMailOutcome::Taken;
        }
    });

    app(AccountMail::class)->notice('staff.email_changed', ['old@example.test', 'new@example.test'], ['actor' => 'Sam'], 'staff:1', 'Changed', ['Changed.']);

    Notification::assertSentOnDemandTimes(SecurityNotice::class, 1);
    Notification::assertSentOnDemand(SecurityNotice::class, fn ($notification, $channels, $notifiable): bool => (array) $notifiable->routes['mail'] === ['old@example.test']);
});

it('sends a security notice once when the same change is handled twice', function () {
    $owner = User::factory()->create(['email' => 'owner@example.test']);

    app(AccountMail::class)->notice('auth.owner_changed', [$owner], ['account' => 'Acme', 'email' => 'x@example.test'], 'client:1|owner:2', 'Changed', ['Changed.']);
    app(AccountMail::class)->notice('auth.owner_changed', [$owner], ['account' => 'Acme', 'email' => 'x@example.test'], 'client:1|owner:2', 'Changed', ['Changed.']);

    expect(EmailMessage::query()->where('template_key', 'auth.owner_changed')->count())->toBe(1);
});

it('greets with a neutral word when the first name is missing', function () {
    expect(reviewCompose('client.welcome', ['recipient' => ['first_name' => '']])->text)->toContain('Hello,')->not->toContain('Hi ,');
});

it('shows status dates in the recipient\'s language', function () {
    app(Settings::class)->set('core.date_format', 'j M Y');

    $frame = app(MailFramer::class)->frame('invoice.created', ['invoice' => ['due_date' => '2026-12-01', 'balance' => '€5', 'total' => '€5']], 'fr');

    expect($frame->statusWhen)->toBe('1 déc. 2026');
});

// ── The suspension email's invoice, from the real Billing and Services data ──────────────────────────────────────

it('names the overdue invoice of a suspended service or of its add-on, and never an invoice not yet due', function () {
    Mail::fake();
    $client = Client::factory()->create(['email' => 'owner@example.test']);
    $service = Service::factory()->for($client)->create(['product_name' => 'Business Hosting']);
    $addon = ServiceAddon::factory()->for($service)->create();
    $bill = fn (BillableType $type, int $id, int $daysOverdue, string $number): Invoice => Invoice::factory()->for($client)
        ->has(InvoiceItem::factory()->state(['type' => $type, 'billable_id' => $id, 'amount' => 1500, 'unit_amount' => 1500]), 'items')
        ->create(['number' => $number, 'total' => 1500, 'subtotal' => 1500, 'balance' => 1500, 'due_date' => now()->subDays($daysOverdue)->toDateString()]);
    $bill(BillableType::Service, $service->id, -5, 'INV-NOT-DUE');
    $bill(BillableType::ServiceAddon, $addon->id, 9, 'INV-ADDON');
    $other = Client::factory()->create();
    Invoice::factory()->for($other)->has(InvoiceItem::factory()->state(['type' => BillableType::Service, 'billable_id' => $service->id]), 'items')->create(['number' => 'INV-OTHER', 'due_date' => now()->subDays(30)->toDateString()]);

    app(SendEventEmails::class)->handle(new ServiceSuspended($service->id, $client->id, 'overdue'));
    app(SendEventEmails::class)->handle(new ServiceSuspended($service->id, $client->id, 'addon_overdue'));

    $sent = [];
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$sent): bool {
        $sent[] = $mail->plainText;

        return true;
    });

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->not->toContain('INV-NOT-DUE', 'INV-OTHER', 'is overdue')->toContain('Reply to this email')
        ->and($sent[1])->toContain('Your invoice INV-ADDON for $15.00 is overdue', 'Pay $15.00: ')->not->toContain('INV-OTHER');
});

// ── Product review ───────────────────────────────────────────────────────────────────────────────────────────────

it('shows a Paid row between the total and the balance of a partly paid invoice', function () {
    $composed = reviewCompose('invoice.created', ['invoice' => [
        'number' => 'INV-7', 'total' => 'Rs 2,825.00', 'balance' => 'Rs 1,412.50', 'amount_paid' => 'Rs 1,412.50', 'amount_paid_minor' => 141250,
        'subtotal' => 'Rs 2,500.00', 'items' => [['description' => 'Hosting', 'amount' => 'Rs 2,500.00']], 'due_date' => now()->addDays(3)->toDateString(),
    ]]);

    expect($composed->text)->toContain("  Total: Rs 2,825.00\n  Paid: −Rs 1,412.50\n  Balance due: Rs 1,412.50");
});

it('states the amount of a refund and of a credit note', function () {
    $context = ['invoice' => ['number' => 'INV-7'], 'refund' => ['amount' => '$5.00'], 'credit_note' => ['amount' => '$4.00']];

    expect(reviewCompose('invoice.refunded', $context)->text)->toContain('Refunded: $5.00', "We've refunded $5.00 for invoice INV-7")
        ->and(reviewCompose('credit_note.issued', $context))->subject->toContain('$4.00')->text->toContain('Credit: $4.00');
});
