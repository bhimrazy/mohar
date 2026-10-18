import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Plus, Users } from 'lucide-react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge, statusLabel } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import type { ClientRecord, ResourcePaginated } from '@/types';

type Props = {
    clients: ResourcePaginated<ClientRecord>;
    filters: { search?: string; status?: string };
    statuses: string[];
    can: { create: boolean };
};

const columns: DataTableColumn<ClientRecord>[] = [
    {
        key: 'name',
        header: 'Client',
        primary: true,
        cell: (client) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate">{client.display_name}</span>
                <span className="truncate text-xs font-normal text-muted-foreground">
                    #{client.id}
                </span>
            </span>
        ),
    },
    {
        key: 'email',
        header: 'Email',
        cell: (client) => (
            <span className="text-muted-foreground">{client.email}</span>
        ),
    },
    {
        key: 'group',
        header: 'Group',
        hideOnMobile: true,
        cell: (client) =>
            client.group ? (
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2 rounded-full border"
                        style={{
                            backgroundColor: client.group.colour ?? undefined,
                        }}
                    />
                    {client.group.name}
                </span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        key: 'currency',
        header: 'Currency',
        hideOnMobile: true,
        cell: (client) => (
            <span className="font-mono text-xs">{client.currency}</span>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (client) => <StatusBadge status={client.status} />,
    },
    {
        key: 'created',
        header: 'Created',
        hideOnMobile: true,
        cell: (client) => <DateTime value={client.created_at} />,
    },
];

export default function ClientsIndex({
    clients,
    filters,
    statuses,
    can,
}: Props) {
    const filtered = Boolean(filters.search || filters.status);

    return (
        <>
            <Head title="Clients" />
            <Page>
                <PageHeader
                    title="Clients"
                    description="Customer accounts, their contacts and logins."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={ClientController.create()}>
                                    <Plus />
                                    New client
                                </Link>
                            </Button>
                        )
                    }
                />

                <FilterBar
                    searchPlaceholder="Search name, company, email or ID…"
                    filterKeys={['status']}
                    only={['clients', 'filters']}
                    filters={
                        <FilterSelect
                            name="status"
                            label="Status"
                            allLabel="All statuses"
                            only={['clients', 'filters']}
                            options={statuses.map((status) => ({
                                value: status,
                                label: statusLabel(status),
                            }))}
                        />
                    }
                />

                <DataTable
                    caption="Clients"
                    columns={columns}
                    rows={clients.data}
                    getRowKey={(client) => client.id}
                    rowHref={(client) => ClientController.show(client.id)}
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Users}
                                title="No clients match these filters"
                                description="Try a different search or clear the filters."
                            />
                        ) : (
                            <EmptyState
                                icon={Users}
                                title="No clients yet"
                                description="Clients appear here when they sign up, place an order or you add one."
                                action={
                                    can.create && (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={ClientController.create()}
                                            >
                                                <Plus />
                                                New client
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        )
                    }
                    footer={
                        <Pagination
                            paginator={clients}
                            itemLabel="clients"
                            only={['clients', 'filters']}
                        />
                    }
                />
            </Page>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [{ title: 'Clients', href: ClientController.index() }],
};
