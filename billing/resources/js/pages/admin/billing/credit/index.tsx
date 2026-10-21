import { router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Wallet } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatCard } from '@/components/app/stat-card';
import { useQueryParams } from '@/components/app/use-query-params';
import {
    ClientPicker,
    ClientPickerField,
} from '@/components/billing/client-picker';
import { FormDialog } from '@/components/billing/form-dialog';
import { SelectField } from '@/components/billing/select-field';
import type { CreditEntry } from '@/components/billing/types';
import { humanize } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    preferences,
    store,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/CreditController';
import type { ResourcePaginated } from '@/types/app';

type Props = {
    can: { adjust: boolean };
    client: {
        id: number;
        display_name: string;
        currency: string;
        balance: number;
        separate_invoices: boolean | null;
    } | null;
    entries: ResourcePaginated<CreditEntry>;
    owing: boolean;
};

export default function CreditIndex({ client, entries, can, owing }: Props) {
    const { apply } = useQueryParams();

    const columns: DataTableColumn<CreditEntry>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (entry) => (
                <DateTime value={entry.created_at} format="datetime" />
            ),
        },
        ...(client
            ? []
            : [
                  {
                      key: 'client',
                      header: 'Client',
                      cell: (entry: CreditEntry) => entry.client?.display_name,
                  } satisfies DataTableColumn<CreditEntry>,
              ]),
        {
            key: 'reason',
            header: 'Reason',
            cell: (entry) => humanize(entry.reason),
        },
        {
            key: 'description',
            header: 'Description',
            hideOnMobile: true,
            cell: (entry) => entry.description ?? '—',
        },
        {
            key: 'staff',
            header: 'By',
            hideOnMobile: true,
            cell: (entry) => entry.staff ?? 'System',
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (entry) => (
                <Money
                    amount={entry.amount}
                    currency={entry.currency}
                    signed
                    tone="ledger"
                />
            ),
        },
        {
            key: 'balance',
            header: 'Balance',
            align: 'right',
            hideOnMobile: true,
            cell: (entry) => (
                <Money
                    amount={entry.balance_after}
                    currency={entry.currency}
                    tone="ledger"
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Account credit" />
            <Page>
                <PageHeader
                    title="Account credit"
                    description="Credit balances come from overpayments, deposits, refunds to credit and manual adjustments."
                    actions={
                        can.adjust && (
                            <FormDialog
                                trigger={<Button>Adjust credit</Button>}
                                title="Adjust credit balance"
                                description="Manual adjustments are logged with your name."
                                submitLabel="Save adjustment"
                                action={store()}
                                moneyFields={['amount']}
                                initial={{
                                    client_id: client ? String(client.id) : '',
                                    direction: 'add',
                                    amount: '',
                                    description: '',
                                }}
                                transform={(data) => ({
                                    ...data,
                                    client_id: Number(data.client_id),
                                })}
                            >
                                {(form) => (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            htmlFor="credit-client"
                                            label="Client"
                                            error={form.errors.client_id}
                                            className="content-start sm:col-span-2"
                                        >
                                            <ClientPickerField
                                                id="credit-client"
                                                initial={client}
                                                invalid={
                                                    !!form.errors.client_id
                                                }
                                                onSelect={(option) =>
                                                    form.setData(
                                                        'client_id',
                                                        option
                                                            ? String(option.id)
                                                            : '',
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            className="content-start"
                                            htmlFor="credit-direction"
                                            label="Adjustment"
                                            error={form.errors.direction}
                                        >
                                            <SelectField
                                                id="credit-direction"
                                                value={form.data.direction}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'direction',
                                                        value,
                                                    )
                                                }
                                                options={[
                                                    {
                                                        value: 'add',
                                                        label: 'Add credit',
                                                    },
                                                    {
                                                        value: 'remove',
                                                        label: 'Remove credit',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        <Field
                                            className="content-start"
                                            htmlFor="credit-amount"
                                            label="Amount"
                                            error={form.errors.amount}
                                        >
                                            <Input
                                                id="credit-amount"
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
                                            htmlFor="credit-description"
                                            label="Reason"
                                            error={form.errors.description}
                                            className="content-start sm:col-span-2"
                                        >
                                            <Input
                                                id="credit-description"
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
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <Field
                        htmlFor="credit-filter"
                        label="Client"
                        className="w-full content-start sm:w-80"
                    >
                        <ClientPicker
                            id="credit-filter"
                            value={client}
                            onChange={(option) =>
                                apply({ client: option ? option.id : null })
                            }
                            placeholder="All clients — search to filter"
                        />
                    </Field>
                    <Field
                        htmlFor="credit-view"
                        label="Show"
                        className="w-full content-start sm:w-64"
                    >
                        <SelectField
                            id="credit-view"
                            value={owing ? 'owing' : 'all'}
                            onChange={(value) =>
                                apply({ owing: value === 'owing' ? 1 : null })
                            }
                            options={[
                                { value: 'all', label: 'All movements' },
                                {
                                    value: 'owing',
                                    label: 'Negative balances only',
                                },
                            ]}
                        />
                    </Field>
                    {client && (
                        <>
                            <StatCard
                                className="sm:w-64"
                                label="Credit balance"
                                value={
                                    <Money
                                        amount={client.balance}
                                        currency={client.currency}
                                        tone="ledger"
                                    />
                                }
                                icon={Wallet}
                            />
                            <Field
                                htmlFor="separate-invoices"
                                label="Renewal invoices"
                                className="w-full content-start sm:w-64"
                            >
                                <SelectField
                                    id="separate-invoices"
                                    value={
                                        client.separate_invoices === null
                                            ? 'default'
                                            : client.separate_invoices
                                              ? 'separate'
                                              : 'grouped'
                                    }
                                    onChange={(value) =>
                                        router.put(
                                            preferences.url(client.id),
                                            {
                                                separate_invoices:
                                                    value === 'default'
                                                        ? null
                                                        : value === 'separate',
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'default',
                                            label: 'Use the default',
                                        },
                                        {
                                            value: 'grouped',
                                            label: 'One invoice per due date',
                                        },
                                        {
                                            value: 'separate',
                                            label: 'One invoice per item',
                                        },
                                    ]}
                                />
                            </Field>
                        </>
                    )}
                </div>
                <DataTable
                    caption="Credit ledger"
                    columns={columns}
                    rows={entries.data}
                    getRowKey={(entry) => entry.id}
                    empty={
                        owing ? (
                            <EmptyState
                                icon={Wallet}
                                title="No negative balances"
                                description="A client owes credit when a chargeback or refund takes back credit they already spent."
                            />
                        ) : (
                            <EmptyState
                                icon={Wallet}
                                title="No credit movements yet"
                                description="Overpayments, deposits and adjustments appear here."
                            />
                        )
                    }
                    footer={
                        <Pagination paginator={entries} itemLabel="entries" />
                    }
                />
            </Page>
        </>
    );
}
