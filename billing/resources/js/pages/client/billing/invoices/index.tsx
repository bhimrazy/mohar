import { Head, Link, router } from '@inertiajs/react';
import { Receipt, Wallet } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import type { Invoice } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as creditIndex } from '@/actions/App/Modules/Billing/Http/Controllers/Client/CreditController';
import { index, show } from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';
import { store as createBatch } from '@/actions/App/Modules/Billing/Http/Controllers/Client/PaymentBatchController';
import type { ResourcePaginated } from '@/types/app';

type Props = {
    invoices: ResourcePaginated<Invoice>;
    filters: { status?: string };
    summary: { unpaid_count: number; unpaid_total: number; overdue_count: number; currency: string; credit: number };
    mass_pay: boolean;
};

export default function ClientInvoices({ invoices, filters, summary, mass_pay }: Props) {
    const { apply } = useQueryParams();
    const tab = filters.status ?? 'all';

    const columns: DataTableColumn<Invoice>[] = [
        { key: 'number', header: 'Invoice', primary: true, cell: (invoice) => invoice.number },
        { key: 'issued', header: 'Date', hideOnMobile: true, cell: (invoice) => <DateTime value={invoice.issue_date} /> },
        { key: 'due', header: 'Due', cell: (invoice) => <DateTime value={invoice.due_date} /> },
        { key: 'status', header: 'Status', cell: (invoice) => <StatusBadge status={invoice.display_status} /> },
        { key: 'total', header: 'Total', align: 'right', cell: (invoice) => <Money amount={invoice.total} currency={invoice.currency} /> },
        {
            key: 'balance',
            header: 'Amount due',
            align: 'right',
            cell: (invoice) => (invoice.balance > 0 ? <Money amount={invoice.balance} currency={invoice.currency} /> : <span className="text-muted-foreground">—</span>),
        },
    ];

    return (
        <>
            <Head title="Invoices" />
            <Page>
                <PageHeader
                    title="Invoices"
                    description={
                        summary.unpaid_count > 0
                            ? `You have ${summary.unpaid_count} unpaid ${summary.unpaid_count === 1 ? 'invoice' : 'invoices'}.`
                            : 'You’re all paid up.'
                    }
                    actions={
                        mass_pay &&
                        summary.unpaid_count > 1 && (
                            <Button onClick={() => router.post(createBatch.url())}>
                                Pay all <Money amount={summary.unpaid_total} currency={summary.currency} />
                            </Button>
                        )
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <StatCard
                        label="Amount due"
                        value={<Money amount={summary.unpaid_total} currency={summary.currency} />}
                        hint={summary.overdue_count > 0 ? `${summary.overdue_count} overdue` : `${summary.unpaid_count} unpaid`}
                        icon={Receipt}
                    />
                    <StatCard label="Credit balance" value={<Money amount={summary.credit} currency={summary.currency} />} icon={Wallet} href={creditIndex()} />
                </div>

                <Tabs value={tab} onValueChange={(value) => apply({ status: value === 'all' ? null : value })}>
                    <TabsList>
                        <TabsTrigger value="all">All</TabsTrigger>
                        <TabsTrigger value="unpaid">Unpaid</TabsTrigger>
                        <TabsTrigger value="paid">Paid</TabsTrigger>
                    </TabsList>
                </Tabs>

                <DataTable
                    caption="Your invoices"
                    columns={columns}
                    rows={invoices.data}
                    getRowKey={(invoice) => invoice.id}
                    rowHref={(invoice) => show(invoice.id)}
                    empty={
                        <EmptyState
                            icon={Receipt}
                            title={tab === 'all' ? 'You don’t have any invoices yet.' : `No ${tab} invoices.`}
                            description={tab === 'all' ? 'Invoices for your orders and renewals will appear here.' : undefined}
                            action={
                                tab !== 'all' && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={index()}>Show all invoices</Link>
                                    </Button>
                                )
                            }
                        />
                    }
                    footer={<Pagination paginator={invoices} itemLabel="invoices" />}
                />
            </Page>
        </>
    );
}
