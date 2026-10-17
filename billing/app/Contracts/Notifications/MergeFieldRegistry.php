<?php

namespace App\Contracts\Notifications;

use Closure;

/**
 * Each module registers resolvers that turn an entity id into template merge fields,
 * e.g. register('invoice', fn (int $id) => ['number' => ..., 'total' => ..., 'client_id' => ...]).
 * Resolvers SHOULD include 'client_id' when the entity belongs to a client.
 */
interface MergeFieldRegistry
{
    /**
     * @param  Closure(int): array<string, mixed>  $resolver
     */
    public function register(string $entity, Closure $resolver): void;

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $entity, int $id): array;

    public function has(string $entity): bool;

    /**
     * @return list<string>
     */
    public function entities(): array;
}
