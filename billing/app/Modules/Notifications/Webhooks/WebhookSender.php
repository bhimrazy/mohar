<?php

namespace App\Modules\Notifications\Webhooks;

use App\Contracts\Branding\BrandProvider;
use App\Data\Integrations\ResolvedAddress;
use App\Events\Notifications\WebhookDelivered;
use App\Events\Notifications\WebhookDeliveryFailed;
use App\Events\Notifications\WebhookEndpointDisabled;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\Redactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performs one delivery attempt (spec §8): SSRF-vetted, signed POST pinned to the vetted address without any proxy,
 * 5 s connect / 10 s total timeout, redirects not followed, 2xx = success. Failures schedule the next attempt with backoff until
 * max_attempts (then abandoned); too many consecutive failures disable the endpoint.
 */
class WebhookSender
{
    public function __construct(
        private WebhookUrlGuard $guard,
        private OutboundAddressGuard $outbound,
        private Settings $settings,
    ) {}

    public function attempt(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint->is_active && $delivery->event_type !== WebhookEventTypes::PING) {
            $delivery->update(['status' => WebhookDeliveryStatus::Abandoned, 'error' => 'Endpoint is disabled.', 'next_attempt_at' => null]);

            return;
        }

        $delivery->attempt++;
        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $started = hrtime(true);

        try {
            $target = $this->guard->vet($endpoint->url);
            $response = $this->post($endpoint, $target, $body, $timestamp, $delivery);
        } catch (UnsafeWebhookTarget $refused) {
            $this->failed($delivery, null, null, $refused->getMessage(), $started);

            return;
        } catch (Throwable $exception) {
            $this->failed($delivery, null, null, Str::limit($exception->getMessage(), 250), $started);

            return;
        }

        if ($response->successful()) {
            $this->succeeded($delivery, $response, $started);

            return;
        }

        $this->failed($delivery, $response->status(), $response->body(), $response->redirect() ? 'Redirects are not followed.' : null, $started);
    }

    /**
     * Connects to the vetted address only, never through a proxy, and follows no redirect (OutboundAddressGuard::pin()).
     */
    private function post(WebhookEndpoint $endpoint, ResolvedAddress $target, string $body, int $timestamp, WebhookDelivery $delivery): Response
    {
        $request = Http::withHeaders([
            'User-Agent' => (Str::slug(app(BrandProvider::class)->brand()->name, '') ?: 'Billing').'-Webhooks/1',
            'X-Webhook-Id' => $delivery->event_id,
            'X-Webhook-Event' => $delivery->event_type,
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => WebhookSigner::header($endpoint->signingSecrets(), $timestamp, $body),
        ])
            ->withBody($body, 'application/json')
            ->connectTimeout((int) config('notifications.webhooks_connect_timeout', 5))
            ->timeout((int) config('notifications.webhooks_timeout', 10));

        return $this->outbound->pin($request, $target)->post($endpoint->url);
    }

    private function succeeded(WebhookDelivery $delivery, Response $response, int|float $started): void
    {
        $delivery->fill([
            'status' => WebhookDeliveryStatus::Succeeded,
            'response_status' => $response->status(),
            'response_body' => $this->truncate($this->redactor()->maskValues($response->body(), [])),
            'error' => null,
            'duration_ms' => $this->elapsed($started),
            'next_attempt_at' => null,
            'delivered_at' => now(),
        ])->save();

        $delivery->endpoint->update(['consecutive_failures' => 0, 'last_success_at' => now()]);
        WebhookDelivered::dispatch($delivery->id, $delivery->webhook_endpoint_id, $delivery->event_type);
    }

    private function failed(WebhookDelivery $delivery, ?int $status, ?string $body, ?string $error, int|float $started): void
    {
        $maxAttempts = max(1, (int) $this->settings->get('notifications.webhooks_max_attempts', 8));
        $backoff = array_values((array) config('notifications.webhooks_backoff_seconds', [60]));
        $abandoned = $delivery->attempt >= $maxAttempts || $delivery->event_type === WebhookEventTypes::PING;
        $delay = (int) ($backoff[min($delivery->attempt - 1, count($backoff) - 1)] ?? 60);

        $delivery->fill([
            'status' => $abandoned ? WebhookDeliveryStatus::Abandoned : WebhookDeliveryStatus::Failed,
            'response_status' => $status,
            'response_body' => $body === null ? null : $this->truncate($this->redactor()->maskValues($body, [])),
            'error' => $error === null ? null : Str::limit($this->redactor()->maskValues($error, []), 250),
            'duration_ms' => $this->elapsed($started),
            'next_attempt_at' => $abandoned ? null : now()->addSeconds($delay),
        ])->save();

        WebhookDeliveryFailed::dispatch($delivery->id, $delivery->webhook_endpoint_id, $delivery->event_type, $delivery->attempt, $abandoned);

        if ($delivery->event_type !== WebhookEventTypes::PING) {
            $this->countFailure($delivery->endpoint);
        }
    }

    private function countFailure(WebhookEndpoint $endpoint): void
    {
        $endpoint->increment('consecutive_failures');
        $threshold = max(1, (int) $this->settings->get('notifications.webhooks_disable_after_consecutive_failures', 20));

        if ($endpoint->is_active && $endpoint->consecutive_failures >= $threshold) {
            $endpoint->update(['is_active' => false, 'disabled_reason' => "Disabled after {$endpoint->consecutive_failures} consecutive failed deliveries."]);
            WebhookEndpointDisabled::dispatch($endpoint->id, $endpoint->url, $endpoint->consecutive_failures);
        }
    }

    /** Receiver answers and transport errors (which quote the endpoint URL) are stored masked. */
    private function redactor(): Redactor
    {
        return app(Redactor::class);
    }

    private function truncate(string $body): string
    {
        return mb_strcut($body, 0, 2048);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
