<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\WebhookEndpoint;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => seedEmailTemplates());

it('forbids staff without the matching permission', function (string $method, string $route) {
    actingAsStaff(['billing.*']);
    $template = EmailTemplate::query()->firstOrFail();
    $endpoint = WebhookEndpoint::factory()->create();

    $parameters = match ($route) {
        'admin.notifications.templates.edit', 'admin.notifications.templates.update' => [$template],
        'admin.notifications.webhooks.show', 'admin.notifications.webhooks.rotate' => [$endpoint],
        default => [],
    };

    $this->{$method}(route($route, $parameters))->assertForbidden();
})->with([
    ['get', 'admin.notifications.templates.index'],
    ['get', 'admin.notifications.templates.edit'],
    ['get', 'admin.notifications.emails.index'],
    ['get', 'admin.notifications.webhooks.index'],
    ['get', 'admin.notifications.webhooks.show'],
    ['get', 'admin.notifications.subscriptions.edit'],
]);

it('does not grant the sensitive template and webhook permissions through module wildcards', function () {
    $staff = actingAsStaff(['notifications.*']);

    expect($staff->hasPermission('notifications.templates.view'))->toBeTrue()
        ->and($staff->hasPermission('notifications.templates.manage'))->toBeFalse()
        ->and($staff->hasPermission('notifications.webhooks.manage'))->toBeFalse();
});

it('lists templates and opens the editor with merge fields', function () {
    actingAsStaff(['notifications.templates.view']);
    $template = EmailTemplate::query()->where('key', 'invoice.created')->sole();

    $this->get(route('admin.notifications.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/notifications/templates/index')->where('canManage', false)->has('templates', EmailTemplate::query()->count()));

    $this->get(route('admin.notifications.templates.edit', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/notifications/templates/edit')
            ->where('translation.subject', 'Invoice {{ invoice.number }} from {{ company.name }}{% if invoice.paid %}: paid{% else %}: {{ invoice.balance }} due {{ invoice.due_date | date }}{% endif %}')
            ->has('fields.invoice'));
});

it('lists a description and a sample-filled subject for each template', function () {
    actingAsStaff(['notifications.templates.view']);
    EmailTemplate::factory()->create(['key' => 'custom.promo', 'name' => 'Promo', 'is_system' => false]);

    $this->get(route('admin.notifications.templates.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['templates'])->keyBy('key');
            $system = $rows->except('custom.promo');

            expect($system->filter(fn ($row) => blank($row['description']))->keys()->all())->toBe([])
                ->and($system->filter(fn ($row) => str_contains((string) $row['sample_subject'], '{{') || str_contains((string) $row['sample_subject'], '{%'))->keys()->all())->toBe([])
                ->and($rows['invoice.created']['sample_subject'])->toContain('INV-2026-0101')
                ->and($rows['invoice.created']['subject'])->toContain('{{ invoice.number }}')
                ->and($rows['ticket.reply']['sample_subject'])->not->toContain('{{')
                ->and($rows['custom.promo']['description'])->toBeNull();
        });
});

it('previews unsaved text with sample data and reports syntax errors', function () {
    actingAsStaff(['notifications.templates.view']);
    $template = EmailTemplate::query()->where('key', 'invoice.created')->sole();

    $this->postJson(route('admin.notifications.templates.preview', $template), ['subject' => 'Invoice {{ invoice.number }}', 'body' => 'Due {{ invoice.due_date | date }}'])
        ->assertOk()->assertJson(['error' => null, 'warnings' => [], 'subject' => 'Invoice INV-2026-0101']);

    $this->postJson(route('admin.notifications.templates.preview', $template), ['subject' => 'x', 'body' => '{% if invoice.balance %}'])
        ->assertOk()->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'never closed'));
});

