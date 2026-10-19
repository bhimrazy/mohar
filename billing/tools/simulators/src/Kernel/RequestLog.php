<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;

/**
 * Every request a simulator answered, redacted with the kernel Redactor's keys, so tests can assert on it ("exactly one
 * createacct") without the log ever holding a credential.
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
            'input' => $this->redactor->redact($request->input()),
            'status' => $response->status,
            'response' => $this->redactor->redact(json_decode($response->body, true) ?? ['raw' => mb_strcut($response->body, 0, 4096)]),
            'at' => microtime(true),
        ];

        $this->state->pdo()->prepare('INSERT INTO log (operation, entry) VALUES (?, ?)')->execute([$operation, json_encode($entry, JSON_UNESCAPED_SLASHES)]);
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
