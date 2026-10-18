<?php

namespace App\Modules\Services\Support;

use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Exceptions\NoServerCapacity;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * Picks the server for a new account (services.md §9).
 */
class ServerSelector
{
    /**
     * @throws NoServerCapacity
     */
    public function select(?int $serverGroupId, string $driver): Server
    {
        $group = $serverGroupId !== null ? ServerGroup::query()->find($serverGroupId) : null;

        $server = $group !== null ? $this->fromGroup($group) : $this->defaultFor($driver);

        if ($server === null) {
            throw new NoServerCapacity;
        }

        return $server;
    }

    private function fromGroup(ServerGroup $group): ?Server
    {
        /** @var Collection<int, Server> $servers */
        $servers = $group->servers()->where('is_active', true)->withActiveAccounts()->get();
        $available = $servers->filter(fn (Server $server): bool => $server->hasCapacity())->values();

        if ($group->fill_type === FillType::FillSequentially) {
            return $available->first();
        }

        return $available
            ->sortBy([
                fn (Server $a, Server $b): int => $a->fillRatio() <=> $b->fillRatio(),
                fn (Server $a, Server $b): int => ((int) $a->getRelationValue('pivot')?->getAttribute('sort_order')) <=> ((int) $b->getRelationValue('pivot')?->getAttribute('sort_order')),
                fn (Server $a, Server $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function defaultFor(string $driver): ?Server
    {
        return Server::query()
            ->where('driver', $driver)
            ->where('is_active', true)
            ->withActiveAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->first(fn (Server $server): bool => $server->hasCapacity());
    }
}
