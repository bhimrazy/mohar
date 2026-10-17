import { Head, Link } from '@inertiajs/react';
import { Lock, Pencil, Plus, Shield, Trash2 } from 'lucide-react';
import RoleController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/RoleController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type RoleRow = {
    id: number;
    name: string;
    description: string | null;
    permissions: string[];
    users_count: number;
    is_system: boolean;
    editable: boolean;
};

function summary(permissions: string[]): string {
    if (permissions.includes('*')) {
        return 'Full access';
    }

    const shown = permissions.slice(0, 3).join(', ');

    return permissions.length > 3
        ? `${shown} +${permissions.length - 3} more`
        : shown;
}

export default function RolesIndex({ roles }: { roles: RoleRow[] }) {
    const columns: DataTableColumn<RoleRow>[] = [
        {
            key: 'name',
            header: 'Role',
            primary: true,
            cell: (role) => (
                <span className="flex min-w-0 flex-col">
                    <span className="flex items-center gap-2">
                        {role.name}
                        {role.is_system && (
                            <Badge variant="secondary">System</Badge>
                        )}
                    </span>
                    {role.description && (
                        <span className="truncate text-xs font-normal text-muted-foreground">
                            {role.description}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'permissions',
            header: 'Permissions',
            cell: (role) => (
                <span className="font-mono text-xs text-muted-foreground">
                    {summary(role.permissions)}
                </span>
            ),
        },
        {
            key: 'users',
            header: 'Staff',
            align: 'right',
            cell: (role) => (
                <span className="tabular-nums">{role.users_count}</span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (role) =>
                role.editable ? (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            asChild
                            aria-label={`Edit ${role.name}`}
                        >
                            <Link href={RoleController.edit(role.id)}>
                                <Pencil />
                            </Link>
                        </Button>
                        {!role.is_system && role.users_count === 0 && (
                            <ConfirmDialog
                                title={`Delete role ${role.name}?`}
                                description="This can't be undone."
                                confirmLabel="Delete role"
                                action={RoleController.destroy(role.id)}
                                trigger={
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Delete ${role.name}`}
                                    >
                                        <Trash2 />
                                    </Button>
                                }
                            />
                        )}
                    </div>
                ) : (
                    <span
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                        title="This role has permissions you don't hold."
                    >
                        <Lock className="size-3.5" aria-hidden="true" />
                        Locked
                    </span>
                ),
        },
    ];

    return (
        <>
            <Head title="Roles" />
            <Page>
                <PageHeader
                    title="Roles"
                    description="Each staff member has one role. Roles grant permissions, with wildcards such as billing.*."
                    actions={
                        <Button asChild>
                            <Link href={RoleController.create()}>
                                <Plus />
                                New role
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Roles"
                    columns={columns}
                    rows={roles}
                    getRowKey={(role) => role.id}
                    rowHref={(role) =>
                        role.editable ? RoleController.edit(role.id) : undefined
                    }
                    empty={<EmptyState icon={Shield} title="No roles yet" />}
                />
            </Page>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Roles', href: RoleController.index() }],
};
