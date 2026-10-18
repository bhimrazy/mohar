import { Head } from '@/components/app/head';
import { FileText } from 'lucide-react';
import ClientOrderController from '@/actions/App/Modules/Orders/Http/Controllers/Client/OrderController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { cycleLabel } from '@/lib/billing-cycles';
import type { Order, OrderItem } from '@/types/orders';

type Props = {
    order: Order;
    invoice_url: string | null;
};

export default function ClientOrderShow({
    order,
    invoice_url: invoiceUrl,
}: Props) {
    const columns: DataTableColumn<OrderItem>[] = [
        {
            key: 'description',
            header: 'Item',
            primary: true,
            cell: (item) => item.description,
        },
        {
            key: 'cycle',
            header: 'Billing',
            cell: (item) => (item.cycle ? cycleLabel(item.cycle) : '—'),
        },
        {
            key: 'amount',
            header: 'First payment',
            align: 'right',
            cell: (item) => (
                <Money
                    amount={item.first_amount + item.setup_fee}
                    currency={order.currency}
                />
            ),
        },
        {
            key: 'discount',
            header: 'Discount',
            align: 'right',
            hideOnMobile: true,
            cell: (item) =>
                item.discount_amount ? (
                    <Money
                        amount={-item.discount_amount}
                        currency={order.currency}
                    />
                ) : (
                    '—'
                ),
        },
        {
            key: 'renews',
            header: 'Renews at',
            align: 'right',
            hideOnMobile: true,
            cell: (item) => (
                <Money
                    amount={item.recurring_amount}
                    currency={order.currency}
                />
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (item) => <StatusBadge status={item.status} />,
        },
    ];

    return (
        <>
            <Head title={`Order #${order.number}`} />
            <Page>
                <PageHeader
                    back={{
                        href: ClientOrderController.index.url(),
                        label: 'Order history',
                    }}
                    title={`Order #${order.number}`}
                    status={order.status}
                    meta={
                        <>
                            Placed <DateTime value={order.created_at} />
                        </>
                    }
                    actions={
                        invoiceUrl && (
                            <Button asChild>
                                <a href={invoiceUrl}>
                                    <FileText /> View invoice
                                </a>
                            </Button>
                        )
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <SectionCard title="Items" flush className="lg:col-span-2">
                        <DataTable
                            caption="Order items"
                            columns={columns}
                            rows={order.items ?? []}
                            getRowKey={(item) => item.id}
                            className="rounded-none border-0 shadow-none"
                        />
                    </SectionCard>
                    <SectionCard title="Summary">
                        <DescriptionList
                            columns={1}
                            items={[
                                {
                                    label: 'Subtotal',
                                    value: (
                                        <Money
                                            amount={order.subtotal}
                                            currency={order.currency}
                                        />
                                    ),
                                },
                                {
                                    label: 'Discount',
                                    value: order.discount_total ? (
                                        <Money
                                            amount={-order.discount_total}
                                            currency={order.currency}
                                        />
                                    ) : null,
                                },
                                {
                                    label: 'Tax',
                                    value: (
                                        <Money
                                            amount={order.tax_total}
                                            currency={order.currency}
                                        />
                                    ),
                                },
                                {
                                    label: 'Total',
                                    value: (
                                        <Money
                                            amount={order.total}
                                            currency={order.currency}
                                            showCode
                                        />
                                    ),
                                },
                                {
                                    label: 'Payment method',
                                    value: order.payment_gateway_label,
                                },
                                { label: 'Your notes', value: order.notes },
                            ]}
                        />
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}
