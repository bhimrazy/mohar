import { Head } from '@/components/app/head';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { PayPanel } from '@/components/billing/pay-panel';
import type { Invoice, PaymentOptions } from '@/components/billing/types';
import { index } from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';
import { pay } from '@/actions/App/Modules/Billing/Http/Controllers/Client/PaymentBatchController';

type Props = {
    batch: { id: number; total: number; currency: string; status: string };
    invoices: Invoice[];
    payment: PaymentOptions | null;
};

export default function PaymentBatchShow({ batch, invoices, payment }: Props) {
    const columns: DataTableColumn<Invoice>[] = [
        {
            key: 'number',
            header: 'Invoice',
            primary: true,
            cell: (invoice) => invoice.number,
        },
        {
            key: 'due',
            header: 'Due',
            cell: (invoice) => <DateTime value={invoice.due_date} />,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (invoice) => <StatusBadge status={invoice.display_status} />,
        },
        {
            key: 'balance',
            header: 'Amount',
            align: 'right',
            cell: (invoice) => (
                <Money amount={invoice.balance} currency={invoice.currency} />
            ),
        },
    ];

    return (
        <>
            <Head title="Pay invoices" />
            <Page size="narrow">
                <PageHeader
                    back={{ href: index(), label: 'Invoices' }}
                    title="Pay invoices"
                    description="One payment for all selected invoices, applied oldest first."
                />
                <SectionCard title={`${invoices.length} invoices`} flush>
                    <DataTable
                        caption="Invoices in this payment"
                        columns={columns}
                        rows={invoices}
                        getRowKey={(invoice) => invoice.id}
                        className="rounded-none border-0 shadow-none"
                    />
                </SectionCard>
                {payment ? (
                    <SectionCard
                        title="Payment"
                        description={
                            <>
                                Total:{' '}
                                <Money
                                    amount={batch.total}
                                    currency={batch.currency}
                                />
                            </>
                        }
                    >
                        <PayPanel
                            options={{
                                ...payment,
                                gateways: payment.gateways.filter(
                                    (gateway) => gateway.kind === 'card',
                                ),
                            }}
                            amount={batch.total}
                            currency={batch.currency}
                            action={pay(batch.id)}
                        />
                    </SectionCard>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        This payment has already been processed.
                    </p>
                )}
            </Page>
        </>
    );
}
