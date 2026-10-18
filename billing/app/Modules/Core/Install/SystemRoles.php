<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Events\Core\RoleUpdated;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;

/**
 * The four system roles, identified by their stable `roles.key`, never by name: staff may rename a system role, and a
 * custom role that happens to carry a system role's name is never touched. On every run a system role's permissions
 * are reset to this definition (docs/security/standards.md §2), so an upgrade that adds a permission reaches it; the
 * staff-chosen name and description are kept. Every reset is written to the audit log and dispatches RoleUpdated.
 * Permissions themselves are code: each module registers its own in the PermissionRegistry.
 */
final class SystemRoles implements InstallsReferenceData
{
    public const KEY = 'core_roles';

    public const FULL_ADMINISTRATOR = 'full_administrator';

    /**
     * Key => the name and description a new install starts with, and the permissions every run enforces. Sensitive
     * permissions are listed by exact key, never by wildcard.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public const ROLES = [
        self::FULL_ADMINISTRATOR => [
            'name' => 'Full Administrator',
            'description' => 'Unrestricted access to everything.',
            'permissions' => ['*'],
        ],
        'billing' => [
            'name' => 'Billing',
            'description' => 'Clients, orders, invoices and billing reports.',
            'permissions' => ['core.clients.view', 'core.clients.create', 'core.clients.update', 'core.contacts.manage', 'billing.*', 'billing.payments.manage', 'billing.transactions.manage', 'billing.refunds.manage', 'billing.credit.adjust', 'orders.*', 'reports.*'],
        ],
        'support' => [
            'name' => 'Support',
            'description' => 'Tickets plus read access to clients, services and domains.',
            'permissions' => ['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view'],
        ],
        'sales' => [
            'name' => 'Sales',
            'description' => 'Read clients and orders, accept orders, manage quotes.',
            'permissions' => ['core.clients.view', 'orders.orders.view', 'orders.orders.accept', 'billing.quotes.view', 'billing.quotes.manage'],
        ],
    ];

    public function __construct(private ActivityLogger $activity) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'System roles';
    }

    /**
     * The permissions a system role is reset to, sorted the way the role editor saves them.
     *
     * @return list<string>
     */
    public static function permissions(string $key): array
    {
        $permissions = self::ROLES[$key]['permissions'];
        sort($permissions);

        return $permissions;
    }

    public function install(): ReferenceDataResult
    {
        $created = $updated = $unchanged = 0;

        foreach (self::ROLES as $key => $definition) {
            $role = Role::query()->where('key', $key)->first();

            if ($role === null) {
                $this->create($key, $definition['name'], $definition['description']);
                $created++;

                continue;
            }

            $this->reset($role, $key) ? $updated++ : $unchanged++;
        }

        return new ReferenceDataResult($created, $updated, $unchanged);
    }

    /**
     * A new system role. When a custom role already uses the name, the system role gets a free "(system)" variant
     * instead: the custom role keeps its name and permissions.
     */
    private function create(string $key, string $name, string $description): void
    {
        $candidate = $name;

        for ($attempt = 1; Role::query()->where('name', $candidate)->exists(); $attempt++) {
            $candidate = $attempt === 1 ? "{$name} (system)" : "{$name} (system {$attempt})";
        }

        (new Role)->forceFill([
            'key' => $key,
            'name' => $candidate,
            'description' => $description,
            'permissions' => self::permissions($key),
            'is_system' => true,
        ])->save();
    }

    /**
     * Reset the permissions of an existing system role. Returns whether anything changed.
     */
    private function reset(Role $role, string $key): bool
    {
        $before = $role->permissions;
        $role->forceFill(['permissions' => self::permissions($key), 'is_system' => true]);

        if (! $role->isDirty()) {
            return false;
        }

        $role->save();

        $this->activity->log('System role reset to its built-in permissions', $role, properties: [
            'role' => $role->name,
            'added' => array_values(array_diff($role->permissions, $before)),
            'removed' => array_values(array_diff($before, $role->permissions)),
        ], event: 'role.updated');
        RoleUpdated::dispatch($role->id);

        return true;
    }
}