it('requires a recent password to change templates and blocks saving broken syntax (criterion 7)', function () {
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    $template = EmailTemplate::query()->where('key', 'invoice.created')->sole();
    $payload = ['name' => $template->name, 'locale' => 'en', 'subject' => 'Invoice', 'body' => '{% if invoice.balance %}Pay now', 'is_enabled' => true];

    $this->put(route('admin.notifications.templates.update', $template), $payload)->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->put(route('admin.notifications.templates.update', $template), $payload)->assertSessionHasErrors('body');

    $this->put(route('admin.notifications.templates.update', $template), [...$payload, 'body' => 'Pay {{ invoice.number }}'])->assertSessionHasNoErrors();
    expect($template->translations()->where('locale', 'en')->value('body'))->toBe('Pay {{ invoice.number }}');

    // Sender and copies need the separate, sensitive delivery permission.
    $this->put(route('admin.notifications.templates.update', $template), [...$payload, 'body' => 'Pay', 'bcc' => 'audit@example.test'])->assertSessionHasErrors('bcc');
    expect($template->fresh()->bcc)->toBeNull();
});

it('lets delivery managers set template copies and logs the change', function () {
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage', 'notifications.delivery.manage']);
    confirmPassword();
    $template = EmailTemplate::query()->where('key', 'invoice.created')->sole();

    $this->put(route('admin.notifications.templates.update', $template), ['name' => $template->name, 'locale' => 'en', 'subject' => 'Invoice', 'body' => 'Pay', 'bcc' => 'audit@example.test'])
        ->assertSessionHasNoErrors();

    expect($template->fresh()->bcc)->toBe(['audit@example.test'])
        ->and(ActivityLog::query()->where('description', 'Email template sender or copies changed')->exists())->toBeTrue();
});

it('cannot delete system templates', function () {
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->delete(route('admin.notifications.templates.destroy', EmailTemplate::query()->where('key', 'invoice.created')->sole()))->assertForbidden();
});

it('shows the email log filtered by client', function () {
    actingAsStaff(['notifications.log.view']);
    $client = Client::factory()->create();
    EmailMessage::factory()->create(['client_id' => $client->id]);
    EmailMessage::factory()->create();

    $this->get(route('admin.notifications.emails.index', ['client' => $client->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/notifications/emails/index')->has('messages.data', 1));
});

it('refuses to resend messages that carried one-time secrets', function () {
    actingAsStaff(['notifications.log.view', 'notifications.log.resend']);
    $message = EmailMessage::factory()->create(['has_redactions' => true]);

    $this->post(route('admin.notifications.emails.resend', $message))->assertStatus(422);
});

it('reveals a new webhook secret once and never in later pages', function () {
    actingAsStaff(['notifications.webhooks.manage']);
    confirmPassword();

    $response = $this->post(route('admin.notifications.webhooks.store'), ['url' => 'https://hooks.example.com/in', 'events' => ['invoice.paid'], 'is_active' => true]);

    $endpoint = WebhookEndpoint::query()->sole();
    $response->assertRedirect(route('admin.notifications.webhooks.show', $endpoint));
    expect($endpoint->getRawOriginal('secret'))->not->toBe($endpoint->secret)
        ->and($endpoint->secret)->toStartWith('whsec_');

    // The page right after creation is the one-time reveal (flash); later visits never contain it.
    $this->get(route('admin.notifications.webhooks.show', $endpoint))->assertOk()->assertSee($endpoint->secret);
    $this->get(route('admin.notifications.webhooks.show', $endpoint))->assertOk()->assertDontSee($endpoint->secret);
    $this->get(route('admin.notifications.webhooks.index'))->assertOk()->assertDontSee($endpoint->secret);
});

it('rejects webhook URLs pointing at private addresses', function () {
    actingAsStaff(['notifications.webhooks.manage']);
    confirmPassword();

    $this->post(route('admin.notifications.webhooks.store'), ['url' => 'https://169.254.169.254/latest', 'events' => ['*']])->assertSessionHasErrors('url');
    expect(WebhookEndpoint::query()->count())->toBe(0);
});
