<?php

declare(strict_types=1);

namespace LoadTest\Seed;

use Illuminate\Database\ConnectionInterface;

/**
 * Buffers rows per table and writes them as multi-row INSERTs. The batch size keeps every statement under
 * 30,000 bound parameters (PostgreSQL allows 65,535 and SQLite 32,766 since 3.32).
 */
final class BulkWriter
{
    private const MAX_PARAMETERS = 30_000;

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $written = [];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function add(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;

        // A full buffer flushes every table, in the order the tables were first used: parents (invoices) are
        // always added before their children (items, links, transactions), so foreign keys hold at each INSERT.
        if (count($this->buffers[$table]) >= max(1, intdiv(self::MAX_PARAMETERS, count($row)))) {
            $this->flush();
        }
    }

    public function flush(?string $table = null): void
    {
        foreach ($table === null ? array_keys($this->buffers) : [$table] as $name) {
            $rows = $this->buffers[$name] ?? [];

            if ($rows === []) {
                continue;
            }

            $this->db->table($name)->insert($rows);
            $this->written[$name] = ($this->written[$name] ?? 0) + count($rows);
            $this->buffers[$name] = [];
        }
    }

    /**
     * @return array<string, int>
     */
    public function written(): array
    {
        return $this->written;
    }
}
