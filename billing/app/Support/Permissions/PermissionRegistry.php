<?php

namespace App\Support\Permissions;

use Closure;

/**
 * Catalog of staff permissions for the role editor. Modules register theirs in bootModule():
 *
 *   app(PermissionRegistry::class)->register('Billing', [
 *       'billing.invoices.view' => 'View invoices',
 *       'billing.refunds.create' => 'Issue refunds',
 *   ], sensitive: ['billing.refunds.create']);
 *
 * Sensitive permissions (impersonation, merges, staff/role management, security settings, refunds,
 * gateway credentials, ...) are never granted through a "module.*" or "module.area.*" wildcard: a role
 * must list them explicitly. The only exception is "*", the full-administrator grant. Routes guarded by a
 * sensitive permission also require a recent password confirmation (see docs/security/standards.md).
 * Entries in $sensitive are exact keys or "prefix.*" patterns.
 */
class PermissionRegistry
{
    /** @var array<string, array<string, string|Closure(): string>> group => [permission => label, or a closure that returns it] */
    private array $groups = [];

    /** @var list<string> exact keys or "prefix.*" patterns */
    private array $sensitive = [];

    /**
     * @param  array<string, string|Closure(): string>  $permissions  a closure is called when the list is read, so the label can be translated then
     * @param  list<string>  $sensitive  keys (or "prefix.*" patterns) that wildcards must never grant
     */
    public function register(string $group, array $permissions, array $sensitive = []): void
    {
        $this->groups[$group] = [...($this->groups[$group] ?? []), ...$permissions];
        $this->sensitive = array_values(array_unique([...$this->sensitive, ...$sensitive]));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        ksort($this->groups);

        return array_map(fn (array $permissions): array => array_map(fn (string|Closure $label): string => $label instanceof Closure ? $label() : $label, $permissions), $this->groups);
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

    /**
     * Whether the permission may only be granted explicitly (or by "*").
     */
    public function isSensitive(string $permission): bool
    {
        foreach ($this->sensitive as $pattern) {
            if ($pattern === $permission || (str_ends_with($pattern, '.*') && str_starts_with($permission, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every registered permission key that is sensitive.
     *
     * @return list<string>
     */
    public function sensitive(): array
    {
        $keys = [];

        foreach ($this->groups as $permissions) {
            foreach (array_keys($permissions) as $key) {
                if ($this->isSensitive($key)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }
}
