<?php

namespace Mohar\Simulators\Kernel;

use Closure;
use Mohar\Simulators\Contracts\SignsCallbacks;

/**
 * Server-to-app deliveries (webhooks, inbound mail). Simulators queue them; the harness pulls them and posts them to the
 * app with their exact bytes and headers, as given (`deliver()`), replayed, out of order, late, with a stale
 * timestamp or signed with the wrong secret.
 */
final class CallbackDispatcher
{
    public const STALE_SECONDS = 3600;

    public function __construct(private readonly StateStore $state, private readonly Scenario $scenario) {}

    /**
     * @param  array<string, string>  $headers  unsigned headers; a simulator that signs adds its signature at delivery
     */
    public function enqueue(string $url, string $body, array $headers = [], int $delaySeconds = 0): int
    {
        $this->state->pdo()->prepare('INSERT INTO outbox (delivery, deliver_after) VALUES (?, ?)')
            ->execute([json_encode(['url' => $url, 'body' => $body, 'headers' => $headers]), time() + $delaySeconds]);

        return (int) $this->state->pdo()->lastInsertId();
    }

    /**
     * Deliveries not yet sent and due, oldest first (or newest first, to deliver out of order).
     *
     * @return list<array{id: int, url: string, body: string, headers: array<string, string>}>
     */
    public function pending(bool $newestFirst = false): array
    {
        $statement = $this->state->pdo()->prepare('SELECT id, delivery FROM outbox WHERE delivered = 0 AND deliver_after <= ? ORDER BY id '.($newestFirst ? 'DESC' : 'ASC'));
        $statement->execute([time()]);

        $deliveries = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $delivery = json_decode((string) $row['delivery'], true);
            $headers = [];

            foreach ((array) ($delivery['headers'] ?? []) as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }

            $deliveries[] = ['id' => (int) $row['id'], 'url' => (string) ($delivery['url'] ?? ''), 'body' => (string) ($delivery['body'] ?? ''), 'headers' => $headers];
        }

        return $deliveries;
    }

    /**
     * Send one delivery through $transport (url, body, headers => HTTP status). Variants: `stale` signs with a timestamp
     * an hour old; `wrong_secret` signs with a secret the app does not know. Delivering again replays it.
     *
     * @param  array{id: int, url: string, body: string, headers: array<string, string>}  $delivery
     * @param  Closure(string, string, array<string, string>): int  $transport
     */
    public function deliver(array $delivery, Closure $transport, ?string $variant = null): int
    {
        $simulator = $this->scenario->simulator();
        $headers = $delivery['headers'];

        if ($simulator instanceof SignsCallbacks) {
            $timestamp = $variant === 'stale' ? time() - self::STALE_SECONDS : time();
            $headers = [...$headers, ...$simulator->sign($this->scenario, $delivery['body'], $timestamp, $variant === 'wrong_secret')];
        }

        $status = $transport($delivery['url'], $delivery['body'], $headers);
        $this->state->pdo()->prepare('UPDATE outbox SET delivered = delivered + 1 WHERE id = ?')->execute([$delivery['id']]);

        return $status;
    }
}
