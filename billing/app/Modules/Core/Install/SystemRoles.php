<?php

namespace App\Modules\Core\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Models\Role;

/**
 * The four system roles. They are brought back to this definition on every run (docs/security/standards.md §2), so
 * an upgrade that adds a permission reaches them. Sensitive permissions are listed by exact key, never by wildcard.
 * Permissions themselves are code: each module registers its own in the PermissionRegistry.
 */
final class SystemRoles implements InstallsReferenceData
{
    public const KEY = 'core_roles';

    public const FULL_ADMINISTRATOR = 'Full Administrator';

    /** @var array<string, array{description: string, permissions: list<string>}> */
    public const ROLES = [
        self::FULL_ADMINISTRATOR => [
            'description' => 'Unrestricted access to everything.',
            'permissions' => ['*'],
        ],
        'Billing' => [
            'description' => 'Clients, orders, invoices and billing reports.',
            'permissions' => ['core.clients.view', 'core.clients.create', 'core.clients.update', 'core.contacts.manage', 'billing.*', 'billing.payments.manage', 'billing.transactions.manage', 'billing.refunds.manage', 'billing.credit.adjust', 'orders.*', 'reports.*'],
        ],
        'Support' => [
            'description' => 'Tickets plus read access to clients, services and domains.',
            'permissions' => ['core.clients.view', 'support.*', 'services.services.view', 'domains.domains.view'],
        ],
        'Sales' => [
            'description' => 'Read clients and orders, accept orders, manage quotes.',
            'permissions' => ['core.clients.view', 'orders.orders.view', 'orders.orders.accept', 'billing.quotes.view', 'billing.quotes.manage'],
        ],
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'System roles';
    }

    public function install(): ReferenceDataResult
    {
        $created = $updated = $unchanged = 0;

        foreach (self::ROLES as $name => $definition) {
            $role = Role::query()->firstOrNew(['name' => $name]);
            $role->fill([...$definition, 'is_system' => true]);

            match (true) {
                ! $role->exists => $created++,
                $role->isDirty() => $updated++,
                default => $unchanged++,
            };

            $role->save();
        }

        return new ReferenceDataResult($created, $updated, $unchanged);
    }
}
