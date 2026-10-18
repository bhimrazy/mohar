import { Head, Link } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import ClientOrderController from '@/actions/App/Modules/Orders/Http/Controllers/Client/OrderController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import store from '@/routes/store';
import type { ResourcePaginated } from '@/types/app';
import type { Order } from '@/types/orders';

type Props = {
    orders: ResourcePaginated<Order>;
    invoice_urls: Record<number, string | null>;
};

export default function ClientOrders({
    orders,
    invoice_urls: invoiceUrls,
}: Props) {
    const columns: DataTableColumn<Order>[] = [
        {
            key: 'number',
            header: 'Order',
            primary: true,
            cell: (order) => `#${order.number}`,
        },
        {
            key: 'date',
            header: 'Date',
            cell: (order) => <DateTime value={order.created_at} />,
        },
        {
            key: 'items',
            header: 'Items',
            hideOnMobile: true,
            cell: (order) => (
                <span className="text-muted-foreground">
                    {(order.items ?? [])
                        .filter((item) => !item.parent_id)
                        .map((item) => item.description)
                        .join(', ') || '—'}
                </span>
            ),
        },
        {
            key: 'total',
            header: 'Total',
            align: 'right',
            cell: (order) => (
                <Money amount={order.total} currency={order.currency} />
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (order) => <StatusBadge status={order.status} />,
        },
    ];

    return (
        <>
            <Head title="Order history" />
            <Page>
                <PageHeader
                    title="Order history"
                    description="Everything you've ordered and the invoice for each order."
                    actions={
                        <Button asChild>
                            <Link href={store.index.url()}>
                                Order something new
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Your orders"
                    columns={columns}
                    rows={orders.data}
                    getRowKey={(order) => order.id}
                    rowHref={(order) =>
                        ClientOrderController.show.url(order.id)
                    }
                    empty={
                        <EmptyState
                            icon={ShoppingCart}
                            title="You haven't placed any orders yet"
                            description="Orders you place in the store appear here with their invoices."
                            action={
                                <Button asChild>
                                    <Link href={store.index.url()}>
                                        Browse the store
                                    </Link>
                                </Button>
                            }
                        />
                    }
                    footer={
                        orders.meta.total > 0 ? (
                            <Pagination paginator={orders} itemLabel="orders" />
                        ) : undefined
                    }
                />
                {Object.values(invoiceUrls).some(Boolean) && (
                    <p className="text-sm text-muted-foreground">
                        Open an order to view or pay its invoice.
                    </p>
                )}
            </Page>
        </>
    );
}
