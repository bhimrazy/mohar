<?php

use App\Events\Services\ServiceSuspended;
use App\Models\User;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Actions\SendClientEmail;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Modules\Notifications\Mail\MailPalette;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Staff\InviteStaff;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * Email direction A (owner decision 2026-10-18) and the product sweep's email findings: the two-card layout with
 * status, amount and line items; buttons that name the action and amount; written text parts; framework mail through
 * editable templates; human suspension reasons; automatic-mail headers; no mail from demo data.
 */

beforeEach(function () {
    seedEmailTemplates();
    $this->client = Client::factory()->create(['email' => 'priya@example.test', 'first_name' => 'Priya', 'currency' => 'INR', 'company_name' => 'Sharma Digital']);
    $this->invoice = [
        'number' => 'INV-1937', 'status' => 'unpaid', 'currency' => 'INR', 'total' => '₹588.82', 'balance' => '₹588.82',
        'subtotal' => '₹499.00', 'taxes' => [['label' => 'CGST 9%', 'amount' => '₹44.91'], ['label' => 'SGST 9%', 'amount' => '₹44.91']],
        'items' => [['description' => 'Starter Hosting', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => '₹499.00']],
        'issue_date' => '2026-10-18', 'due_date' => now()->addDays(14)->toDateString(),
        'url' => 'https://billing.test/client/invoices/7', 'pdf_url' => 'https://billing.test/client/invoices/7/pdf',
    ];
});

function compose(string $key, array $context): ComposedEmail
{
    return app(EmailComposer::class)->compose(EmailTemplate::query()->with('translations')->where('key', $key)->sole(), 'en', $context);
}

it('lays an invoice out as the two-card receipt: amount, status, pay button, line items and a written text part', function () {
    $composed = compose('invoice.created', ['client' => ['company_name' => 'Sharma Digital', 'currency' => 'INR'], 'recipient' => ['first_name' => 'Priya'], 'invoice' => $this->invoice]);
    $html = (string) $composed->html;

    expect($html)->toContain('max-width:'.MailPalette::WIDTH.'px', 'amount amt-a', '₹588.82', 'Due in 14 days', 'Hi Priya,')
        ->toMatch('#class="button"[^>]*>\s*Pay ₹588\.82\s*</a>#')
        ->toContain("What you're paying for", 'Starter Hosting', 'CGST 9%', 'Total due', 'Download invoice', 'https://billing.test/client/invoices/7/pdf')
        ->toContain('prefers-color-scheme: dark', '[data-ogsc]')
        ->and($composed->text)->toContain("Amount due: ₹588.82\n", 'Pay ₹588.82: https://billing.test/client/invoices/7', '- Starter Hosting', '  Total due: ₹588.82')
        ->not->toContain('|---', '](', '<', '**');
});

it('converts the HTML of a ticket reply into readable text', function () {
    $composed = compose('ticket.reply', [
        'ticket' => ['number' => '4821', 'subject' => 'Mail down', 'department' => 'Technical Support', 'priority' => 'High'],
        'reply' => ['author_name' => 'Sam', 'body_html' => '<p>Hi Ann,</p><ul><li>Update the <strong>MX</strong> record</li><li>Wait an hour</li></ul><p>See <a href="https://docs.example.test/mx">our guide</a>.</p>', 'excerpt' => ''],
    ]);

    expect($composed->text)->toContain("- Update the MX record\n- Wait an hour", 'See our guide (https://docs.example.test/mx).')
        ->not->toMatch('/<\/?[a-z]+[^>]*>/');
});

it('names the overdue invoice, its amount and a pay button in the suspension email, never a reason code', function () {
    Mail::fake();
    fakeMergeFields([
        'service' => ['product_name' => 'Business Hosting', 'domain' => 'sharma.test', 'suspension_reason' => 'overdue'],
        'service_overdue_invoice' => [...$this->invoice, 'client_id' => $this->client->id, 'number' => 'INV-1906', 'balance' => 'Rs 12,769.57', 'due_date' => now()->subDays(9)->toDateString()],
    ]);

    app(SendEventEmails::class)->handle(new ServiceSuspended(5, $this->client->id, 'overdue'));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'Your invoice INV-1906 for Rs 12,769.57 is overdue')
        && preg_match('#href="https://billing\.test/client/invoices/7" class="button"[^>]*>\s*Pay Rs 12,769\.57#', (string) $mail->htmlBody) === 1
        && ! str_contains($mail->subjectLine.$mail->htmlBody.$mail->plainText, 'overdue_'));
});

