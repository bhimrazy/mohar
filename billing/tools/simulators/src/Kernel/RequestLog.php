<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;

/**
 * Every request a simulator answered, redacted with the kernel Redactor's keys and then its payment rule (card numbers,
 * CVCs, expiry, Luhn-valid digits in text), so tests can assert on it ("exactly one createacct") without the log ever
 * holding a credential or card data.
 */
final class RequestLog
{
    public function __construct(private readonly StateStore $state, private readonly Redactor $redactor = new Redactor) {}

    public function record(string $operation, Request $request, Response $response): void
    {
        $entry = [
            'operation' => $operation,
            'method' => $request->method,
            'path' => $request->path,
            'headers' => $this->redactor->redact($request->headers, sensitiveKeys: ['authorization', 'stripe-signature']),
            'input' => $this->redactor->redactPayment($this->redactor->redact($request->input())),
            'status' => $response->status,
            'response' => $this->redactor->redactPayment($this->redactor->redact(self::decoded($response->body))),
            'at' => microtime(true),
        ];

        // Login-session secrets live in values, not keys: cPanel's /cpsess<digits> path and session= query values.
        $encoded = (string) preg_replace(['#cpsess\d{6,}#', '#([?&]session=)[^&"\s]+#'], ['cpsess[redacted]', '$1[redacted]'], (string) json_encode($entry, JSON_UNESCAPED_SLASHES));

        $this->state->pdo()->prepare('INSERT INTO log (operation, entry) VALUES (?, ?)')->execute([$operation, $encoded]);
    }

    /**
     * The answer as an array: JSON objects and lists as they are, a bare JSON value (an id, `true`) under `value`, and
     * anything else as a raw excerpt.
     *
     * @return array<array-key, mixed>
     */
    private static function decoded(string $body): array
    {
        $decoded = json_decode($body, true);

        return match (true) {
            is_array($decoded) => $decoded,
            $decoded !== null => ['value' => $decoded],
            default => ['raw' => mb_strcut($body, 0, 4096)],
        };
    }

    /**
     * @return list<array<array-key, mixed>> oldest first
     */
    public function entries(?string $operation = null): array
    {
        $statement = $operation === null
            ? $this->state->pdo()->query('SELECT entry FROM log ORDER BY id')
            : $this->state->pdo()->prepare('SELECT entry FROM log WHERE operation = ? ORDER BY id');

        if ($operation !== null) {
            $statement->execute([$operation]);
        }

        $entries = [];

        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $entry) {
            $decoded = json_decode((string) $entry, true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    public function count(string $operation): int
    {
        return count($this->entries($operation));
    }
}
