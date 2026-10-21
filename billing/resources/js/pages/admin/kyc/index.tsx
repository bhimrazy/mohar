import { ShieldCheck } from 'lucide-react';
import ReviewController from '@/actions/App/Modules/Kyc/Http/Controllers/Admin/ReviewController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import type { LaravelPaginated } from '@/types/app';

type Row = {
    client: { id: number; name: string; email: string | null };
    waiting: number;
    waitingSince: string | null;
};

const columns: DataTableColumn<Row>[] = [
    {
        key: 'client',
        header: 'Client',
        primary: true,
        cell: (row) => (
            <div className="min-w-0">
                <p className="truncate font-medium">{row.client.name}</p>
                <p className="truncate text-sm text-muted-foreground">
                    {row.client.email}
                </p>
            </div>
        ),
    },
    {
        key: 'waiting',
        header: 'Documents waiting',
        align: 'right',
        cell: (row) => row.waiting,
    },
    {
        key: 'since',
        header: 'Waiting since',
        hideOnMobile: true,
        cell: (row) =>
            row.waitingSince ? (
                <DateTime value={row.waitingSince} relative />
            ) : null,
    },
];

export default function KycQueue({ queue }: { queue: LaravelPaginated<Row> }) {
    return (
        <>
            <Head title="Identity verification" />
            <Page>
                <PageHeader
                    title="Identity verification"
                    description="Clients with identity documents waiting for review, oldest first. Verification never blocks a client."
                />
                <SectionCard flush>
                    <DataTable
                        columns={columns}
                        rows={queue.data}
                        getRowKey={(row) => row.client.id}
                        rowHref={(row) => ReviewController.show(row.client.id)}
                        caption="Clients waiting for identity review"
                        empty={
                            <EmptyState
                                icon={ShieldCheck}
                                title="Nothing to review"
                                description="New identity documents appear here when clients upload them."
                            />
                        }
                    />
                </SectionCard>
                <Pagination paginator={queue} itemLabel="clients" />
            </Page>
        </>
    );
}

KycQueue.layout = {
    breadcrumbs: [
        { title: 'Identity verification', href: ReviewController.index() },
    ],
};
