import { Head } from '@inertiajs/react';
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
import { FormDialog } from '@/components/billing/form-dialog';
import { SelectField } from '@/components/billing/select-field';
import type { ClientOption, CreditEntry } from '@/components/billing/types';
import { humanize } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/CreditController';
import type { ResourcePaginated } from '@/types/app';

type Props = {
    client: { id: number; display_name: string; currency: string; balance: number } | null;
    entries: ResourcePaginated<CreditEntry>;
    clients: ClientOption[];
};

const ALL = 'all';

export default function CreditIndex({ client, entries, clients }: Props) {
    const { apply } = useQueryParams();

    const columns: DataTableColumn<CreditEntry>[] = [
        { key: 'date', header: 'Date', primary: true, cell: (entry) => <DateTime value={entry.created_at} format="datetime" /> },
        ...(client ? [] : [{ key: 'client', header: 'Client', cell: (entry: CreditEntry) => entry.client?.display_name } satisfies DataTableColumn<CreditEntry>]),
        { key: 'reason', header: 'Reason', cell: (entry) => humanize(entry.reason) },
        { key: 'description', header: 'Description', hideOnMobile: true, cell: (entry) => entry.description ?? '—' },
        { key: 'staff', header: 'By', hideOnMobile: true, cell: (entry) => entry.staff ?? 'System' },
        { key: 'amount', header: 'Amount', align: 'right', cell: (entry) => <Money amount={entry.amount} currency={entry.currency} signed tone="ledger" /> },
        { key: 'balance', header: 'Balance', align: 'right', hideOnMobile: true, cell: (entry) => <Money amount={entry.balance_after} currency={entry.currency} tone="ledger" /> },
    ];

    return (
        <>
            <Head title="Account credit" />
            <Page>
                <PageHeader
                    title="Account credit"
                    description="Credit balances come from overpayments, deposits, refunds to credit and manual adjustments."
                    actions={
                        <FormDialog
                            trigger={<Button>Adjust credit</Button>}
                            title="Adjust credit balance"
                            description="Manual adjustments are logged with your name."
                            submitLabel="Save adjustment"
                            action={store()}
                            initial={{ client_id: client ? String(client.id) : '', direction: 'add', amount: '', description: '' }}
                            transform={(data) => ({ ...data, client_id: Number(data.client_id) })}
                        >
                            {(form) => (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field htmlFor="credit-client" label="Client" error={form.errors.client_id} className="sm:col-span-2">
                                        <SelectField
                                            id="credit-client"
                                            value={form.data.client_id}
                                            onChange={(value) => form.setData('client_id', value)}
                                            options={clients.map((option) => ({ value: String(option.id), label: option.display_name }))}
                                            placeholder="Choose a client"
                                        />
                                    </Field>
                                    <Field htmlFor="credit-direction" label="Adjustment" error={form.errors.direction}>
                                        <SelectField
                                            id="credit-direction"
                                            value={form.data.direction}
                                            onChange={(value) => form.setData('direction', value)}
                                            options={[
                                                { value: 'add', label: 'Add credit' },
                                                { value: 'remove', label: 'Remove credit' },
                                            ]}
                                        />
                                    </Field>
                                    <Field htmlFor="credit-amount" label="Amount" error={form.errors.amount}>
                                        <Input id="credit-amount" inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} />
                                    </Field>
                                    <Field htmlFor="credit-description" label="Reason" error={form.errors.description} className="sm:col-span-2">
                                        <Input id="credit-description" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                                    </Field>
                                </div>
                            )}
                        </FormDialog>
                    }
                />
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <Field htmlFor="credit-filter" label="Client" className="w-full sm:w-80">
                        <SelectField
                            id="credit-filter"
                            value={client ? String(client.id) : ALL}
                            onChange={(value) => apply({ client: value === ALL ? null : value })}
                            options={[{ value: ALL, label: 'All clients' }, ...clients.map((option) => ({ value: String(option.id), label: option.display_name }))]}
                        />
                    </Field>
                    {client && (
                        <StatCard
                            className="sm:w-64"
                            label="Credit balance"
                            value={<Money amount={client.balance} currency={client.currency} tone="ledger" />}
                            icon={Wallet}
                        />
                    )}
                </div>
                <DataTable
                    caption="Credit ledger"
                    columns={columns}
                    rows={entries.data}
                    getRowKey={(entry) => entry.id}
                    empty={<EmptyState icon={Wallet} title="No credit movements yet" description="Overpayments, deposits and adjustments appear here." />}
                    footer={<Pagination paginator={entries} itemLabel="entries" />}
                />
            </Page>
        </>
    );
}
