<?php

use App\Contracts\Auth\AuthMailer;
use App\Events\Billing\InvoiceCreated;
use App\Events\Domains\DomainExpiryDigest;
use App\Events\Services\ProvisioningFailed;
use App\Events\Support\TicketReplied;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\ClientEmailPreference;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Mail::fake();
    seedEmailTemplates();
    fakeMergeFields([
        'invoice' => ['number' => 'INV-1', 'status' => 'Unpaid', 'total' => '$10.00', 'balance' => '$10.00', 'due_date' => '2026-11-01', 'url' => 'https://billing.test/i/1'],
    ]);
    $this->client = Client::factory()->create(['email' => 'owner@example.test', 'first_name' => 'Olive']);
    $this->listener = app(SendEventEmails::class);
});

function optOutOwner(Client $client, array $categories): void
{
    ClientEmailPreference::query()->create(['client_id' => $client->id, 'scope_key' => ClientEmailPreference::OWNER, 'categories' => $categories]);
}

it('subscribes to the domain events it maps', function () {
    Event::fake();

    Event::assertListening(InvoiceCreated::class, [SendEventEmails::class, 'handle']);
    Event::assertListening(TicketReplied::class, [SendEventEmails::class, 'handle']);
});

it('sends invoice mail only to recipients who opted in (criterion 1)', function () {
    optOutOwner($this->client, ['invoice' => false]);
    contactFor($this->client, 'accounts@example.test', ['invoice' => true]);
    contactFor($this->client, 'tech@example.test', ['invoice' => false]);

    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    expect(mailedTo())->toBe(['accounts@example.test'])
        ->and(loggedRecipients('invoice.created'))->toBe(['accounts@example.test']);
});

it('falls back to the owner when nobody wants invoice mail (criterion 2)', function () {
    optOutOwner($this->client, ['invoice' => false]);
    contactFor($this->client, 'accounts@example.test', ['invoice' => false]);

    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    expect(mailedTo())->toBe(['owner@example.test']);
});

it('respects the notify flag on InvoiceCreated', function () {
    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id, notify: false));

    Mail::assertNothingSent();
});

it('always sends password resets, whatever the preferences (criterion 3)', function () {
    optOutOwner($this->client, array_fill_keys(['general', 'invoice', 'product', 'domain', 'support', 'affiliate'], false));
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $this->client->users()->attach($user, ['is_owner' => true]);

    app(AuthMailer::class)->sendPasswordReset($user, 'secret-token');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('owner@example.test') && str_contains((string) $mail->htmlBody, 'secret-token'));
    $logged = EmailMessage::query()->where('template_key', 'auth.password_reset')->sole();
    expect($logged->body_html)->not->toContain('secret-token')
        ->and($logged->body_text)->not->toContain('secret-token')->toContain('••••')
        ->and($logged->has_redactions)->toBeTrue();
});

it('uses the staff template for staff password resets', function () {
    $staff = fullAdministrator('sam@example.test');

    app(AuthMailer::class)->sendPasswordReset($staff, 'staff-token');

    expect(loggedRecipients('staff.password_reset'))->toBe(['sam@example.test'])
        ->and(loggedRecipients('auth.password_reset'))->toBe([]);
});

it('creates exactly one log row per recipient when an event is replayed (criterion 4)', function () {
    contactFor($this->client, 'accounts@example.test', ['invoice' => true]);
    $event = new InvoiceCreated(10, $this->client->id);

    $this->listener->invoiceCreated($event);
    $this->listener->invoiceCreated($event);

    expect(loggedRecipients('invoice.created'))->toBe(['accounts@example.test', 'owner@example.test']);
    Mail::assertSentCount(2);
});

it('dedupes the same occurrence even after serialisation', function () {
    $event = new InvoiceCreated(10, $this->client->id);

    $this->listener->invoiceCreated($event);
    $this->listener->invoiceCreated(unserialize(serialize($event)));

    expect(loggedRecipients('invoice.created'))->toBe(['owner@example.test']);
});

it('sends twice for two separate events with identical payloads', function () {
    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));
    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    expect(loggedRecipients('invoice.created'))->toBe(['owner@example.test', 'owner@example.test']);
    Mail::assertSentCount(2);
});

