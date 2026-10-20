<?php

namespace Mohar\Simulators\Kernel;

use Closure;
use Mohar\Simulators\Contracts\SignsCallbacks;

/**
 * Server-to-app deliveries (webhooks, inbound mail). Simulators queue them; the harness pulls them and posts them to the
 * app with their exact bytes and headers, as given (`deliver()`), replayed, out of order, late, with a stale
 * timestamp, signed with the wrong secret, or unsigned.
 *
 * As a provider does (Stripe retries any non-2xx answer for up to three days), only a 2xx answer completes a delivery;
 * any other answer schedules a retry on the backoff schedule, until the maximum number of attempts. The schedule is
 * compressed to seconds so tests can wait for it (`retryPolicy()`, `drain()`).
 */
final class CallbackDispatcher
{
    public const STALE_SECONDS = 3600;

    /** Seconds before each retry: Stripe's exponential schedule over three days, compressed. */
    public const BACKOFF_SECONDS = [0, 1, 2, 4, 8];

    public const MAX_ATTEMPTS = 6;

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
        $statement = $this->state->pdo()->prepare('SELECT id, delivery FROM outbox WHERE delivered = 0 AND attempts < ? AND deliver_after <= ? ORDER BY id '.($newestFirst ? 'DESC' : 'ASC'));
        $statement->execute([$this->policy()['max_attempts'], time()]);

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
     * an hour old; `wrong_secret` signs with a secret the app does not know; `unsigned` sends no signature. Delivering
     * again replays it. A 2xx answer completes it; any other answer schedules the next retry. A variant is a test of the
     * app, not one of the provider's attempts (it never sees a forged webhook): it leaves the genuine delivery's
     * attempts, schedule and completion untouched.
     *
     * @param  array{id: int, url: string, body: string, headers: array<string, string>}  $delivery
     * @param  Closure(string, string, array<string, string>): int  $transport
     */
    public function deliver(array $delivery, Closure $transport, ?string $variant = null): int
    {
        $simulator = $this->scenario->simulator();
        $headers = $delivery['headers'];

        if ($simulator instanceof SignsCallbacks && $variant !== 'unsigned') {
            $timestamp = $variant === 'stale' ? time() - self::STALE_SECONDS : time();
            $headers = [...$headers, ...$simulator->sign($this->scenario, $delivery['body'], $timestamp, $variant === 'wrong_secret')];
        }

        $status = $transport($delivery['url'], $delivery['body'], $headers);

        if ($variant !== null) {
            return $status;
        }

        if ($status >= 200 && $status < 300) {
            $this->state->pdo()->prepare('UPDATE outbox SET delivered = delivered + 1 WHERE id = ?')->execute([$delivery['id']]);

            return $status;
        }

        // Read and written under the lock, so two deliverers of the same delivery cannot lose a count.
        $this->state->transaction(function () use ($delivery): void {
            $statement = $this->state->pdo()->prepare('SELECT attempts FROM outbox WHERE id = ?');
            $statement->execute([$delivery['id']]);
            $attempts = (int) $statement->fetchColumn() + 1;
            $backoff = $this->policy()['backoff'];
            $this->state->pdo()->prepare('UPDATE outbox SET attempts = ?, deliver_after = ? WHERE id = ?')
                ->execute([$attempts, time() + $backoff[min($attempts - 1, count($backoff) - 1)], $delivery['id']]);
        });

        return $status;
    }

    /**
     * Deliver everything due, oldest first, and keep going as retries fall due, until no delivery is left that can still
     * be retried (or $maxSeconds pass). Returns each attempt's [delivery id, status], in order.
     *
     * @param  Closure(string, string, array<string, string>): int  $transport
     * @return list<array{0: int, 1: int}>
     */
    public function drain(Closure $transport, int $maxSeconds = 30): array
    {
        $deadline = microtime(true) + $maxSeconds;
        $attempts = [];

        while (microtime(true) < $deadline) {
            $due = $this->pending();

            if ($due === []) {
                $next = $this->state->pdo()->prepare('SELECT MIN(deliver_after) FROM outbox WHERE delivered = 0 AND attempts < ?');
                $next->execute([$this->policy()['max_attempts']]);
                $at = $next->fetchColumn();

                if ($at === null || $at === false) {
                    break;
                }

                usleep((int) max(50_000, min(1_000_000, ((int) $at - time()) * 1_000_000)));

                continue;
            }

            foreach ($due as $delivery) {
                $attempts[] = [$delivery['id'], $this->deliver($delivery, $transport)];
            }
        }

        return $attempts;
    }

    /**
     * Deliveries that used every attempt without a 2xx answer.
     *
     * @return list<int> their ids
     */
    public function exhausted(): array
    {
        $statement = $this->state->pdo()->prepare('SELECT id FROM outbox WHERE delivered = 0 AND attempts >= ? ORDER BY id');
        $statement->execute([$this->policy()['max_attempts']]);

        return array_values(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Change the retry schedule for this scenario (seconds before each retry, and the attempt limit).
     *
     * @param  list<int>  $backoffSeconds
     */
    public function retryPolicy(array $backoffSeconds, int $maxAttempts): void
    {
        $this->state->put('config', 'outbox_retry', ['backoff' => array_map('intval', $backoffSeconds), 'max_attempts' => max(1, $maxAttempts)]);
    }

    /**
     * @return array{backoff: list<int>, max_attempts: int}
     */
    private function policy(): array
    {
        $policy = $this->state->get('config', 'outbox_retry');

        if (! is_array($policy)) {
            return ['backoff' => self::BACKOFF_SECONDS, 'max_attempts' => self::MAX_ATTEMPTS];
        }

        return [
            'backoff' => array_values(array_map('intval', (array) ($policy['backoff'] ?? []))) ?: [0],
            'max_attempts' => max(1, (int) ($policy['max_attempts'] ?? self::MAX_ATTEMPTS)),
        ];
    }
}
