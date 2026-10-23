import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Activity } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormDialog } from '@/components/billing/form-dialog';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
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
    resolve,
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
    can_resolve: boolean;
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

/**
 * Staff settle a payment in review by hand, after looking in the gateway's own portal: it was paid, or it was not.
 */
function ResolveButtons({ attempt }: { attempt: Attempt }) {
    const name = humanize(attempt.gateway);
    const amount = formatMoney(attempt.amount, attempt.currency);

    return (
        <>
            <FormDialog
                trigger={
                    <Button variant="outline" size="sm">
                        Mark as paid
                    </Button>
                }
                title={`Record this ${amount} payment as paid?`}
                description={`Only after you saw the full ${amount} payment in the ${name} portal. It is added to the invoice once, and can't be undone.`}
                submitLabel="Record payment"
                action={resolve(attempt.id)}
                initial={{ outcome: 'paid', provider_reference: '' }}
            >
                {(form) => (
                    <Field
                        htmlFor={`attempt-ref-${attempt.id}`}
                        label={`${name} reference`}
                        hint="The transaction code the portal shows for this payment."
                        error={form.errors.provider_reference}
                    >
                        <Input
                            id={`attempt-ref-${attempt.id}`}
                            value={form.data.provider_reference}
                            onChange={(event) =>
                                form.setData(
                                    'provider_reference',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </Field>
                )}
            </FormDialog>
            <ConfirmDialog
                trigger={
                    <Button variant="outline" size="sm">
                        Not paid
                    </Button>
                }
                title={`Close this ${amount} payment as not paid?`}
                description={`Use only after you checked the ${name} portal and found no payment of ${amount}. The invoice can then be paid again, so a wrong choice can mean the client pays twice.`}
                confirmLabel="Close as not paid"
                method="post"
                action={resolve(attempt.id)}
                data={{ outcome: 'not_paid' }}
            />
        </>
    );
}

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
                <div className="flex flex-wrap justify-end gap-1">
                    {attempt.needs_check && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => post(lateChecked.url(attempt.id))}
                        >
                            Mark checked
                        </Button>
                    )}
                    {attempt.can_resolve && (
                        <ResolveButtons attempt={attempt} />
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
