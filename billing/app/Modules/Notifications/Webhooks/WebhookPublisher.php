<?php

namespace App\Modules\Notifications\Webhooks;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Services\MergeContext;
use App\Modules\Notifications\Support\EventKey;
use Carbon\CarbonImmutable;

/**
 * Turns a domain event into one delivery per subscribed endpoint (spec §8). The event id is derived
 * from the event, so a replayed event hits the unique (event_id, endpoint) index instead of re-sending.
 */
class WebhookPublisher
{
    /** Keys never sent in a payload, at any depth. */
    private const SECRET_KEY_PATTERN = '/(password|passwd|secret|token|epp|auth_?code|access_key|api_key|card|cvv|iban)/i';

    public function __construct(private MergeContext $context) {}

    public function publishEvent(object $event): int
    {
        [$type, $entity, $idProperty] = WebhookEventTypes::MAP[$event::class] ?? [null, null, null];

        if ($type === null) {
            return 0;
        }

        $properties = get_object_vars($event);
        $ids = array_filter($properties, fn (mixed $value, string $key): bool => is_int($value) && str_ends_with($key, 'Id'), ARRAY_FILTER_USE_BOTH);
        $id = $idProperty !== null && is_int($properties[$idProperty] ?? null) ? $properties[$idProperty] : null;
        $object = $entity !== null ? $this->context->resolve($entity, $id) : [];

        if ($object === []) {
            $object = array_filter($properties, fn (mixed $value, string $key): bool => is_scalar($value) && ! array_key_exists($key, $ids) && $key !== 'occurrenceId', ARRAY_FILTER_USE_BOTH);
        }

        return $this->publish($type, $object, $this->snakeKeys($ids), EventKey::of($event));
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, int>  $ids
     * @param  list<int>|null  $endpointIds  limit to these endpoints (ping)
     */
    public function publish(string $type, array $object, array $ids, string $eventKey, ?array $endpointIds = null): int
    {
        $endpoints = WebhookEndpoint::query()->where('is_active', true)
            ->when($endpointIds !== null, fn ($query) => $query->whereIn('id', $endpointIds))
            ->orderBy('id')->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $type === WebhookEventTypes::PING || $endpoint->subscribesTo($type));

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $eventId = EventKey::uuid($eventKey);
        $envelope = [
            'id' => $eventId,
            'type' => $type,
            'created_at' => now()->utc()->toIso8601ZuluString(),
            'api_version' => (string) config('notifications.webhooks_api_version', '2026-10'),
            'data' => ['object' => $this->sanitize($object), 'ids' => $ids],
        ];
        $created = 0;

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                ['event_id' => $eventId, 'webhook_endpoint_id' => $endpoint->id],
                // The lease: if the job below is lost, notifications:retry-webhooks takes the row again after it.
                ['event_type' => $type, 'payload' => $envelope, 'attempt' => 0, 'status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => self::lease()],
            );

            if ($delivery->wasRecentlyCreated) {
                DeliverWebhook::dispatch($delivery->id)->afterCommit();
                $created++;
            }
        }

        return $created;
    }

    public static function lease(): CarbonImmutable
    {
        return now()->toImmutable()->addSeconds(max(30, (int) config('notifications.webhooks_dispatch_lease_seconds', 300)));
    }

    /**
     * Drops secret-looking keys and URLs that carry access keys or tokens (spec §8: no secrets ever).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($value instanceof SensitiveValue || (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key))) {
                continue;
            }

            if (is_string($value) && preg_match('/[?&](key|token|signature|expires)=/i', $value)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, int>  $ids
     * @return array<string, int>
     */
    private function snakeKeys(array $ids): array
    {
        $snake = [];

        foreach ($ids as $key => $value) {
            $snake[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key))] = $value;
        }

        return $snake;
    }
}
