import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, Plus, X } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { FormDialog } from '@/components/billing/form-dialog';
import { SelectField } from '@/components/billing/select-field';
import type {
    ClientOption,
    GatewayOption,
    Transaction,
} from '@/components/billing/types';
import { humanize } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import {
    index,
    store,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TransactionController';
import type { ResourcePaginated, SelectOption } from '@/types/app';

type Props = {
    transactions: ResourcePaginated<Transaction>;
    filters: Record<string, string>;
    types: SelectOption[];
    gateways: GatewayOption[];
    client: (ClientOption & { currency: string }) | null;
    clients: ClientOption[];
    can: { manage: boolean; refunds: boolean };
};

export default function TransactionsIndex({
    transactions,
    filters,
    types,
    gateways,
    client,
    clients,
    can,
}: Props) {
    const { apply } = useQueryParams();
    const hasFilters = Object.keys(filters).length > 0;

    const columns: DataTableColumn<Transaction>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (txn) => (
                <DateTime value={txn.occurred_at} format="datetime" />
            ),
        },
        {
            key: 'client',
            header: 'Client',
            cell: (txn) => txn.client?.display_name,
        },
        { key: 'type', header: 'Type', cell: (txn) => humanize(txn.type) },
        {
            key: 'invoice',
            header: 'Invoice',
            hideOnMobile: true,
            cell: (txn) =>
                txn.invoice_id ? (
                    <Link
                        href={showInvoice(txn.invoice_id)}
                        className="hover:underline"
                    >
                        {txn.invoice_number ?? `#${txn.invoice_id}`}
                    </Link>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'gateway',
            header: 'Gateway',
            hideOnMobile: true,
            cell: (txn) =>
                gateways.find((gateway) => gateway.value === txn.gateway)
                    ?.label ?? humanize(txn.gateway),
        },
        {
            key: 'reference',
            header: 'Reference',
            hideOnMobile: true,
            cell: (txn) => (
                <span className="font-mono text-xs">
                    {txn.gateway_txn_id ?? '—'}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            hideOnMobile: true,
            cell: (txn) => (
                <StatusBadge
                    status={
                        txn.status === 'succeeded' ? 'completed' : txn.status
                    }
                    label={humanize(txn.status)}
                />
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (txn) => (
                <Money
                    amount={
                        txn.amount_out > 0 ? -txn.amount_out : txn.amount_in
                    }
                    currency={txn.currency}
                    tone="ledger"
                    signed
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Transactions" />
            <Page>
                <PageHeader
                    title="Transactions"
                    description="Payments, refunds, chargebacks and manual entries. Refund a payment from its invoice."
                    actions={
                        can.manage && (
                            <FormDialog
                                trigger={
                                    <Button>
                                        <Plus />
                                        Add transaction
                                    </Button>
                                }
                                title="Add manual transaction"
                                description="Income is applied to the listed invoice IDs in order; any remainder becomes account credit."
                                submitLabel="Add transaction"
                                action={store()}
                                initial={{
                                    client_id: client ? String(client.id) : '',
                                    type: 'manual_income',
                                    amount: '',
                                    fee: '',
                                    gateway: 'bank_transfer',
                                    gateway_txn_id: '',
                                    description: '',
                                    occurred_at: new Date()
                                        .toISOString()
                                        .slice(0, 10),
                                    invoice_ids_text: '',
                                }}
                                transform={(data) => ({
                                    ...data,
                                    client_id: Number(data.client_id),
                                    invoice_ids: data.invoice_ids_text
                                        .split(',')
                                        .map((value) => Number(value.trim()))
                                        .filter(
                                            (value) =>
                                                Number.isInteger(value) &&
                                                value > 0,
                                        ),
                                })}
                            >
                                {(form) => (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            htmlFor="txn-client"
                                            label="Client"
                                            error={form.errors.client_id}
                                            className="sm:col-span-2"
                                        >
                                            <SelectField
                                                id="txn-client"
                                                value={form.data.client_id}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'client_id',
                                                        value,
                                                    )
                                                }
                                                options={clients.map(
                                                    (option) => ({
                                                        value: String(
                                                            option.id,
                                                        ),
                                                        label: option.display_name,
                                                    }),
                                                )}
                                                placeholder="Choose a client"
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-type"
                                            label="Type"
                                            error={form.errors.type}
                                        >
                                            <SelectField
                                                id="txn-type"
                                                value={form.data.type}
                                                onChange={(value) =>
                                                    form.setData('type', value)
                                                }
                                                options={[
                                                    {
                                                        value: 'manual_income',
                                                        label: 'Income',
                                                    },
                                                    {
                                                        value: 'manual_expense',
                                                        label: 'Expense',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-amount"
                                            label="Amount"
                                            error={form.errors.amount}
                                        >
                                            <Input
                                                id="txn-amount"
                                                inputMode="decimal"
                                                value={form.data.amount}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'amount',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-gateway"
                                            label="Gateway"
                                            error={form.errors.gateway}
                                        >
                                            <SelectField
                                                id="txn-gateway"
                                                value={form.data.gateway}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'gateway',
                                                        value,
                                                    )
                                                }
                                                options={gateways}
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-date"
                                            label="Date"
                                            error={form.errors.occurred_at}
                                        >
                                            <Input
                                                id="txn-date"
                                                type="date"
                                                value={form.data.occurred_at}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'occurred_at',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-reference"
                                            label="Transaction ID"
                                            optional
                                            error={form.errors.gateway_txn_id}
                                        >
                                            <Input
                                                id="txn-reference"
                                                value={form.data.gateway_txn_id}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'gateway_txn_id',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-fee"
                                            label="Fee"
                                            optional
                                            error={form.errors.fee}
                                        >
                                            <Input
                                                id="txn-fee"
                                                inputMode="decimal"
                                                value={form.data.fee}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'fee',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-invoices"
                                            label="Apply to invoice IDs"
                                            optional
                                            hint="Comma-separated, e.g. 1042, 1043"
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="txn-invoices"
                                                value={
                                                    form.data.invoice_ids_text
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'invoice_ids_text',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="txn-description"
                                            label="Description"
                                            optional
                                            error={form.errors.description}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="txn-description"
                                                value={form.data.description}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'description',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                )}
                            </FormDialog>
                        )
                    }
                />

                {client && (
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Client:</span>
                        <span className="font-medium">
                            {client.display_name}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => apply({ client: null })}
                            aria-label="Show all clients"
                        >
                            <X />
                        </Button>
                    </div>
                )}

                <FilterBar
                    searchPlaceholder="Search reference or description…"
                    filterKeys={['type', 'gateway']}
                    filters={
                        <>
                            <FilterSelect
                                name="type"
                                label="Types"
                                options={types}
                            />
                            <FilterSelect
                                name="gateway"
                                label="Gateways"
                                options={gateways}
                            />
                        </>
                    }
                />

                <DataTable
                    caption="Transactions"
                    columns={columns}
                    rows={transactions.data}
                    getRowKey={(txn) => txn.id}
                    rowHref={(txn) =>
                        txn.invoice_id ? showInvoice(txn.invoice_id) : undefined
                    }
                    empty={
                        <EmptyState
                            icon={ArrowLeftRight}
                            title={
                                hasFilters
                                    ? 'No transactions match these filters'
                                    : 'No transactions yet'
                            }
                            description={
                                hasFilters
                                    ? undefined
                                    : 'Payments appear here as soon as they are recorded.'
                            }
                            action={
                                hasFilters && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={index()}>
                                            Clear filters
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    }
                    footer={
                        <Pagination
                            paginator={transactions}
                            itemLabel="transactions"
                        />
                    }
                />
            </Page>
        </>
    );
}
