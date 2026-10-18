import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Plus, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/OrderController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { useQueryParams } from '@/components/app/use-query-params';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge, statusLabel } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { ResourcePaginated, SelectOption } from '@/types/app';
import type { Order } from '@/types/orders';

type Props = {
    orders: ResourcePaginated<Order>;
    filters: Record<string, string | undefined>;
    statuses: string[];
    fraud_decisions: string[];
    gateways: SelectOption[];
    client: { id: number; display_name: string } | null;
    can: { create: boolean; accept: boolean; cancel: boolean; delete: boolean };
};

type BulkAction = 'accept' | 'cancel' | 'delete';

const BULK_COPY: Record<
    BulkAction,
    {
        title: string;
        description: string;
        confirm: string;
        destructive: boolean;
    }
> = {
    accept: {
        title: 'Accept the selected orders?',
        description:
            'Pending orders become active and items set to provision on accept are created. Orders in other statuses are skipped.',
        confirm: 'Accept orders',
        destructive: false,
    },
    cancel: {
        title: 'Cancel the selected orders?',
        description:
            'Pending items and unpaid invoices are cancelled; active services are terminated by the Services module.',
        confirm: 'Cancel orders',
        destructive: true,
    },
    delete: {
        title: 'Delete the selected orders?',
        description:
            "Orders with payments on their invoice are skipped. Deleted orders can't be restored.",
        confirm: 'Delete orders',
        destructive: true,
    },
};

function DateRange() {
    const { params, apply } = useQueryParams();

    return (
        <div className="flex items-center gap-2">
            <Input
                type="date"
                aria-label="Placed from"
                defaultValue={params.get('from') ?? ''}
                onChange={(event) => apply({ from: event.target.value })}
                className="w-full sm:w-40"
            />
            <span className="text-sm text-muted-foreground">to</span>
            <Input
                type="date"
                aria-label="Placed until"
                defaultValue={params.get('to') ?? ''}
                onChange={(event) => apply({ to: event.target.value })}
                className="w-full sm:w-40"
            />
        </div>
    );
}

export default function OrdersIndex({
    orders,
    statuses,
    fraud_decisions: fraudDecisions,
    gateways,
    client,
    can,
}: Props) {
    const { params } = useQueryParams();
    const [selected, setSelected] = useState<number[]>([]);
    const [bulk, setBulk] = useState<BulkAction | null>(null);
    const canBulk = can.accept || can.cancel || can.delete;
    const allSelected =
        orders.data.length > 0 && selected.length === orders.data.length;

    const toggle = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((value) => value !== id),
        );

    const columns: DataTableColumn<Order>[] = [
        ...(canBulk
            ? [
                  {
                      key: 'select',
                      header: (
                          <Checkbox
                              aria-label="Select all orders on this page"
                              checked={allSelected}
                              onCheckedChange={(checked) =>
                                  setSelected(
                                      checked
                                          ? orders.data.map((order) => order.id)
                                          : [],
                                  )
                              }
                          />
                      ),
                      cell: (order: Order) => (
                          <Checkbox
                              aria-label={`Select order ${order.number}`}
                              checked={selected.includes(order.id)}
                              onCheckedChange={(checked) =>
                                  toggle(order.id, checked === true)
                              }
                          />
                      ),
                      className: 'w-10',
                      hideOnMobile: true,
                  },
              ]
            : []),
        {
            key: 'number',
            header: 'Order',
            primary: true,
            cell: (order) => <span className="font-mono">#{order.number}</span>,
        },
        {
            key: 'client',
            header: 'Client',
            cell: (order) => order.client?.display_name ?? '—',
        },
        {
            key: 'date',
            header: 'Placed',
            cell: (order) => <DateTime value={order.created_at} />,
        },
        {
            key: 'items',
            header: 'Items',
            align: 'right',
            hideOnMobile: true,
            cell: (order) => order.items_count ?? 0,
        },
        {
            key: 'gateway',
            header: 'Gateway',
            hideOnMobile: true,
            cell: (order) => (
                <span className="text-muted-foreground">
                    {order.payment_gateway_label ?? '—'}
                </span>
            ),
        },
        {
            key: 'fraud',
            header: 'Fraud check',
            hideOnMobile: true,
            cell: (order) =>
                order.fraud_decision ? (
                    <StatusBadge status={order.fraud_decision} />
                ) : (
                    '—'
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

    const hasFilters = [
        'search',
        'status',
        'client',
        'gateway',
        'fraud',
        'from',
        'to',
    ].some((key) => params.get(key));

    return (
        <>
            <Head title="Orders" />
            <Page>
                <PageHeader
                    title="Orders"
                    description={
                        client
                            ? `Orders placed by ${client.display_name}`
                            : 'Every order across clients. Pending orders wait for review.'
                    }
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link
                                    href={OrderController.create.url({
                                        query: client
                                            ? { client: client.id }
                                            : {},
                                    })}
                                >
                                    <Plus /> New order
                                </Link>
                            </Button>
                        )
                    }
                />

                <FilterBar
                    searchPlaceholder="Search order number or client…"
                    filterKeys={[
                        'status',
                        'gateway',
                        'fraud',
                        'from',
                        'to',
                        'client',
                    ]}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Statuses"
                                options={statuses.map((status) => ({
                                    value: status,
                                    label: statusLabel(status),
                                }))}
                            />
                            <FilterSelect
                                name="fraud"
                                label="Fraud checks"
                                options={fraudDecisions.map((decision) => ({
                                    value: decision,
                                    label: statusLabel(decision),
                                }))}
                            />
                            <FilterSelect
                                name="gateway"
                                label="Gateways"
                                options={gateways}
                            />
                            <DateRange />
                        </>
                    }
                />

                {selected.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/40 px-4 py-2.5 text-sm">
                        <span className="mr-auto font-medium">
                            {selected.length} selected
                        </span>
                        {can.accept && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setBulk('accept')}
                            >
                                Accept
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setBulk('cancel')}
                            >
                                Cancel
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() => setBulk('delete')}
                            >
                                Delete
                            </Button>
                        )}
                    </div>
                )}

                {bulk && (
                    <ConfirmDialog
                        open
                        onOpenChange={(open) => !open && setBulk(null)}
                        title={BULK_COPY[bulk].title}
                        description={BULK_COPY[bulk].description}
                        confirmLabel={BULK_COPY[bulk].confirm}
                        destructive={BULK_COPY[bulk].destructive}
                        onConfirm={() =>
                            router.post(
                                OrderController.bulk.url(),
                                { action: bulk, ids: selected },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setSelected([]),
                                },
                            )
                        }
                    />
                )}

                <DataTable
                    caption="Orders"
                    columns={columns}
                    rows={orders.data}
                    getRowKey={(order) => order.id}
                    rowHref={(order) => OrderController.show.url(order.id)}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={ShoppingCart}
                                title="No orders match these filters"
                                action={
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={OrderController.index.url()}
                                        >
                                            Clear filters
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={ShoppingCart}
                                title="No orders yet"
                                description="Orders appear here when clients check out in the store."
                            />
                        )
                    }
                    footer={
                        <Pagination paginator={orders} itemLabel="orders" />
                    }
                />
            </Page>
        </>
    );
}
