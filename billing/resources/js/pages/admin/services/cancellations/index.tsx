import { Link, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Inbox } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import CancellationRequestController from '@/actions/App/Modules/Services/Http/Controllers/Admin/CancellationRequestController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import { show as showClient } from '@/routes/admin/clients';
import type { LaravelPaginated, SelectOption } from '@/types/app';

type RequestRow = {
    id: number;
    service_id: number;
    service_label: string;
    service_status: string;
    client: { id: number; name: string };
    type: string;
    type_label: string;
    reason: string | null;
    status: string;
    by_staff: boolean;
    requested_by: string | null;
    process_after: string;
    created_at: string | null;
};

type Props = {
    requests: LaravelPaginated<RequestRow>;
    statuses: SelectOption[];
    filters: { status: string };
};

export default function CancellationsIndex({
    requests,
    statuses,
    filters,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [pending, setPending] = useState<{
        row: RequestRow;
        action: 'approve' | 'void';
    } | null>(null);

    const columns: DataTableColumn<RequestRow>[] = [
        {
            key: 'service',
            header: 'Service',
            primary: true,
            cell: (row) => (
                <span className="flex flex-col">
                    <span>{row.service_label}</span>
                    <span className="text-xs font-normal text-muted-foreground">
                        #{row.service_id}
                    </span>
                </span>
            ),
        },
        {
            key: 'client',
            header: 'Client',
            cell: (row) => (
                <Link
                    href={showClient(row.client.id)}
                    className="hover:underline"
                >
                    {row.client.name}
                </Link>
            ),
        },
        {
            key: 'type',
            header: 'When',
            cell: (row) => (
                <span className="flex flex-col">
                    <span>{row.type_label}</span>
                    <span className="text-xs text-muted-foreground">
                        Process on <DateTime value={row.process_after} />
                    </span>
                </span>
            ),
        },
        {
            key: 'reason',
            header: 'Reason',
            hideOnMobile: true,
            cell: (row) => (
                <span className="flex max-w-xs flex-col whitespace-normal">
                    <span className="line-clamp-2">{row.reason ?? '—'}</span>
                    <span className="text-xs text-muted-foreground">
                        {row.by_staff ? 'Staff' : 'Client'}
                        {row.requested_by && ` · ${row.requested_by}`} ·{' '}
                        <DateTime value={row.created_at} relative />
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (row) =>
                row.status === 'open' || row.status === 'failed' ? (
                    <span className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setPending({ row, action: 'void' })}
                        >
                            Void
                        </Button>
                        {row.status === 'open' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setPending({ row, action: 'approve' })
                                }
                            >
                                Approve
                            </Button>
                        )}
                    </span>
                ) : null,
        },
    ];

    return (
        <>
            <Head title="Cancellation requests" />
            <Page>
                <PageHeader
                    title="Cancellation requests"
                    description="Requests from clients and staff. Approving terminates the service on the server and marks it cancelled."
                />
                {errors.action && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.action}</AlertDescription>
                    </Alert>
                )}
                <FilterBar
                    searchParam={null}
                    filterKeys={[]}
                    filters={
                        <FilterSelect
                            name="status"
                            label="Status"
                            allLabel="Open"
                            options={[
                                ...statuses.filter(
                                    (status) => status.value !== 'open',
                                ),
                                { value: 'all', label: 'All' },
                            ]}
                        />
                    }
                />
                <DataTable
                    caption="Cancellation requests"
                    columns={columns}
                    rows={requests.data}
                    getRowKey={(row) => row.id}
                    rowHref={(row) => ServiceController.show(row.service_id)}
                    empty={
                        <EmptyState
                            icon={Inbox}
                            title={
                                filters.status === 'open'
                                    ? 'No open cancellation requests'
                                    : 'No requests match this filter'
                            }
                            description="Requests appear here when a client or staff member asks to cancel a service."
                        />
                    }
                    footer={
                        <Pagination paginator={requests} itemLabel="requests" />
                    }
                />
            </Page>
            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                title={
                    pending?.action === 'approve'
                        ? `Cancel ${pending.row.service_label} now?`
                        : `Void the request for ${pending?.row.service_label ?? ''}?`
                }
                description={
                    pending?.action === 'approve'
                        ? 'The account is terminated on the server now and the service is marked cancelled. This can’t be undone.'
                        : 'Renewals resume and periods whose invoices were cancelled are invoiced again on the next run.'
                }
                confirmLabel={
                    pending?.action === 'approve'
                        ? 'Cancel service'
                        : 'Void request'
                }
                destructive={pending?.action === 'approve'}
                action={
                    pending
                        ? pending.action === 'approve'
                            ? CancellationRequestController.approve(
                                  pending.row.id,
                              )
                            : CancellationRequestController.void(pending.row.id)
                        : undefined
                }
            />
        </>
    );
}
