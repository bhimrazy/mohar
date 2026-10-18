import { Head } from '@/components/app/head';
import { ClipboardList } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import type { Quote } from '@/components/billing/types';
import { show } from '@/actions/App/Modules/Billing/Http/Controllers/Client/QuoteController';
import type { ResourcePaginated } from '@/types/app';

export default function ClientQuotes({
    quotes,
}: {
    quotes: ResourcePaginated<Quote>;
}) {
    const columns: DataTableColumn<Quote>[] = [
        {
            key: 'number',
            header: 'Quote',
            primary: true,
            cell: (quote) => quote.number,
        },
        { key: 'subject', header: 'Subject', cell: (quote) => quote.subject },
        {
            key: 'stage',
            header: 'Status',
            cell: (quote) => <StatusBadge status={quote.stage} />,
        },
        {
            key: 'valid',
            header: 'Valid until',
            hideOnMobile: true,
            cell: (quote) => <DateTime value={quote.valid_until} />,
        },
        {
            key: 'total',
            header: 'Total',
            align: 'right',
            cell: (quote) => (
                <Money amount={quote.total} currency={quote.currency} />
            ),
        },
    ];

    return (
        <>
            <Head title="Quotes" />
            <Page>
                <PageHeader
                    title="Quotes"
                    description="Proposals we’ve prepared for you."
                />
                <DataTable
                    caption="Your quotes"
                    columns={columns}
                    rows={quotes.data}
                    getRowKey={(quote) => quote.id}
                    rowHref={(quote) => show(quote.id)}
                    empty={
                        <EmptyState
                            icon={ClipboardList}
                            title="You don’t have any quotes."
                            description="Quotes we send you will appear here."
                        />
                    }
                    footer={
                        <Pagination paginator={quotes} itemLabel="quotes" />
                    }
                />
            </Page>
        </>
    );
}
