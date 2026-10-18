import { Head, useForm } from '@inertiajs/react';
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
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import type { CreditEntry, Transaction } from '@/components/billing/types';
import { humanize, normalizeMoney } from '@/components/billing/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { addFunds } from '@/actions/App/Modules/Billing/Http/Controllers/Client/CreditController';
import type { ResourcePaginated } from '@/types/app';

type Props = {
    balance: number;
    currency: string;
    entries: ResourcePaginated<CreditEntry>;
    transactions: ResourcePaginated<Transaction>;
    add_funds: {
        unavailable_reason: string | null;
        min: number;
        max: number;
        max_balance: number;
    };
};

export default function ClientCredit({
    balance,
    currency,
    entries,
    transactions,
    add_funds,
}: Props) {
    const form = useForm({ amount: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const entryColumns: DataTableColumn<CreditEntry>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (entry) => <DateTime value={entry.created_at} />,
        },
        {
            key: 'description',
            header: 'Description',
            cell: (entry) => entry.description ?? humanize(entry.reason),
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
                <Money amount={entry.balance_after} currency={entry.currency} />
            ),
        },
    ];

    const transactionColumns: DataTableColumn<Transaction>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (txn) => <DateTime value={txn.occurred_at} />,
        },
        {
            key: 'invoice',
            header: 'Invoice',
            cell: (txn) => txn.invoice_number ?? '—',
        },
        { key: 'type', header: 'Type', cell: (txn) => humanize(txn.type) },
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
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Credit & funds" />
            <Page>
                <PageHeader
                    title="Credit & funds"
                    description="Credit is used for your invoices automatically or whenever you choose to apply it."
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <StatCard
                        label="Credit balance"
                        value={<Money amount={balance} currency={currency} />}
                        icon={Wallet}
                    />
                    <SectionCard title="Add funds" className="lg:col-span-2">
                        {add_funds.unavailable_reason ? (
                            <p className="text-sm text-muted-foreground">
                                {add_funds.unavailable_reason}
                            </p>
                        ) : (
                            <form
                                className="grid gap-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.transform((data) => ({
                                        amount: normalizeMoney(data.amount),
                                    }));
                                    form.submit(addFunds());
                                }}
                            >
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <Field
                                        htmlFor="add-funds-amount"
                                        label={`Amount (${currency})`}
                                        hint={
                                            <>
                                                Between{' '}
                                                <Money
                                                    amount={add_funds.min}
                                                    currency={currency}
                                                />{' '}
                                                and{' '}
                                                <Money
                                                    amount={add_funds.max}
                                                    currency={currency}
                                                />
                                                .
                                            </>
                                        }
                                        error={errors.amount}
                                        className="flex-1"
                                    >
                                        <Input
                                            id="add-funds-amount"
                                            inputMode="decimal"
                                            placeholder="50.00"
                                            value={form.data.amount}
                                            onChange={(event) =>
                                                form.setData(
                                                    'amount',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={!!errors.amount}
                                            aria-describedby="add-funds-amount-description"
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        className="sm:mt-[1.375rem]"
                                    >
                                        {form.processing && <Spinner />}
                                        Add funds
                                    </Button>
                                </div>
                                <InputError message={errors.billing} />
                            </form>
                        )}
                    </SectionCard>
                </div>
                <SectionCard title="Credit history" flush>
                    <DataTable
                        caption="Credit history"
                        columns={entryColumns}
                        rows={entries.data}
                        getRowKey={(entry) => entry.id}
                        className="rounded-none border-0 shadow-none"
                        empty={
                            <EmptyState
                                title="No credit activity yet."
                                description="Overpayments, deposits and refunds to credit appear here."
                            />
                        }
                        footer={
                            entries.meta.last_page > 1 ? (
                                <Pagination
                                    paginator={entries}
                                    pageName="credit_page"
                                    itemLabel="entries"
                                />
                            ) : undefined
                        }
                    />
                </SectionCard>
                <SectionCard title="Transactions" flush>
                    <DataTable
                        caption="Transactions"
                        columns={transactionColumns}
                        rows={transactions.data}
                        getRowKey={(txn) => txn.id}
                        className="rounded-none border-0 shadow-none"
                        empty={<EmptyState title="No payments yet." />}
                        footer={
                            transactions.meta.last_page > 1 ? (
                                <Pagination
                                    paginator={transactions}
                                    pageName="transactions_page"
                                    itemLabel="transactions"
                                />
                            ) : undefined
                        }
                    />
                </SectionCard>
            </Page>
        </>
    );
}