it('does not show a staff suspension note to the client', function () {
    Mail::fake();
    fakeMergeFields(['service' => ['product_name' => 'Business Hosting', 'domain' => 'sharma.test', 'suspension_reason' => 'staff:suspended by staff']]);

    app(SendEventEmails::class)->handle(new ServiceSuspended(5, $this->client->id, 'staff:suspended by staff'));

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => ! str_contains($mail->htmlBody.$mail->plainText, 'staff')
        && str_contains($mail->plainText, 'Reply to this email and we\'ll help you get it running again.'));
});

it('sends a new client login the invitation template instead of the password-reset email', function () {
    Mail::fake();
    Notification::fake();
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $this->client->users()->attach($owner, ['is_owner' => true]);

    $login = app(ManageClientLogins::class)->invite($this->client, 'helper@example.test', 'Hari Helper', ['billing']);

    $invitation = EmailMessage::query()->where('template_key', 'auth.user_invitation')->sole();
    expect($invitation->recipient)->toBe('helper@example.test')
        ->and($invitation->has_redactions)->toBeTrue()
        ->and(EmailMessage::query()->where('template_key', 'auth.password_reset')->exists())->toBeFalse()
        ->and(loggedRecipients('auth.login_added'))->toBe(['owner@example.test'])
        ->and(EmailMessage::query()->where('template_key', 'auth.access_granted')->exists())->toBeFalse();
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('helper@example.test')
        && substr_count((string) $mail->htmlBody, '/account-invitation/') === 1 && str_contains($mail->plainText, 'Accept invitation: '));
    Notification::assertNotSentTo($login, ResetPassword::class);
    Notification::assertNothingSentTo($owner);
});

it('tells an existing login it was given access, through the template', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'existing@example.test']);

    app(ManageClientLogins::class)->invite($this->client, 'existing@example.test', 'x', []);

    expect(loggedRecipients('auth.access_granted'))->toBe(['existing@example.test'])
        ->and(EmailMessage::query()->where('template_key', 'auth.user_invitation')->exists())->toBeFalse();
    Notification::assertNotSentTo($existing, SecurityNotice::class);
});

it('falls back to the notification when the template system cannot take the mail', function () {
    Notification::fake();
    EmailTemplate::query()->where('key', 'auth.access_granted')->delete();
    $existing = User::factory()->create(['email' => 'existing@example.test']);

    app(ManageClientLogins::class)->invite($this->client, 'existing@example.test', 'x', []);

    Notification::assertSentTo($existing, SecurityNotice::class);
});

it('sends the staff invitation template with the accept link', function () {
    Mail::fake();
    Notification::fake();
    $admin = User::factory()->staff(['*'])->create();
    $role = Role::factory()->withPermissions(['support.tickets.view'])->create();

    app(InviteStaff::class)->handle($admin, 'new.agent@example.test', 'New Agent', $role);

    expect(loggedRecipients('staff.invitation'))->toBe(['new.agent@example.test']);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => substr_count((string) $mail->htmlBody, '/staff-invitation/') === 1);
    Notification::assertNothingSent();
});

it('marks system mail as automatic and leaves hand-written mail alone', function () {
    config(['mail.default' => 'array']);
    app(NotificationMailer::class)->send(new EmailRequest('client.welcome', [app(RecipientResolver::class)->owner($this->client)], [], 'auto|1', $this->client->id));
    app(SendClientEmail::class)->handle($this->client, null, 'A note from us', 'Hello.');

    [$system, $manual] = array_values(array_map(fn ($sent) => $sent->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all()));

    expect($system->getHeaders()->get('Auto-Submitted')?->getBodyAsString())->toBe('auto-generated')
        ->and($system->getHeaders()->has('X-Auto-Response-Suppress'))->toBeTrue()
        ->and($manual->getHeaders()->has('Auto-Submitted'))->toBeFalse();
});

