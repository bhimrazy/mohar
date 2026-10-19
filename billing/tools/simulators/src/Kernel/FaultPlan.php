<?php

namespace Mohar\Simulators\Kernel;

/**
 * Faults keyed by operation and nth call (null: every call). Each planned fault with an nth fires once.
 */
final class FaultPlan
{
    public function __construct(private readonly StateStore $state) {}

    public function add(string $operation, Fault $fault, ?int $nth = null): void
    {
        $this->state->pdo()->prepare('INSERT INTO faults (operation, nth, fault) VALUES (?, ?, ?)')
            ->execute([$operation, $nth, json_encode($fault->toArray())]);
    }

    public function clear(): void
    {
        $this->state->pdo()->exec('DELETE FROM faults');
    }

    /**
     * The fault for this call of the operation, if any; a fault planned for one call is used up.
     */
    public function for(string $operation, int $call): ?Fault
    {
        $pdo = $this->state->pdo();
        $statement = $pdo->prepare('SELECT id, nth, fault FROM faults WHERE operation = ? AND used = 0 AND (nth IS NULL OR nth = ?) ORDER BY nth IS NULL, id LIMIT 1');
        $statement->execute([$operation, $call]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if ($row['nth'] !== null) {
            $pdo->prepare('UPDATE faults SET used = 1 WHERE id = ?')->execute([$row['id']]);
        }

        return Fault::fromArray(json_decode((string) $row['fault'], true));
    }
}
