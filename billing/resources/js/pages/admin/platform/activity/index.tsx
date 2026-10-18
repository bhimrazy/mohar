import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { History } from 'lucide-react';
import { useState } from 'react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import ActivityLogController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ActivityLogController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { useQueryParams } from '@/components/app/use-query-params';
import { Input } from '@/components/ui/input';
import type { LaravelPaginated } from '@/types';

type Entry = {
    id: number;
    description: string;
    event: string | null;
    actor_type: string | null;
    actor: { id: number; name: string } | null;
    impersonator: string | null;
    client: { id: number; name: string } | null;
    ip_address: string | null;
    properties: Record<string, unknown> | null;
    created_at: string | null;
};

type Props = {
    entries: LaravelPaginated<Entry>;
    filters: Record<string, string | number | undefined>;
    events: string[];
    staff: { id: number; name: string }[];
};

const ONLY = ['entries', 'filters'];

function InputFilter({
    name,
    label,
    type = 'text',
    placeholder,
    className = 'w-full sm:w-36',
}: {
    name: string;
    label: string;
    type?: 'text' | 'date';
    placeholder?: string;
    className?: string;
}) {
    const { params, apply } = useQueryParams();
    const current = params.get(name) ?? '';
    const [value, setValue] = useState(current);
    const commit = (next: string) => {
        if (next !== current) {
            apply({ [name]: next.trim() || null }, { only: ONLY });
        }
    };

    return (
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <span className="shrink-0">{label}</span>
            <Input
                type={type}
                value={type === 'date' ? current : value}
                placeholder={placeholder}
                onChange={(event) =>
                    type === 'date'
                        ? commit(event.target.value)
                        : setValue(event.target.value)
                }
                onBlur={() => type !== 'date' && commit(value)}
                onKeyDown={(event) => event.key === 'Enter' && commit(value)}
                className={className}
            />
        </label>
    );
}

const columns: DataTableColumn<Entry>[] = [
    {
        key: 'when',
        header: 'When',
        className: 'w-36',
        cell: (entry) => (
            <DateTime
                value={entry.created_at}
                relative
                className="text-muted-foreground"
            />
        ),
    },
    {
        key: 'description',
        header: 'Activity',
        primary: true,
        cell: (entry) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate">{entry.description}</span>
                {entry.event && (
                    <span className="font-mono text-xs font-normal text-muted-foreground">
                        {entry.event}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'actor',
        header: 'By',
        cell: (entry) => (
            <span className="flex flex-col">
                <span>
                    {entry.actor?.name ?? (
                        <span className="text-muted-foreground capitalize">
                            {entry.actor_type ?? 'system'}
                        </span>
                    )}
                </span>
                {entry.impersonator && (
                    <span className="text-xs text-muted-foreground">
                        via {entry.impersonator}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'client',
        header: 'Client',
        cell: (entry) =>
            entry.client ? (
                <Link
                    href={ClientController.show(entry.client.id)}
                    className="hover:underline"
                >
                    {entry.client.name}
                </Link>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        key: 'ip',
        header: 'IP',
        hideOnMobile: true,
        cell: (entry) => (
            <span className="font-mono text-xs text-muted-foreground">
                {entry.ip_address ?? '—'}
            </span>
        ),
    },
];

export default function ActivityIndex({
    entries,
    filters,
    events,
    staff,
}: Props) {
    const filtered = Object.values(filters).some(
        (value) => value !== undefined && value !== null && value !== '',
    );

    return (
        <>
            <Head title="Activity log" />
            <Page>
                <PageHeader
                    title="Activity log"
                    description="A read-only audit trail of staff, client and system actions."
                />

                <FilterBar
                    searchPlaceholder="Search activity…"
                    filterKeys={['event', 'user', 'client', 'ip', 'from', 'to']}
                    only={ONLY}
                    filters={
                        <>
                            <FilterSelect
                                name="event"
                                label="Events"
                                allLabel="All events"
                                only={ONLY}
                                options={events.map((event) => ({
                                    value: event,
                                    label: event,
                                }))}
                            />
                            <FilterSelect
                                name="user"
                                label="Staff"
                                allLabel="Anyone"
                                only={ONLY}
                                options={staff.map((member) => ({
                                    value: String(member.id),
                                    label: member.name,
                                }))}
                            />
                            <InputFilter
                                name="client"
                                label="Client #"
                                placeholder="ID"
                                className="w-full sm:w-20"
                            />
                            <InputFilter
                                name="ip"
                                label="IP"
                                placeholder="203.0.113.5"
                            />
                            <InputFilter name="from" label="From" type="date" />
                            <InputFilter name="to" label="To" type="date" />
                        </>
                    }
                />

                <DataTable
                    caption="Activity log"
                    columns={columns}
                    rows={entries.data}
                    getRowKey={(entry) => entry.id}
                    empty={
                        <EmptyState
                            icon={History}
                            title={
                                filtered
                                    ? 'No activity matches these filters'
                                    : 'No activity yet'
                            }
                            description={
                                filtered
                                    ? 'Try a wider date range or clear the filters.'
                                    : 'Sign-ins, changes and automation runs are recorded here.'
                            }
                        />
                    }
                    footer={
                        <Pagination
                            paginator={entries}
                            itemLabel="entries"
                            only={ONLY}
                        />
                    }
                />
            </Page>
        </>
    );
}

ActivityIndex.layout = {
    breadcrumbs: [
        { title: 'Activity log', href: ActivityLogController.index() },
    ],
};