it('names the Reply-To address in the footer', function () {
    app(Settings::class)->setMany(['core.company_email' => 'billing@example.test', 'notifications.support_email' => 'help@example.test']);

    $ticket = compose('ticket.autoresponse', ['ticket' => ['number' => '1', 'subject' => 'Hi', 'department' => 'Sales', 'priority' => 'Low', 'department_email' => 'sales@example.test', 'url' => 'https://billing.test/t/1']]);
    $invoice = compose('invoice.created', ['invoice' => $this->invoice]);

    expect($ticket->text)->toContain('write to sales@example.test')->not->toContain('help@example.test')
        ->and($invoice->text)->toContain('write to billing@example.test');
});

it('gives reminders and updated invoices subjects of their own, with the due date', function () {
    $context = ['company' => ['name' => 'Acme'], 'invoice' => [...$this->invoice, 'due_date' => '2026-11-01']];
    app(Settings::class)->set('core.date_format', 'j M Y');

    expect(compose('invoice.created', $context)->subject)->toBe('Invoice INV-1937 from Acme: ₹588.82 due 1 Nov 2026')
        ->and(compose('invoice.payment_reminder', $context)->subject)->toBe('Reminder: invoice INV-1937 (₹588.82) is due 1 Nov 2026')
        ->and(compose('invoice.modified', $context)->subject)->toBe('Invoice INV-1937 from Acme was updated: ₹588.82 due 1 Nov 2026');
});

it('uses the template preheader and saves it from the editor', function () {
    $staff = User::factory()->staff(['notifications.templates.manage', 'notifications.templates.view'])->create();
    $template = EmailTemplate::query()->with('translations')->where('key', 'client.welcome')->sole();

    $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])->put(route('admin.notifications.templates.update', $template), [
        'name' => $template->name, 'locale' => 'en', 'subject' => 'Welcome', 'preheader' => 'Your account is ready, {{ client.first_name }}.', 'body' => "# Hi\n\nWelcome.",
    ])->assertSessionHasNoErrors();

    $html = (string) compose('client.welcome', ['client' => ['first_name' => 'Priya']])->html;
    expect($html)->toMatch('#class="preheader"[^>]*>Your account is ready, Priya\.#');
});

it('shows dates in the company time zone and the date setting', function () {
    app(Settings::class)->setMany(['system.timezone' => 'Asia/Kathmandu', 'core.date_format' => 'j M Y']);

    $text = app(EmailComposer::class)->composeSource('{{ at | date }}', 'x', ['at' => '2026-10-18T20:00:00Z'], 'en')->subject;

    expect($text)->toBe('19 Oct 2026');
});

it('records mail from demo seeding as suppressed instead of sending it', function () {
    Mail::fake();
    $seeder = new class extends DemoSeeder
    {
        public function run(): void
        {
            app(NotificationMailer::class)->send(new EmailRequest('client.welcome', [app(RecipientResolver::class)->addresses(['demo@example.test'])[0]], [], 'demo|1'));
        }
    };

    $seeder();

    Mail::assertNothingSent();
    expect(EmailMessage::query()->sole())->status->value->toBe('suppressed')->suppressed_reason->toBe('demo_data');
});

it('shows template names in the email log', function () {
    $staff = User::factory()->staff(['notifications.log.view'])->create();
    EmailMessage::factory()->create(['template_key' => 'invoice.created']);

    $this->actingAs($staff)->get(route('admin.notifications.emails.index'))
        ->assertInertia(fn ($page) => $page->where('messages.data.0.template_name', 'Invoice created')
            ->where('templates.0.label', 'Invoice created'));
});

it('keeps a theme accent only when its email colour pairs pass AA', function () {
    expect(MailPalette::forAccent('#0F766E', '#FFFFFF')->light['accent'])->toBe('#0F766E')
        ->and(MailPalette::forAccent('#FDE047', '#FFFFFF')->light['accent'])->toBe('#4F4BC4');
});

it('renders every template with the sample data in direction A without warnings', function () {
    $samples = app(SampleContext::class);

    foreach (EmailTemplate::query()->with('translations')->get() as $template) {
        $composed = app(EmailComposer::class)->compose($template, 'en', $samples->build($template->audience->value === 'staff'));

        expect($composed->warnings)->toBe([], $template->key)
            ->and($composed->text)->not->toMatch('/<\/?[a-z]+[^>]*>|\]\(|\|---/', $template->key);
    }
});
