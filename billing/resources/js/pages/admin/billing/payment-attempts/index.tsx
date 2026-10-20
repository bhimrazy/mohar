import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Activity } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { humanize } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import {
    check,
    lateChecked,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/PaymentAttemptController';
import type { LaravelPaginated, SelectOption } from '@/types/app';

type Attempt = {
    id: number;
    reference: string;
    gateway: string;
    status: string;
    amount: number;
    currency: string;
    invoice_id: number | null;
    invoice_number: string | null;
    provider_txn_id: string | null;
    review_reason: string | null;
    lookup_count: number;
    last_lookup_at: string | null;
    expires_at: string | null;
    created_at: string | null;
    late_payment_at: string | null;
    late_checked_at: string | null;
    check_reason: string | null;
    needs_check: boolean;
};

type Props = {
    attempts: LaravelPaginated<Attempt>;
    filters: Record<string, string>;
    statuses: SelectOption[];
};

const LATE_OPTIONS: SelectOption[] = [{ value: '1', label: 'Late payments' }];

/** Why staff should check a booked payment (`check_reason`). */
const CHECK_LABELS: Record<string, string> = {
    late: 'Late payment',
    credit: 'Kept as credit',
    refund: 'Refund over credit',
};

function flagLabel(attempt: Attempt): string | null {
    const label = attempt.check_reason
        ? (CHECK_LABELS[attempt.check_reason] ?? 'To check')
        : attempt.late_payment_at
          ? CHECK_LABELS.late
          : null;

    return label && attempt.late_checked_at ? `${label}, checked` : label;
}

/** Closed attempts can still be looked up: a late success at the gateway is booked. */
const CHECKABLE = ['pending', 'ambiguous', 'expired', 'failed'];

const post = (url: string) => router.post(url, {}, { preserveScroll: true });

const TONES: Record<string, string> = {
    pending: 'pending',
    succeeded: 'paid',
    failed: 'failed',
    expired: 'cancelled',
    ambiguous: 'overdue',
};

export default function PaymentAttempts({ attempts, statuses }: Props) {
    const columns: DataTableColumn<Attempt>[] = [
        {
            key: 'reference',
            header: 'Reference',
            primary: true,
            cell: (attempt) => (
                <span className="font-mono text-xs">{attempt.reference}</span>
            ),
        },
        {
            key: 'gateway',
            header: 'Gateway',
            cell: (attempt) => humanize(attempt.gateway),
        },
        {
            key: 'invoice',
            header: 'Invoice',
            cell: (attempt) =>
                attempt.invoice_id ? (
                    <Link
                        href={showInvoice(attempt.invoice_id)}
                        className="hover:underline"
                    >
                        {attempt.invoice_number ?? `#${attempt.invoice_id}`}
                    </Link>
                ) : (
                    '—'
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (attempt) => (
                <div className="space-y-1">
                    <div className="flex flex-wrap gap-1">
                        <StatusBadge
                            status={TONES[attempt.status] ?? attempt.status}
                            label={humanize(attempt.status)}
                        />
                        {flagLabel(attempt) && (
                            <StatusBadge
                                status={
                                    attempt.late_checked_at
                                        ? 'resolved'
                                        : 'review'
                                }
                                label={flagLabel(attempt) ?? ''}
                            />
                        )}
                    </div>
                    {attempt.review_reason && (
                        <div className="text-xs whitespace-normal text-muted-foreground">
                            {attempt.review_reason}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'checked',
            header: 'Last lookup',
            hideOnMobile: true,
            cell: (attempt) => (
                <DateTime
                    value={attempt.last_lookup_at}
                    relative
                    fallback="Never"
                />
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (attempt) => (
                <Money amount={attempt.amount} currency={attempt.currency} />
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            cell: (attempt) => (
                <div className="flex justify-end gap-1">
                    {attempt.needs_check && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => post(lateChecked.url(attempt.id))}
                        >
                            Mark checked
                        </Button>
                    )}
                    {CHECKABLE.includes(attempt.status) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => post(check.url(attempt.id))}
                        >
                            Check now
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Payment attempts" />
            <Page>
                <PageHeader
                    title="Payment attempts"
                    description="Hosted gateway payments. Each one is confirmed by a status lookup at the gateway, never by the customer's return alone."
                />
                <FilterBar
                    searchParam={null}
                    filterKeys={['status', 'late']}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Statuses"
                                options={statuses}
                            />
                            <FilterSelect
                                name="late"
                                label="Late payments"
                                allLabel="All attempts"
                                options={LATE_OPTIONS}
                            />
                        </>
                    }
                />
                <DataTable
                    caption="Payment attempts"
                    columns={columns}
                    rows={attempts.data}
                    getRowKey={(attempt) => attempt.id}
                    empty={
                        <EmptyState
                            icon={Activity}
                            title="No hosted payments yet"
                            description="Payments started on hosted gateway pages appear here."
                        />
                    }
                    footer={
                        <Pagination paginator={attempts} itemLabel="attempts" />
                    }
                />
            </Page>
        </>
    );
}
