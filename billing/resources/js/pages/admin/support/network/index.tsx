import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Activity, Plus } from 'lucide-react';
import NetworkIncidentController from '@/actions/App/Modules/Support/Http/Controllers/Admin/NetworkIncidentController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { PriorityBadge } from '@/components/support/priority-badge';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types/app';

type Incident = {
    id: number;
    title: string;
    type: string;
    affected: string | null;
    priority: string;
    status: string;
    starts_at: string;
    ends_at: string | null;
};

const columns: DataTableColumn<Incident>[] = [
    {
        key: 'title',
        header: 'Incident',
        primary: true,
        cell: (incident) => incident.title,
    },
    {
        key: 'affected',
        header: 'Affected',
        cell: (incident) =>
            incident.affected ?? (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (incident) => <StatusBadge status={incident.status} />,
    },
    {
        key: 'priority',
        header: 'Priority',
        cell: (incident) => <PriorityBadge priority={incident.priority} />,
    },
    {
        key: 'starts',
        header: 'Started',
        align: 'right',
        cell: (incident) => (
            <DateTime value={incident.starts_at} format="datetime" />
        ),
    },
    {
        key: 'ends',
        header: 'Ended',
        align: 'right',
        hideOnMobile: true,
        cell: (incident) => (
            <DateTime value={incident.ends_at} format="datetime" />
        ),
    },
];

export default function NetworkIndex({
    incidents,
}: {
    incidents: LaravelPaginated<Incident>;
}) {
    return (
        <>
            <Head title="Network status" />
            <Page>
                <PageHeader
                    title="Network status"
                    description="Incidents and maintenance shown on the network status page."
                    actions={
                        <Button asChild>
                            <Link href={NetworkIncidentController.create()}>
                                <Plus />
                                New incident
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Incidents"
                    columns={columns}
                    rows={incidents.data}
                    getRowKey={(incident) => incident.id}
                    rowHref={(incident) =>
                        NetworkIncidentController.edit(incident.id)
                    }
                    empty={
                        <EmptyState
                            icon={Activity}
                            title="No incidents"
                            description="Report outages and planned maintenance here so clients know what is going on."
                        />
                    }
                    footer={
                        <Pagination
                            paginator={incidents}
                            itemLabel="incidents"
                        />
                    }
                />
            </Page>
        </>
    );
}
