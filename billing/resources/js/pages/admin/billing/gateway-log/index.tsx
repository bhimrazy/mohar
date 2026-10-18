import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Webhook } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { humanize } from '@/components/billing/types';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import type { LaravelPaginated, SelectOption } from '@/types/app';

type Entry = {
    id: number;
    gateway: string;
    direction: string;
    invoice_id: number | null;
    result: string;
    payload: Record<string, unknown> | null;
    created_at: string | null;
};

type Props = {
    entries: LaravelPaginated<Entry>;
    filters: Record<string, string>;
    gateways: SelectOption[];
};

export default function GatewayLogIndex({ entries, gateways }: Props) {
    const columns: DataTableColumn<Entry>[] = [
        {
            key: 'date',
            header: 'Time',
            primary: true,
            cell: (entry) => (
                <DateTime value={entry.created_at} format="datetime" />
            ),
        },
        {
            key: 'gateway',
            header: 'Gateway',
            cell: (entry) => humanize(entry.gateway),
        },
        {
            key: 'direction',
            header: 'Direction',
            cell: (entry) => humanize(entry.direction),
        },
        { key: 'result', header: 'Result', cell: (entry) => entry.result },
        {
            key: 'invoice',
            header: 'Invoice',
            hideOnMobile: true,
            cell: (entry) =>
                entry.invoice_id ? (
                    <Link
                        href={showInvoice(entry.invoice_id)}
                        className="hover:underline"
                    >
                        #{entry.invoice_id}
                    </Link>
                ) : (
                    '—'
                ),
        },
        {
            key: 'payload',
            header: 'Payload (redacted)',
            hideOnMobile: true,
            className: 'max-w-md',
            cell: (entry) => (
                <code
                    className="block truncate text-xs text-muted-foreground"
                    title={JSON.stringify(entry.payload)}
                >
                    {entry.payload ? JSON.stringify(entry.payload) : '—'}
                </code>
            ),
        },
    ];

    return (
        <>
            <Head title="Gateway log" />
            <Page>
                <PageHeader
                    title="Gateway log"
                    description="Requests to and callbacks from payment gateways. Sensitive values are redacted."
                />
                <FilterBar
                    searchParam={null}
                    filterKeys={['gateway', 'direction']}
                    filters={
                        <>
                            <FilterSelect
                                name="gateway"
                                label="Gateways"
                                options={gateways}
                            />
                            <FilterSelect
                                name="direction"
                                label="Directions"
                                options={[
                                    { value: 'callback', label: 'Callback' },
                                    { value: 'request', label: 'Request' },
                                    { value: 'response', label: 'Response' },
                                ]}
                            />
                        </>
                    }
                />
                <DataTable
                    caption="Gateway log"
                    columns={columns}
                    rows={entries.data}
                    getRowKey={(entry) => entry.id}
                    empty={
                        <EmptyState
                            icon={Webhook}
                            title="Nothing logged yet"
                            description="Gateway calls and callbacks appear here."
                        />
                    }
                    footer={
                        <Pagination paginator={entries} itemLabel="entries" />
                    }
                />
            </Page>
        </>
    );
}
