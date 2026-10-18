import { Head, Link } from '@inertiajs/react';
import { LifeBuoy, Plus } from 'lucide-react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Client/TicketController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { LaravelPaginated } from '@/types/app';
import type { TicketListItem } from '@/types/support';

const STATES = [
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'all', label: 'All' },
];

const columns: DataTableColumn<TicketListItem>[] = [
    {
        key: 'subject',
        header: 'Subject',
        primary: true,
        cell: (ticket) => (
            <span className="flex min-w-0 items-baseline gap-2">
                <span className="truncate">{ticket.subject}</span>
                <span className="shrink-0 text-xs font-normal text-muted-foreground">
                    #{ticket.number}
                </span>
            </span>
        ),
    },
    {
        key: 'department',
        header: 'Department',
        cell: (ticket) => ticket.department,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (ticket) => (
            <StatusBadge
                status={ticket.status}
                label={ticket.status === 'answered' ? 'Answered' : undefined}
            />
        ),
    },
    {
        key: 'updated',
        header: 'Last update',
        align: 'right',
        cell: (ticket) => <DateTime value={ticket.last_reply_at} relative />,
    },
];

export default function ClientTickets({
    tickets,
    filters,
}: {
    tickets: LaravelPaginated<TicketListItem>;
    filters: { search?: string; state: string };
}) {
    const { hrefWith } = useQueryParams();
    const openTicket = (
        <Button asChild>
            <Link href={TicketController.create()}>
                <Plus />
                Open a ticket
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Support tickets" />
            <Page>
                <PageHeader
                    title="Support tickets"
                    description="Questions and problems you've sent to our team."
                    actions={openTicket}
                />
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <nav
                        aria-label="Ticket state"
                        className="flex w-fit gap-1 rounded-lg border p-1"
                    >
                        {STATES.map((state) => (
                            <Link
                                key={state.value}
                                href={hrefWith({
                                    state:
                                        state.value === 'open'
                                            ? null
                                            : state.value,
                                    page: null,
                                })}
                                aria-current={
                                    filters.state === state.value
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'rounded-md px-3 py-1 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                    filters.state === state.value
                                        ? 'bg-muted font-medium'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {state.label}
                            </Link>
                        ))}
                    </nav>
                    <FilterBar
                        searchPlaceholder="Search your tickets…"
                        className="sm:w-80"
                    />
                </div>
                <DataTable
                    caption="Your tickets"
                    columns={columns}
                    rows={tickets.data}
                    getRowKey={(ticket) => ticket.number}
                    rowHref={(ticket) => TicketController.show(ticket.number)}
                    empty={
                        <EmptyState
                            icon={LifeBuoy}
                            title={
                                filters.search
                                    ? 'No tickets match your search'
                                    : filters.state === 'closed'
                                      ? "You don't have any closed tickets."
                                      : "You don't have any open tickets."
                            }
                            description="Need help with a service, an invoice or your account? Our team is happy to help."
                            action={openTicket}
                        />
                    }
                    footer={
                        tickets.total > 0 ? (
                            <Pagination
                                paginator={tickets}
                                itemLabel="tickets"
                            />
                        ) : undefined
                    }
                />
            </Page>
        </>
    );
}