it('logs messages as suppressed without calling the transport when mail is disabled (criterion 5)', function () {
    app(Settings::class)->set('notifications.mail_disabled', true);

    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    Mail::assertNothingSent();
    expect(EmailMessage::query()->sole())
        ->status->toBe(EmailStatus::Suppressed)
        ->suppressed_reason->toBe('mail_disabled');
});

it('sends nothing but auth mail to closed accounts', function () {
    $this->client->update(['status' => 'closed']);

    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    Mail::assertNothingSent();
});

it('sends a staff ticket reply to the requester, both CCs and the owner once each (criterion 11)', function () {
    fakeMergeFields([
        'ticket' => [
            'number' => '482913', 'subject' => 'Mail down', 'department' => 'Support', 'priority' => 'High', 'status' => 'Answered',
            'requester_name' => 'Ada Contact', 'requester_email' => 'ada@example.test', 'cc' => ['cc1@example.test', 'CC2@example.test', 'ada@example.test'],
            'url' => 'https://billing.test/t/482913', 'admin_url' => 'https://billing.test/admin/t/1',
        ],
        'ticket_reply' => ['author_name' => 'Sam', 'body_html' => '<p>Fixed.</p>', 'is_note' => false],
    ]);

    $this->listener->ticketReplied(new TicketReplied(5, 9, $this->client->id, byStaff: true));

    expect(mailedTo('[#482913] Mail down'))->toBe(['ada@example.test', 'cc1@example.test', 'cc2@example.test', 'owner@example.test']);
    Mail::assertSentCount(4);
});

it('sends expired domains the expired notice, not the renewal reminder (criterion 12)', function () {
    $this->listener->domainExpiryDigest(new DomainExpiryDigest($this->client->id, [
        ['domain_id' => 3, 'domain' => 'example.com', 'expiry_date' => '2026-10-15', 'days_left' => -3],
    ], '2026-10-18'));

    expect(loggedRecipients('domain.expired_notice'))->toBe(['owner@example.test'])
        ->and(loggedRecipients('domain.renewal_upcoming'))->toBe([]);
});

it('notifies subscribed staff and marks the message failed when a template does not render', function () {
    fullAdministrator('admin@example.test');
    contactFor($this->client, 'accounts@example.test', ['invoice' => true]);
    EmailTemplate::query()->where('key', 'invoice.created')->sole()
        ->translations()->update(['body' => '{% if invoice.balance %}Pay now']);

    $this->listener->invoiceCreated(new InvoiceCreated(10, $this->client->id));

    expect(EmailMessage::query()->where('template_key', 'invoice.created')->pluck('status')->unique()->all())->toBe([EmailStatus::Failed])
        ->and(loggedRecipients('staff.template_render_failed'))->toBe(['admin@example.test']);
    Mail::assertSent(TemplatedMail::class, 1);
});

it('tells staff of a failure, or of an unknown outcome that is being checked (#147)', function (bool $unknown, string $sent, string $notSent) {
    fullAdministrator('ops@example.test');

    $this->listener->provisioningFailed(new ProvisioningFailed(7, $this->client->id, 'create', 'cURL error 28: timed out', outcomeUnknown: $unknown));

    expect(loggedRecipients($sent))->toBe(['ops@example.test'])
        ->and(loggedRecipients($notSent))->toBe([])
        ->and(mailedTo($unknown ? 'Checking the outcome' : 'Provisioning failed'))->toBe(['ops@example.test']);
})->with([
    'failed' => [false, 'staff.provisioning_failed', 'staff.provisioning_unknown'],
    'outcome unknown' => [true, 'staff.provisioning_unknown', 'staff.provisioning_failed'],
]);

it('logs the key when a template is missing instead of dropping the mail silently', function () {
    fullAdministrator('ops@example.test');
    EmailTemplate::query()->where('key', 'staff.provisioning_unknown')->delete();
    Log::spy();

    $this->listener->provisioningFailed(new ProvisioningFailed(7, $this->client->id, 'create', 'timed out', outcomeUnknown: true));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Email not sent: no template with this key.', ['template_key' => 'staff.provisioning_unknown'])->once();
});
