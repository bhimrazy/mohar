<?php

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
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

it('2: fails closed when the link is not on a configured origin: no template mail, no fallback', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    ResetPassword::createUrlUsing(fn ($user, string $token): string => 'https://portal.example.test/reset/'.$token);
    $user = User::factory()->create();

    try {
        $this->post(route('password.email'), ['email' => $user->email]);
    } finally {
        ResetPassword::$createUrlCallback = null;
    }

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect(ActivityLog::query()->where('description', 'Account email blocked: link host not allowed')->sole()->properties)
        ->toMatchArray(['kind' => 'password reset', 'host' => 'portal.example.test']);
});

it('2: a repeated request for the same token neither sends again nor triggers the fallback', function () {
    Mail::fake();
    Notification::fake();
    seedEmailTemplates();
    $user = User::factory()->create();
    app(AuthMailer::class)->sendPasswordReset($user, 'same-token');
    app(AuthMailer::class)->sendPasswordReset($user, 'same-token');

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

it('sends templated resets to a custom domain once notifications.auth_link_host allows it', function () {
    Mail::fake();
    seedEmailTemplates();
    app(Settings::class)->set('notifications.auth_link_host', 'portal.example.test');
    ResetPassword::createUrlUsing(fn ($user, string $token): string => 'https://portal.example.test/reset/'.$token);
    $user = User::factory()->create();

    try {
        app(AuthMailer::class)->sendPasswordReset($user, 'PORTAL-TOKEN');
    } finally {
        ResetPassword::$createUrlCallback = null;
    }

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => str_contains((string) $mail->htmlBody, 'href="https://portal.example.test/reset/PORTAL-TOKEN"'));
});
