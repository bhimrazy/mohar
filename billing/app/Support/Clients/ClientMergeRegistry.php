<?php

namespace App\Support\Clients;

use Closure;

/**
 * Participants in a client merge. Each module that owns client data registers a preview in bootModule():
 *
 *   app(ClientMergeRegistry::class)->participant('billing.invoices', fn (int $sourceId, int $targetId): array => [
 *       'label' => 'Invoices',
 *       'count' => Invoice::query()->where('client_id', $sourceId)->count(),
 *       // optional: a reason that vetoes the merge, e.g. both clients are affiliates with ledger entries
 *       'blocking' => null,
 *   ]);
 *
 * The merge itself moves only Core-owned rows (contacts, logins) and dispatches App\Events\Core\ClientMerged
 * inside its database transaction; each module re-points and reconciles its own tables in a synchronous
 * listener (so a failure rolls the whole merge back).
 *
 * @api
 */
class ClientMergeRegistry
{
    /** @var array<string, Closure(int, int): array{label: string, count: int, blocking?: string|null}> */
    private array $participants = [];

    /**
     * @param  Closure(int, int): array{label: string, count: int, blocking?: string|null}  $preview
     */
    public function participant(string $key, Closure $preview): void
    {
        $this->participants[$key] = $preview;
    }

    /**
     * @return list<array{key: string, label: string, count: int, blocking: string|null}>
     */
    public function preview(int $sourceId, int $targetId): array
    {
        $items = [];

        foreach ($this->participants as $key => $preview) {
            $result = $preview($sourceId, $targetId);
            $items[] = [
                'key' => $key,
                'label' => $result['label'],
                'count' => (int) $result['count'],
                'blocking' => isset($result['blocking']) && $result['blocking'] !== '' ? $result['blocking'] : null,
            ];
        }

        return $items;
    }

    /**
     * Reasons that veto merging $sourceId into $targetId.
     *
     * @return list<string>
     */
    public function blockers(int $sourceId, int $targetId): array
    {
        return array_values(array_filter(array_map(
            fn (array $item): ?string => $item['blocking'],
            $this->preview($sourceId, $targetId),
        )));
    }
}
