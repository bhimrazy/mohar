import { Link, router, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Check, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/OrderController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge, statusLabel } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { cycleLabel } from '@/lib/billing-cycles';
import type { Order, OrderItem } from '@/types/orders';

type FraudCheck = {
    id: number;
    provider: string;
    score: string;
    decision: string;
    reasons: string[];
    duration_ms: number;
    created_at: string | null;
};

type Props = {
    order: Order;
    invoice: { id: number; status: string | null; url: string | null } | null;
    fraud_checks: FraudCheck[];
    default_run_create: number[];
    client_url: string | null;
    placed_by_staff: string | null;
    can: {
        accept: boolean;
        cancel: boolean;
        mark_fraud: boolean;
        set_pending: boolean;
        delete: boolean;
        run_fraud_check: boolean;
    };
};

type Confirm = 'cancel' | 'fraud' | 'pending' | 'delete' | null;

const TYPE_LABELS: Record<OrderItem['type'], string> = {
    service: 'Service',
    addon: 'Addon',
    domain: 'Domain',
    upgrade: 'Upgrade',
};

function AcceptDialog({
    order,
    defaults,
    open,
    onOpenChange,
}: {
    order: Order;
    defaults: number[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [runCreate, setRunCreate] = useState<number[]>(defaults);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const items = (order.items ?? []).filter(
        (item) => item.status === 'pending' && item.type !== 'addon',
    );

    const accept = () =>
        router.post(
            OrderController.accept.url(order.id),
            { run_create: runCreate },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ?? 'Something went wrong.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !processing && onOpenChange(next)}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Accept order #{order.number}?</DialogTitle>
                    <DialogDescription>
                        The order becomes active. Choose which items to create
                        now; failures are reported but don't undo the
                        acceptance.
                    </DialogDescription>
                </DialogHeader>
                {items.length > 0 ? (
                    <ul className="grid gap-2">
                        {items.map((item) => (
                            <li key={item.id}>
                                <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm">
                                    <Checkbox
                                        checked={runCreate.includes(item.id)}
                                        onCheckedChange={(checked) =>
                                            setRunCreate((current) =>
                                                checked
                                                    ? [...current, item.id]
                                                    : current.filter(
                                                          (id) =>
                                                              id !== item.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            Run create: {item.description}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {TYPE_LABELS[item.type]}
                                            {item.auto_setup
                                                ? ` · auto-setup ${item.auto_setup}`
                                                : ''}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No pending items need provisioning.
                    </p>
                )}
                <InputError message={error ?? undefined} />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={accept} disabled={processing}>
                        {processing && <Spinner />}
                        Accept order
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function OrderShow({
    order,
    invoice,
    fraud_checks: fraudChecks,
    default_run_create: defaultRunCreate,
    client_url: clientUrl,
    placed_by_staff: placedByStaff,
    can,
}: Props) {
    const [accepting, setAccepting] = useState(false);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const items = order.items ?? [];

    const columns: DataTableColumn<OrderItem>[] = [
        {
            key: 'description',
            header: 'Item',
            primary: true,
            cell: (item) => (
                <span className={item.parent_id ? 'pl-4' : undefined}>
                    {item.description}
                    {item.type !== 'service' && (
                        <span className="ml-2 text-xs text-muted-foreground">
                            {TYPE_LABELS[item.type]}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'id',
            header: 'ID',
            hideOnMobile: true,
            cell: (item) => (
                <span className="font-mono text-xs text-muted-foreground">
                    {item.item_id ?? '—'}
                </span>
            ),
        },
        {
            key: 'cycle',
            header: 'Cycle',
            cell: (item) => (item.cycle ? cycleLabel(item.cycle) : '—'),
        },
        {
            key: 'first',
            header: 'First period',
            align: 'right',
            cell: (item) => (
                <Money amount={item.first_amount} currency={order.currency} />
            ),
        },
        {
            key: 'setup',
            header: 'Setup',
            align: 'right',
            hideOnMobile: true,
            cell: (item) =>
                item.setup_fee ? (
                    <Money amount={item.setup_fee} currency={order.currency} />
                ) : (
                    '—'
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
            key: 'recurring',
            header: 'Renews at',
            align: 'right',
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

    const confirmCopy = {
        cancel: {
            title: `Cancel order #${order.number}?`,
            description:
                'Pending items and the unpaid invoice are cancelled. Active services are terminated on the server by the Services module.',
            label: 'Cancel order',
            action: OrderController.cancel(order.id),
        },
        fraud: {
            title: `Mark order #${order.number} as fraud?`,
            description:
                'The order and its items are flagged, active items are terminated and the invoice is cancelled.',
            label: 'Mark as fraud',
            action: OrderController.markFraud(order.id),
        },
        pending: {
            title: `Set order #${order.number} back to pending?`,
            description:
                'The order and its fraud-flagged items return to pending and the invoice can be paid again.',
            label: 'Set to pending',
            action: OrderController.setPending(order.id),
        },
        delete: {
            title: `Delete order #${order.number}?`,
            description:
                "Pending items and the open invoice are cancelled and the order is removed. Orders with payments can't be deleted. This can't be undone.",
            label: 'Delete order',
            action: OrderController.destroy(order.id),
        },
    } as const;

    const hasMenu =
        can.cancel ||
        can.mark_fraud ||
        can.set_pending ||
        can.delete ||
        can.run_fraud_check;

    return (
        <>
            <Head title={`Order #${order.number}`} />
            <Page>
                <PageHeader
                    back={{
                        href: OrderController.index.url(),
                        label: 'Orders',
                    }}
                    title={`Order #${order.number}`}
                    status={order.status}
                    meta={
                        <>
                            {clientUrl && order.client ? (
                                <Link
                                    href={clientUrl}
                                    className="hover:text-foreground hover:underline"
                                >
                                    {order.client.display_name}
                                </Link>
                            ) : (
                                order.client?.display_name
                            )}
                            <span aria-hidden="true">·</span>
                            <span>
                                Placed{' '}
                                <DateTime
                                    value={order.created_at}
                                    format="datetime"
                                />
                            </span>
                        </>
                    }
                    actions={
                        <>
                            {hasMenu && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label="More actions"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {can.run_fraud_check && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    router.post(
                                                        OrderController.runFraudCheck.url(
                                                            order.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Re-run fraud check
                                            </DropdownMenuItem>
                                        )}
                                        {can.set_pending && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setConfirm('pending')
                                                }
                                            >
                                                Set back to pending
                                            </DropdownMenuItem>
                                        )}
                                        {can.cancel && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setConfirm('cancel')
                                                }
                                            >
                                                Cancel order
                                            </DropdownMenuItem>
                                        )}
                                        {(can.mark_fraud || can.delete) && (
                                            <DropdownMenuSeparator />
                                        )}
                                        {can.mark_fraud && (
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    setConfirm('fraud')
                                                }
                                            >
                                                Mark as fraud
                                            </DropdownMenuItem>
                                        )}
                                        {can.delete && (
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    setConfirm('delete')
                                                }
                                            >
                                                Delete order
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {invoice?.url && (
                                <Button asChild variant="outline">
                                    <a href={invoice.url}>View invoice</a>
                                </Button>
                            )}
                            {can.accept && (
                                <Button onClick={() => setAccepting(true)}>
                                    <Check /> Accept order
                                </Button>
                            )}
                        </>
                    }
                />

                <InputError message={errors?.order} />

                {can.accept && (
                    <AcceptDialog
                        order={order}
                        defaults={defaultRunCreate}
                        open={accepting}
                        onOpenChange={setAccepting}
                    />
                )}

                {confirm && (
                    <ConfirmDialog
                        open
                        onOpenChange={(open) => !open && setConfirm(null)}
                        title={confirmCopy[confirm].title}
                        description={confirmCopy[confirm].description}
                        confirmLabel={confirmCopy[confirm].label}
                        destructive={confirm !== 'pending'}
                        confirmText={
                            confirm === 'delete' ? order.number : undefined
                        }
                        action={confirmCopy[confirm].action}
                    />
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Total"
                        value={
                            <Money
                                amount={order.total}
                                currency={order.currency}
                            />
                        }
                        hint={order.tax_total ? 'Including tax' : 'No tax'}
                    />
                    <StatCard
                        label="Invoice"
                        value={
                            invoice?.status ? statusLabel(invoice.status) : '—'
                        }
                        hint={invoice ? `#${invoice.id}` : 'No invoice'}
                        href={invoice?.url ?? undefined}
                    />
                    <StatCard
                        label="Fraud check"
                        value={
                            order.fraud_decision
                                ? statusLabel(order.fraud_decision)
                                : 'Not run'
                        }
                        hint={
                            order.fraud_provider
                                ? `${order.fraud_provider} · score ${order.fraud_score ?? 0}`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Payment method"
                        value={order.payment_gateway_label ?? '—'}
                        hint={
                            order.promotion_code
                                ? `Promotion ${order.promotion_code}`
                                : 'No promotion'
                        }
                    />
                </div>

                <SectionCard title="Items" flush>
                    <DataTable
                        caption="Order items"
                        columns={columns}
                        rows={items}
                        getRowKey={(item) => item.id}
                        className="rounded-none border-0 shadow-none"
                        empty={<EmptyState title="This order has no items" />}
                    />
                </SectionCard>
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <SectionCard
                            title="Fraud checks"
                            description="Newest first. Re-run from the actions menu."
                        >
                            {fraudChecks.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No fraud check has run for this order.
                                </p>
                            ) : (
                                <ul className="grid gap-3">
                                    {fraudChecks.map((check) => (
                                        <li
                                            key={check.id}
                                            className="rounded-lg border p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="flex items-center gap-2">
                                                    <StatusBadge
                                                        status={check.decision}
                                                    />
                                                    <span className="text-muted-foreground">
                                                        {check.provider} · score{' '}
                                                        {check.score} ·{' '}
                                                        {check.duration_ms} ms
                                                    </span>
                                                </span>
                                                <DateTime
                                                    value={check.created_at}
                                                    relative
                                                    className="text-muted-foreground"
                                                />
                                            </div>
                                            {check.reasons.length > 0 && (
                                                <ul className="mt-2 list-disc pl-5 text-muted-foreground">
                                                    {check.reasons.map(
                                                        (reason) => (
                                                            <li key={reason}>
                                                                {reason}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>

                    <div className="flex flex-col gap-4">
                        <SectionCard title="Totals">
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
                                        label: 'Setup fees',
                                        value: (
                                            <Money
                                                amount={order.setup_total}
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
                                            />
                                        ),
                                    },
                                ]}
                            />
                        </SectionCard>
                        <SectionCard title="Details">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Client email',
                                        value: order.client?.email,
                                    },
                                    {
                                        label: 'IP address',
                                        value: order.ip_address,
                                    },
                                    {
                                        label: 'Referral code',
                                        value: order.referral_code,
                                    },
                                    {
                                        label: 'Placed by staff',
                                        value: placedByStaff,
                                    },
                                    {
                                        label: 'Accepted',
                                        value: order.accepted_at ? (
                                            <DateTime
                                                value={order.accepted_at}
                                                format="datetime"
                                            />
                                        ) : null,
                                    },
                                    {
                                        label: 'Cancelled',
                                        value: order.cancelled_at ? (
                                            <DateTime
                                                value={order.cancelled_at}
                                                format="datetime"
                                            />
                                        ) : null,
                                    },
                                    {
                                        label: 'Client notes',
                                        value: order.notes,
                                        full: true,
                                    },
                                    {
                                        label: 'Admin notes',
                                        value: order.admin_notes,
                                        full: true,
                                    },
                                ]}
                            />
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}
