import { Head, Link } from '@inertiajs/react';
import { ClipboardList, Plus } from 'lucide-react';
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
import type { Quote } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import {
    create,
    index,
    show,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/QuoteController';
import type { ResourcePaginated, SelectOption } from '@/types/app';

type Props = {
    quotes: ResourcePaginated<Quote>;
    filters: Record<string, string>;
    stages: SelectOption[];
    can: { manage: boolean };
};

export default function QuotesIndex({ quotes, filters, stages, can }: Props) {
    const hasFilters = Object.keys(filters).length > 0;

    const columns: DataTableColumn<Quote>[] = [
        {
            key: 'number',
            header: 'Quote',
            primary: true,
            cell: (quote) => quote.number,
        },
        { key: 'subject', header: 'Subject', cell: (quote) => quote.subject },
        {
            key: 'client',
            header: 'Client',
            hideOnMobile: true,
            cell: (quote) => quote.client?.display_name,
        },
        {
            key: 'stage',
            header: 'Stage',
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
                    description="Proposals clients can accept online. Accepted quotes become invoices."
                    actions={
                        can.manage && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    New quote
                                </Link>
                            </Button>
                        )
                    }
                />
                <FilterBar
                    searchPlaceholder="Search number or subject…"
                    filterKeys={['stage']}
                    filters={
                        <FilterSelect
                            name="stage"
                            label="Stages"
                            options={stages}
                        />
                    }
                />
                <DataTable
                    caption="Quotes"
                    columns={columns}
                    rows={quotes.data}
                    getRowKey={(quote) => quote.id}
                    rowHref={(quote) => show(quote.id)}
                    empty={
                        <EmptyState
                            icon={ClipboardList}
                            title={
                                hasFilters
                                    ? 'No quotes match these filters'
                                    : 'No quotes yet'
                            }
                            description={
                                hasFilters
                                    ? undefined
                                    : 'Create a quote to send a priced proposal to a client.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={index()}>
                                            Clear filters
                                        </Link>
                                    </Button>
                                ) : (
                                    can.manage && (
                                        <Button size="sm" asChild>
                                            <Link href={create()}>
                                                <Plus />
                                                Create quote
                                            </Link>
                                        </Button>
                                    )
                                )
                            }
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
