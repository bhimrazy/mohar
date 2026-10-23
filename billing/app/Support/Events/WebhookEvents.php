<?php

namespace App\Support\Events;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The domain events exposed as outbound webhooks (ADR 0011). The module that owns an event registers it in
 * bootModule(), and a package does the same in boot():
 *
 *   app(WebhookEvents::class)->register(InvoicePaid::class, 'invoice.paid', 'invoice', 'invoiceId');
 *
 * $entity names the merge-field resolver that builds the payload object and $idProperty the event property holding
 * the entity id; without them the payload is the event's scalar properties. Registration stores plain data and
 * resolves nothing. The type is the key subscribers see, so it is a public name: `<area>.<action>`, lowercase.
 *
 * @api
 */
final class WebhookEvents
{
    /** Sent by the endpoint "send test" action; no event maps to it. */
    public const PING = 'webhook.ping';

    private const TYPE_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    /** @var array<string, array{event: class-string, entity: string|null, idProperty: string|null}> */
    private array $types = [];

    /**
     * @param  class-string  $event
     */
    public function register(string $event, string $type, ?string $entity = null, ?string $idProperty = null, bool $replace = false): void
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1 || strlen($type) > 50 || $type === self::PING) {
            throw new InvalidArgumentException("Invalid webhook type [{$type}]: use 1 to 50 lowercase letters, digits, underscores and dots, as <area>.<action>.");
        }

        $eventHolder = $this->typeOf($event);
        $conflict = isset($this->types[$type]) ? $type : $eventHolder;

        if ($conflict !== null) {
            if (! $replace) {
                throw new LogicException("Webhook type [{$type}] or event [{$event}] is already registered as [{$conflict}]; pass replace: true to override it.");
            }

            Log::info('Webhook event replaced.', ['type' => $type, 'event' => $event]);
            unset($this->types[$type]);

            if ($eventHolder !== null) {
                unset($this->types[$eventHolder]);
            }
        }

        $this->types[$type] = ['event' => $event, 'entity' => $entity, 'idProperty' => $idProperty];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @param  class-string  $event
     * @return array{type: string, entity: string|null, idProperty: string|null}|null
     */
    public function forEvent(string $event): ?array
    {
        $type = $this->typeOf($event);

        return $type === null ? null : ['type' => $type, 'entity' => $this->types[$type]['entity'], 'idProperty' => $this->types[$type]['idProperty']];
    }

    /**
     * Registered event classes, for listeners.
     *
     * @return list<class-string>
     */
    public function events(): array
    {
        return array_column($this->types, 'event');
    }

    /**
     * What an endpoint can subscribe to, plus the test event.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return [...array_keys($this->types), self::PING];
    }

    /**
     * Registered types in registration order, without the test event.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    private function typeOf(string $event): ?string
    {
        foreach ($this->types as $type => $entry) {
            if ($entry['event'] === $event) {
                return $type;
            }
        }

        return null;
    }
}
