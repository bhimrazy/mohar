<?php

namespace App\Support\Permissions;

/**
 * Catalog of staff permissions for the role editor. Modules register theirs in bootModule():
 *
 *   app(PermissionRegistry::class)->register('Billing', [
 *       'billing.invoices.view' => 'View invoices',
 *       'billing.invoices.manage' => 'Create, edit and cancel invoices',
 *   ]);
 */
class PermissionRegistry
{
    /** @var array<string, array<string, string>> group => [permission => label] */
    private array $groups = [];

    /**
     * @param  array<string, string>  $permissions
     */
    public function register(string $group, array $permissions): void
    {
        $this->groups[$group] = [...($this->groups[$group] ?? []), ...$permissions];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        ksort($this->groups);

        return $this->groups;
    }

    public function exists(string $permission): bool
    {
        foreach ($this->groups as $permissions) {
            if (array_key_exists($permission, $permissions)) {
                return true;
            }
        }

        return false;
    }
}
