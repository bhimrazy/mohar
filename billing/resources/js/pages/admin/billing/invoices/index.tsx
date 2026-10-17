import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import type { GatewayOption, Invoice } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { bulk } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceActionController';
import {
    create,
    index,
    show,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import type { ResourcePaginated, SelectOption } from '@/types/app';

type Props = {
    invoices: ResourcePaginated<Invoice>;
    filters: Partial<
        Record<
            'search' | 'status' | 'client' | 'gateway' | 'from' | 'to',
            string
        >
    >;
    statuses: SelectOption[];
    gateways: GatewayOption[];
    client: { id: number; display_name: string } | null;
    can: { manage: boolean };
};

function DateFilter({ name, label }: { name: string; label: string }) {
    const { params, apply } = useQueryParams();

    return (
        <div className="flex items-center gap-2">
            <Label htmlFor={`filter-${name}`} className="text-muted-foreground">
                {label}
            </Label>
            <Input
                id={`filter-${name}`}
                type="date"
                className="w-full sm:w-40"
                value={params.get(name) ?? ''}
                onChange={(event) => apply({ [name]: event.target.value })}
            />
        </div>
    );
}

export default function InvoicesIndex({
    invoices,
    filters,
    statuses,
    gateways,
    client,
    can,
}: Props) {
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const { apply } = useQueryParams();
    const hasFilters = Object.keys(filters).length > 0;
    const pageIds = invoices.data.map((invoice) => invoice.id);
    const multiCurrency =
        new Set(invoices.data.map((invoice) => invoice.currency)).size > 1;
    const allSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

    const toggle = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked ? [...current, id] : current.filter((item) => item !== id),
        );

    const runBulk = (action: 'mark_paid' | 'cancel' | 'remind') =>
        router.visit(bulk.url(), {
            method: 'post',
            data: { action, ids: selected },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setSelected([]),
        });

    const columns: DataTableColumn<Invoice>[] = [
        ...(can.manage
            ? [
                  {
                      key: 'select',
                      header: (
                          <Checkbox
                              aria-label="Select all invoices on this page"
                              checked={allSelected}
                              onCheckedChange={(checked) =>
                                  setSelected(checked === true ? pageIds : [])
                              }
                          />
                      ),
                      className: 'w-8',
                      hideOnMobile: true,
                      cell: (invoice: Invoice) => (
                          <Checkbox
                              aria-label={`Select invoice ${invoice.number}`}
                              checked={selected.includes(invoice.id)}
                              onCheckedChange={(checked) =>
                                  toggle(invoice.id, checked === true)
                              }
                          />
                      ),
                  } satisfies DataTableColumn<Invoice>,
              ]
            : []),
        {
            key: 'number',
            header: 'Invoice',
            primary: true,
            cell: (invoice) => invoice.number,
        },
        {
            key: 'client',
            header: 'Client',
            cell: (invoice) => invoice.client?.display_name,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (invoice) => <StatusBadge status={invoice.display_status} />,
        },
        {
            key: 'issued',
            header: 'Issued',
            hideOnMobile: true,
            cell: (invoice) => <DateTime value={invoice.issue_date} />,
        },
        {
            key: 'due',
            header: 'Due',
            cell: (invoice) => <DateTime value={invoice.due_date} />,
        },
        {
            key: 'total',
            header: 'Total',
            align: 'right',
            cell: (invoice) => (
                <Money
                    amount={invoice.total}
                    currency={invoice.currency}
                    showCode={multiCurrency}
                />
            ),
        },
        {
            key: 'balance',
            header: 'Balance',
            align: 'right',
            hideOnMobile: true,
            cell: (invoice) => (
                <Money
                    amount={invoice.balance}
                    currency={invoice.currency}
                    tone="ledger"
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Invoices" />
            <Page>
                <PageHeader
                    title="Invoices"
                    description="All invoices across clients."
                    actions={
                        can.manage && (
                            <Button asChild>
                                <Link
                                    href={create({
                                        query: client
                                            ? { client: client.id }
                                            : {},
                                    })}
                                >
                                    <Plus />
                                    New invoice
                                </Link>
                            </Button>
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
                    searchPlaceholder="Search invoice number…"
                    filterKeys={['status', 'gateway', 'from', 'to']}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="All statuses"
                                options={statuses}
                            />
                            <FilterSelect
                                name="gateway"
                                label="Gateways"
                                options={gateways}
                            />
                            <DateFilter name="from" label="From" />
                            <DateFilter name="to" label="To" />
                        </>
                    }
                />

                {selected.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-card px-4 py-2 text-sm shadow-xs">
                        <span className="mr-auto text-muted-foreground">
                            {selected.length} selected
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() => runBulk('remind')}
                        >
                            Send reminder
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() => runBulk('mark_paid')}
                        >
                            Mark paid
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            disabled={processing}
                            onClick={() => runBulk('cancel')}
                        >
                            Cancel invoices
                        </Button>
                    </div>
                )}

                <DataTable
                    caption="Invoices"
                    columns={columns}
                    rows={invoices.data}
                    getRowKey={(invoice) => invoice.id}
                    rowHref={(invoice) => show(invoice.id)}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={FileText}
                                title="No invoices match these filters"
                                action={
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={index()}>
                                            Clear filters
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={FileText}
                                title="No invoices yet"
                                description="Invoices appear here once an order is placed or you create one manually."
                                action={
                                    can.manage && (
                                        <Button size="sm" asChild>
                                            <Link href={create()}>
                                                <Plus />
                                                Create invoice
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        )
                    }
                    footer={
                        <Pagination paginator={invoices} itemLabel="invoices" />
                    }
                />
            </Page>
        </>
    );
}
