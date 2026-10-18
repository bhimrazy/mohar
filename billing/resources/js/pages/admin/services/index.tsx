import { Head, Link } from '@inertiajs/react';
import { Server } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import { show as showClient } from '@/routes/admin/clients';
import type { ResourcePaginated, SelectOption } from '@/types/app';
import { cycleLabel } from '@/types/services';
import type { ServiceRow } from '@/types/services';

type Props = {
    services: ResourcePaginated<ServiceRow>;
    statuses: SelectOption[];
    products: SelectOption[];
    servers: SelectOption[];
    client: { id: number; display_name: string } | null;
};

const FILTER_KEYS = ['status', 'product', 'server', 'client', 'overdue'];

const columns: DataTableColumn<ServiceRow>[] = [
    {
        key: 'service',
        header: 'Service',
        primary: true,
        cell: (service) => (
            <span className="flex flex-col">
                <span>{service.domain ?? service.product_name}</span>
                <span className="text-xs font-normal text-muted-foreground">
                    #{service.id} · {service.product_name}
                </span>
            </span>
        ),
    },
    {
        key: 'client',
        header: 'Client',
        cell: (service) => service.client?.name ?? '—',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (service) => (
            <span className="flex flex-wrap items-center gap-1.5">
                <StatusBadge status={service.status} />
                {service.is_overdue && <StatusBadge status="overdue" />}
            </span>
        ),
    },
    {
        key: 'server',
        header: 'Server',
        hideOnMobile: true,
        cell: (service) =>
            service.server ? (
                <span className="text-muted-foreground">
                    {service.server.name}
                </span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        key: 'next_due',
        header: 'Next due',
        cell: (service) => <DateTime value={service.next_due_date} />,
    },
    {
        key: 'amount',
        header: 'Recurring',
        align: 'right',
        cell: (service) => (
            <span className="flex flex-col items-end">
                <Money
                    amount={service.recurring_amount}
                    currency={service.currency}
                />
                <span className="text-xs text-muted-foreground">
                    {cycleLabel(service.cycle)}
                </span>
            </span>
        ),
    },
];

export default function ServicesIndex({
    services,
    statuses,
    products,
    servers,
    client,
}: Props) {
    const { params, apply } = useQueryParams();
    const filtered =
        FILTER_KEYS.some((key) => params.get(key)) || !!params.get('search');

    return (
        <>
            <Head title="Services" />
            <Page>
                <PageHeader
                    title="Services"
                    description={
                        client ? (
                            <>
                                Services of{' '}
                                <Link
                                    href={showClient(client.id)}
                                    className="font-medium text-foreground hover:underline"
                                >
                                    {client.display_name}
                                </Link>
                            </>
                        ) : (
                            'Hosting accounts and other provisioned products across all clients.'
                        )
                    }
                />

                <FilterBar
                    searchPlaceholder="Search domain, username or IP…"
                    filterKeys={FILTER_KEYS}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="All statuses"
                                options={statuses}
                            />
                            <FilterSelect
                                name="product"
                                label="Products"
                                options={products}
                            />
                            <FilterSelect
                                name="server"
                                label="Servers"
                                options={servers}
                            />
                            <Button
                                variant={
                                    params.get('overdue')
                                        ? 'secondary'
                                        : 'outline'
                                }
                                size="sm"
                                aria-pressed={!!params.get('overdue')}
                                onClick={() =>
                                    apply({
                                        overdue: params.get('overdue')
                                            ? null
                                            : 1,
                                    })
                                }
                                className="w-fit"
                            >
                                Overdue only
                            </Button>
                        </>
                    }
                />

                <DataTable
                    caption="Services"
                    columns={columns}
                    rows={services.data}
                    getRowKey={(service) => service.id}
                    rowHref={(service) => ServiceController.show(service.id)}
                    empty={
                        <EmptyState
                            icon={Server}
                            title={
                                filtered
                                    ? 'No services match these filters'
                                    : 'No services yet'
                            }
                            description={
                                filtered
                                    ? 'Try a different status, product or server.'
                                    : 'Services appear here when clients order a product.'
                            }
                            action={
                                filtered ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            apply(
                                                Object.fromEntries(
                                                    [
                                                        ...FILTER_KEYS,
                                                        'search',
                                                    ].map((key) => [key, null]),
                                                ),
                                            )
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    }
                    footer={
                        <Pagination paginator={services} itemLabel="services" />
                    }
                />
            </Page>
        </>
    );
}
