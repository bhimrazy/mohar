import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Tag, Trash2 } from 'lucide-react';
import ClientGroupController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientGroupController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Button } from '@/components/ui/button';

type GroupRow = {
    id: number;
    name: string;
    colour: string | null;
    discount_percent: string;
    exempt_from_suspension: boolean;
    separate_invoices: boolean;
    clients_count: number;
};

export default function ClientGroupsIndex({ groups }: { groups: GroupRow[] }) {
    const columns: DataTableColumn<GroupRow>[] = [
        {
            key: 'name',
            header: 'Group',
            primary: true,
            cell: (group) => (
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        className="size-2.5 rounded-full border"
                        style={{ backgroundColor: group.colour ?? undefined }}
                    />
                    {group.name}
                </span>
            ),
        },
        {
            key: 'discount',
            header: 'Discount',
            align: 'right',
            cell: (group) => (
                <span className="tabular-nums">
                    {Number(group.discount_percent)}%
                </span>
            ),
        },
        {
            key: 'rules',
            header: 'Rules',
            hideOnMobile: true,
            cell: (group) => {
                const rules = [
                    group.exempt_from_suspension && 'Never auto-suspended',
                    group.separate_invoices && 'Separate invoices',
                ].filter(Boolean);

                return (
                    <span className="text-muted-foreground">
                        {rules.length ? rules.join(' · ') : '—'}
                    </span>
                );
            },
        },
        {
            key: 'clients',
            header: 'Clients',
            align: 'right',
            cell: (group) => (
                <span className="tabular-nums">{group.clients_count}</span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (group) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        asChild
                        aria-label={`Edit ${group.name}`}
                    >
                        <Link href={ClientGroupController.edit(group.id)}>
                            <Pencil />
                        </Link>
                    </Button>
                    {group.clients_count === 0 && (
                        <ConfirmDialog
                            title={`Delete group ${group.name}?`}
                            description="This can't be undone."
                            confirmLabel="Delete group"
                            action={ClientGroupController.destroy(group.id)}
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Delete ${group.name}`}
                                >
                                    <Trash2 />
                                </Button>
                            }
                        />
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Client groups" />
            <Page>
                <PageHeader
                    title="Client groups"
                    description="Group clients for discounts, suspension rules and domain pricing."
                    actions={
                        <Button asChild>
                            <Link href={ClientGroupController.create()}>
                                <Plus />
                                New group
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Client groups"
                    columns={columns}
                    rows={groups}
                    getRowKey={(group) => group.id}
                    rowHref={(group) => ClientGroupController.edit(group.id)}
                    empty={
                        <EmptyState
                            icon={Tag}
                            title="No client groups yet"
                            description="Create a group to give resellers or VIP clients a discount."
                            action={
                                <Button size="sm" asChild>
                                    <Link href={ClientGroupController.create()}>
                                        <Plus />
                                        New group
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
            </Page>
        </>
    );
}

ClientGroupsIndex.layout = {
    breadcrumbs: [
        { title: 'Client groups', href: ClientGroupController.index() },
    ],
};
