<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\MergeFieldRegistry;
use Closure;
use InvalidArgumentException;

class InMemoryMergeFieldRegistry implements MergeFieldRegistry
{
    /** @var array<string, Closure(int): array<string, mixed>> */
    private array $resolvers = [];

    public function register(string $entity, Closure $resolver): void
    {
        $this->resolvers[$entity] = $resolver;
    }

    public function resolve(string $entity, int $id): array
    {
        if (! $this->has($entity)) {
            throw new InvalidArgumentException("No merge-field resolver registered for [{$entity}].");
        }

        return ($this->resolvers[$entity])($id);
    }

    public function has(string $entity): bool
    {
        return isset($this->resolvers[$entity]);
    }

    public function entities(): array
    {
        return array_keys($this->resolvers);
    }
}
