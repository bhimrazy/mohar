<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Mail\Transport\ArrayTransport;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/helpers.php';

/*
 * Owner decisions 2026-10-18: every email comes from the company and can be answered. Reply-To is the billing address for billing mail,
 * the ticket department's inbound address for tickets, the support address otherwise — never a no-reply
 * mailbox, and omitted when nothing is configured. The footer names the support address.
 */

beforeEach(function () {
    config(['mail.default' => 'array']);
    seedEmailTemplates();
    $this->client = Client::factory()->create(['email' => 'owner@example.test']);
    $this->settings = app(Settings::class);
    $this->settings->set('core.company_email', 'billing@example.test');
    $this->settings->set('notifications.support_email', 'help@example.test');
});

/**
 * @return list<Email>
 */
function deliveredEmails(): array
{
    $transport = app('mailer')->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return array_values(array_map(fn ($sent) => $sent->getOriginalMessage(), $transport->messages()->all()));
}

function replyToOf(Email $email): ?string
{
    return ($email->getReplyTo()[0] ?? null)?->getAddress();
}

function sendTemplate(string $key, array $context = []): EmailMessage
{
    $client = test()->client;
    $messages = app(NotificationMailer::class)->send(new EmailRequest($key, [app(RecipientResolver::class)->owner($client)], $context, 'reply-to|'.$key.'|'.uniqid(), $client->id));

    return $messages[0]->fresh();
}

it('uses the billing address for invoice mail, the department for tickets and support for the rest', function () {
    fakeMergeFields(['invoice' => ['number' => 'INV-1']]);

    expect(sendTemplate('invoice.created')->reply_to)->toBe('billing@example.test')
        ->and(sendTemplate('ticket.reply', ['ticket' => ['number' => '1', 'subject' => 'x', 'department_email' => 'Tech@Example.test']])->reply_to)->toBe('tech@example.test')
        ->and(sendTemplate('client.welcome')->reply_to)->toBe('help@example.test')
        ->and(array_map(replyToOf(...), deliveredEmails()))->toBe(['billing@example.test', 'tech@example.test', 'help@example.test']);
});

it('falls back to the billing address, skips no-reply mailboxes and omits Reply-To when nothing is usable', function () {
    $this->settings->set('notifications.support_email', '');
    expect(sendTemplate('client.welcome')->reply_to)->toBe('billing@example.test');

    $this->settings->set('notifications.support_email', 'noreply@example.test');
    $this->settings->set('core.company_email', 'no-reply@example.test');
    expect(sendTemplate('client.welcome')->reply_to)->toBeNull()
        ->and(sendTemplate('ticket.reply', ['ticket' => ['number' => '1', 'subject' => 'x']])->reply_to)->toBeNull()
        ->and(replyToOf(deliveredEmails()[2]))->toBeNull();
});

it('adds the support Reply-To and security line to framework and module notifications', function () {
    $user = User::factory()->create();

    $user->notify(new SecurityNotice('Your two-factor authentication was reset', ['An administrator reset it.']));

    $email = deliveredEmails()[0];
    expect(replyToOf($email))->toBe('help@example.test')
        ->and($email->getHtmlBody())->toContain("If this wasn't you, contact help@example.test.")
        ->and($email->getTextBody())->toContain("If this wasn't you, contact help@example.test.");
});

it('names the support address in the footer of the HTML and text parts', function () {
    $this->settings->set('notifications.footer_help_text', 'Stuck? Write to {{ support.email }} any time.');

    sendTemplate('client.welcome');
    $email = deliveredEmails()[0];

    expect($email->getHtmlBody())->toContain('Stuck? Write to help@example.test any time.')
        ->and($email->getTextBody())->toContain('Stuck? Write to help@example.test any time.');
});

it('adds "if this wasn\'t you" with the support address to templated security mail', function () {
    sendTemplate('auth.password_reset', ['user' => ['email' => 'owner@example.test'], 'auth' => ['reset_url' => url('/reset-password/abc'), 'expires_at' => 'soon']]);

    expect(deliveredEmails()[0]->getTextBody())->toContain("If this wasn't you, contact help@example.test.");
});

it('sends from the company name, with the billing sender for billing mail and fallbacks', function () {
    $this->settings->set('core.company_name', 'Acme Hosting');
    config(['mail.from.address' => 'mailer@acme.test', 'mail.from.name' => 'Laravel']);
    fakeMergeFields(['invoice' => ['number' => 'INV-1']]);

    $plain = sendTemplate('client.welcome');
    $billingWithoutAlias = sendTemplate('invoice.created');
    expect([$plain->from, $plain->from_name])->toBe(['mailer@acme.test', 'Acme Hosting'])
        ->and($billingWithoutAlias->from)->toBe('mailer@acme.test');

    $this->settings->set('notifications.from.default', 'Hello@Acme.test');
    $this->settings->set('notifications.from.billing', 'billing-desk@acme.test');

    expect(sendTemplate('invoice.created')->from)->toBe('billing-desk@acme.test')
        ->and(sendTemplate('client.welcome')->from)->toBe('hello@acme.test');

    $senders = array_map(fn (Email $email): array => [$email->getFrom()[0]->getAddress(), $email->getFrom()[0]->getName()], deliveredEmails());
    expect($senders)->toBe([
        ['mailer@acme.test', 'Acme Hosting'],
        ['mailer@acme.test', 'Acme Hosting'],
        ['billing-desk@acme.test', 'Acme Hosting'],
        ['hello@acme.test', 'Acme Hosting'],
    ]);
});

it('rebrands the default sender of framework and module notifications', function () {
    $this->settings->set('core.company_name', 'Acme Hosting');
    config(['mail.from.address' => 'mailer@acme.test', 'mail.from.name' => 'Laravel']);

    User::factory()->create()->notify(new SecurityNotice('Your two-factor authentication was reset', ['An administrator reset it.']));

    $from = deliveredEmails()[0]->getFrom()[0];
    expect([$from->getAddress(), $from->getName()])->toBe(['mailer@acme.test', 'Acme Hosting']);
});

it('offers client templates to the catalog product form', function () {
    actingAsStaff(['catalog.products.view', 'catalog.products.manage']);

    $this->get(route('admin.catalog.products.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('enums.emailTemplates', fn ($options): bool => collect($options)->contains(fn (array $option): bool => $option['value'] === 'service.welcome.shared_hosting' && $option['label'] === 'Welcome: shared hosting')
                && collect($options)->doesntContain(fn (array $option): bool => str_starts_with($option['value'], 'staff.'))));
});
