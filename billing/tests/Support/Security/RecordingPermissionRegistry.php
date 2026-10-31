<?php

namespace Tests\Support\Security;

use App\Support\Permissions\PermissionRegistry;

/**
 * Records every permission a request checks: Role::allows() asks the registry whether each one is sensitive.
 */
final class RecordingPermissionRegistry extends PermissionRegistry
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(private PermissionRegistry $inner) {}

    public function register(string $group, array $permissions, array $sensitive = []): void
    {
        $this->inner->register($group, $permissions, $sensitive);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function exists(string $permission): bool
    {
        return $this->inner->exists($permission);
    }

    public function isSensitive(string $permission): bool
    {
        $this->checked[] = $permission;

        return $this->inner->isSensitive($permission);
    }

    public function sensitive(): array
    {
        return $this->inner->sensitive();
    }
}
