<?php

namespace Mohar\Simulators\Kernel;

use PDO;

/**
 * A scenario's state: one SQLite file in the scenario directory (never the app database), opened with WAL and a busy
 * timeout so the API workers, the control plane and the test process can share it.
 *
 * Simulators keep their records as JSON documents by namespace and key (`accounts` => `example` => [...]).
 */
final class StateStore
{
    private ?PDO $pdo = null;

    /** How many transaction() calls are open on this connection. */
    private int $depth = 0;

    public function __construct(private readonly string $path) {}

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS documents (namespace TEXT NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (namespace, key))');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, operation TEXT, entry TEXT NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS faults (id INTEGER PRIMARY KEY AUTOINCREMENT, operation TEXT NOT NULL, nth INTEGER, fault TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, delivery TEXT NOT NULL, deliver_after INTEGER NOT NULL DEFAULT 0, delivered INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0)');
        }

        return $this->pdo;
    }

    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        $statement = $this->pdo()->prepare('SELECT value FROM documents WHERE namespace = ? AND key = ?');
        $statement->execute([$namespace, $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : json_decode((string) $value, true);
    }

    public function put(string $namespace, string $key, mixed $value): void
    {
        $this->pdo()->prepare('INSERT INTO documents (namespace, key, value) VALUES (?, ?, ?) ON CONFLICT (namespace, key) DO UPDATE SET value = excluded.value')
            ->execute([$namespace, $key, json_encode($value, JSON_UNESCAPED_SLASHES)]);
    }

    public function delete(string $namespace, string $key): void
    {
        $this->pdo()->prepare('DELETE FROM documents WHERE namespace = ? AND key = ?')->execute([$namespace, $key]);
    }

    /**
     * @return array<string, mixed> key => value, in key order
     */
    public function all(string $namespace): array
    {
        $statement = $this->pdo()->prepare('SELECT key, value FROM documents WHERE namespace = ? ORDER BY key');
        $statement->execute([$namespace]);
        $values = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['key']] = json_decode((string) $row['value'], true);
        }

        return $values;
    }

    /**
     * Add one to a counter and return the new value, atomically across processes.
     */
    public function increment(string $namespace, string $key): int
    {
        return $this->transaction(function () use ($namespace, $key): int {
            $value = (int) $this->get($namespace, $key, 0) + 1;
            $this->put($namespace, $key, $value);

            return $value;
        });
    }

    /**
     * Run $work under a write lock held across processes (BEGIN IMMEDIATE), so a check and the change it guards cannot
     * interleave with another worker's: two creates for one username, for example. Nestable: work inside work (an id
     * counter inside an idempotency check) runs in a savepoint of the outer transaction.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $pdo = $this->pdo();
        $savepoint = $this->depth > 0 ? 'level'.$this->depth : null;
        $pdo->exec($savepoint === null ? 'BEGIN IMMEDIATE' : "SAVEPOINT {$savepoint}");
        $this->depth++;

        try {
            $result = $work();
            $pdo->exec($savepoint === null ? 'COMMIT' : "RELEASE {$savepoint}");

            return $result;
        } catch (\Throwable $exception) {
            $pdo->exec($savepoint === null ? 'ROLLBACK' : "ROLLBACK TO {$savepoint}; RELEASE {$savepoint}");

            throw $exception;
        } finally {
            $this->depth--;
        }
    }
}
