import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { ChevronRight, Server } from 'lucide-react';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Client/ServiceController';
import type { ResourcePaginated } from '@/types/app';
import { cycleLabel } from '@/types/services';
import type { ServiceRow } from '@/types/services';

type Props = {
    services: ResourcePaginated<ServiceRow>;
    filters: { status?: string };
};

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'pending', label: 'Pending' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'terminated', label: 'Terminated' },
];

export default function ClientServicesIndex({ services, filters }: Props) {
    const suspended = services.data.filter(
        (service) => service.status === 'suspended',
    );
    const attention = suspended.length;
    // Only an overdue invoice is cleared by paying; a service suspended for another reason needs support.
    const overdue = suspended.filter(
        (service) => service.suspension_kind === 'overdue',
    ).length;

    return (
        <>
            <Head title="My services" />
            <Page>
                <PageHeader
                    title="My services"
                    description="Your hosting plans and other services."
                />

                {attention > 0 && (
                    <div className="rounded-xl border border-red-600/20 bg-red-500/5 p-4 text-sm">
                        <p className="font-medium">
                            {attention === 1
                                ? '1 service is suspended.'
                                : `${attention} services are suspended.`}
                        </p>
                        <p className="text-muted-foreground">
                            {overdue === attention
                                ? 'Pay the outstanding invoice to restore it automatically, or contact support.'
                                : overdue === 0
                                  ? 'Contact support to restore it.'
                                  : 'Pay the outstanding invoice to restore the overdue ones automatically. For the others, contact support.'}
                        </p>
                    </div>
                )}

                <FilterBar
                    searchParam={null}
                    filterKeys={['status']}
                    filters={
                        <FilterSelect
                            name="status"
                            label="Status"
                            allLabel="All services"
                            options={STATUS_OPTIONS}
                        />
                    }
                />

                {services.data.length === 0 ? (
                    <EmptyState
                        variant="bordered"
                        icon={Server}
                        title={
                            filters.status
                                ? 'No services with this status'
                                : "You don't have any services yet."
                        }
                        description={
                            filters.status
                                ? 'Choose another status to see more services.'
                                : 'Services you order appear here once the order is placed.'
                        }
                    />
                ) : (
                    <ul className="grid gap-3">
                        {services.data.map((service) => (
                            <li key={service.id}>
                                <Link
                                    href={ServiceController.show(service.id)}
                                    className="flex items-center gap-4 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:p-5"
                                >
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/50 text-muted-foreground">
                                        <Server
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate font-medium">
                                                {service.domain ??
                                                    service.product_name}
                                            </span>
                                            <StatusBadge
                                                status={service.status}
                                            />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {service.product_name}
                                            {service.next_due_date &&
                                                [
                                                    'active',
                                                    'suspended',
                                                ].includes(service.status) && (
                                                    <>
                                                        {' · '}
                                                        {service.do_not_renew
                                                            ? 'Ends'
                                                            : 'Renews'}{' '}
                                                        <DateTime
                                                            value={
                                                                service.next_due_date
                                                            }
                                                        />
                                                    </>
                                                )}
                                        </p>
                                    </div>
                                    <div className="hidden text-right sm:block">
                                        <Money
                                            amount={service.recurring_amount}
                                            currency={service.currency}
                                            className="font-medium"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {cycleLabel(service.cycle)}
                                        </p>
                                    </div>
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {services.meta.last_page > 1 && (
                    <Pagination paginator={services} itemLabel="services" />
                )}
            </Page>
        </>
    );
}
