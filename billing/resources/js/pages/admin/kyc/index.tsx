import { CircleDashed, Clock, ShieldCheck, ShieldX } from 'lucide-react';
import ReviewController from '@/actions/App/Modules/Kyc/Http/Controllers/Admin/ReviewController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { KYC_TONE } from '@/components/kyc/kyc-tone';
import type { LaravelPaginated } from '@/types/app';

type Row = {
    id: number;
    name: string;
    email: string;
    status: { value: string; label: string };
    waiting: number;
    waitingSince: string | null;
};

type Props = {
    counts: {
        pending_review: number;
        declined: number;
        verified: number;
        not_started: number;
    };
    filters: { status: string; q: string };
    clients: LaravelPaginated<Row>;
};

const columns: DataTableColumn<Row>[] = [
    {
        key: 'client',
        header: 'Client',
        primary: true,
        cell: (row) => (
            <div className="min-w-0">
                <p className="truncate font-medium">{row.name}</p>
                <p className="truncate text-sm text-muted-foreground">
                    {row.email}
                </p>
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => (
            <StatusBadge
                status={row.status.value}
                label={row.status.label}
                tone={KYC_TONE[row.status.value as keyof typeof KYC_TONE]}
            />
        ),
    },
    {
        key: 'waiting',
        header: 'Waiting for review',
        align: 'right',
        hideOnMobile: true,
        cell: (row) => (row.waiting > 0 ? row.waiting : '—'),
    },
    {
        key: 'since',
        header: 'Waiting since',
        hideOnMobile: true,
        cell: (row) =>
            row.waitingSince ? (
                <DateTime value={row.waitingSince} relative />
            ) : (
                '—'
            ),
    },
];

const filterHref = (status: string) =>
    ReviewController.index({ query: { status } });

export default function KycQueue({ counts, filters, clients }: Props) {
    return (
        <>
            <Head title="Identity verification" />
            <Page>
                <PageHeader
                    title="Identity verification"
                    description="Find any client's verification status and review the documents waiting for a decision. Verification never blocks a client."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Pending review"
                        value={counts.pending_review}
                        hint="Clients with documents waiting"
                        icon={Clock}
                        tone={counts.pending_review > 0 ? 'warning' : 'default'}
                        href={filterHref('pending_review')}
                    />
                    <StatCard
                        label="Declined"
                        value={counts.declined}
                        hint="Waiting for a new upload"
                        icon={ShieldX}
                        href={filterHref('declined')}
                    />
                    <StatCard
                        label="Verified"
                        value={counts.verified}
                        hint="Two document types approved"
                        icon={ShieldCheck}
                        href={filterHref('verified')}
                    />
                    <StatCard
                        label="Not started"
                        value={counts.not_started}
                        hint="Open accounts without documents"
                        icon={CircleDashed}
                        href={filterHref('not_started')}
                    />
                </div>
                <FilterBar
                    searchPlaceholder="Search clients by name or email…"
                    filterKeys={['status']}
                    filters={
                        <FilterSelect
                            name="status"
                            label="Status"
                            allLabel="Pending review"
                            options={[
                                { value: 'declined', label: 'Declined' },
                                { value: 'verified', label: 'Verified' },
                                { value: 'not_started', label: 'Not started' },
                                { value: 'all', label: 'All clients' },
                            ]}
                        />
                    }
                />
                <SectionCard flush>
                    <DataTable
                        columns={columns}
                        rows={clients.data}
                        getRowKey={(row) => row.id}
                        rowHref={(row) => ReviewController.show(row.id)}
                        caption="Clients and their identity verification status"
                        empty={
                            <EmptyState
                                icon={ShieldCheck}
                                title={
                                    filters.q !== ''
                                        ? 'No clients match this search'
                                        : filters.status === 'pending_review'
                                          ? 'Nothing to review'
                                          : 'No clients in this list'
                                }
                                description={
                                    filters.status === 'pending_review'
                                        ? 'New identity documents appear here when clients upload them.'
                                        : undefined
                                }
                            />
                        }
                    />
                </SectionCard>
                <Pagination paginator={clients} itemLabel="clients" />
            </Page>
        </>
    );
}

KycQueue.layout = {
    breadcrumbs: [
        { title: 'Identity verification', href: ReviewController.index() },
    ],
};
