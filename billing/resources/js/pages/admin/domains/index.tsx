import { Head, Link } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge, statusLabel } from '@/components/app/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import TldController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/TldController';
import type { ResourcePaginated } from '@/types/app';
import { expiryHint } from '@/types/domains';
import type { Domain } from '@/types/domains';

type Props = {
    domains: ResourcePaginated<Domain>;
    filters: Partial<
        Record<
            'search' | 'status' | 'registrar' | 'tld' | 'expiring' | 'client',
            string
        >
    >;
    statuses: string[];
    registrars: string[];
    registrarNames: Record<string, string>;
    tlds: string[];
};

const FILTER_KEYS = ['status', 'registrar', 'tld', 'expiring', 'client'];

const columns = (
    registrarNames: Record<string, string>,
): DataTableColumn<Domain>[] => [
    {
        key: 'name',
        header: 'Domain',
        primary: true,
        cell: (domain) => (
            <span className="inline-flex items-center gap-2">
                {domain.name_unicode}
                {domain.type === 'transfer' &&
                    domain.status.startsWith('pending') && (
                        <Badge variant="outline">Transfer</Badge>
                    )}
            </span>
        ),
    },
    {
        key: 'client',
        header: 'Client',
        cell: (domain) => domain.client?.display_name ?? '—',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (domain) => <StatusBadge status={domain.status} />,
    },
    {
        key: 'registrar',
        header: 'Registrar',
        hideOnMobile: true,
        cell: (domain) =>
            domain.registrar_key ? (
                (registrarNames[domain.registrar_key] ?? domain.registrar_key)
            ) : (
                <span className="text-muted-foreground">Manual</span>
            ),
    },
    {
        key: 'expiry',
        header: 'Expires',
        cell: (domain) => (
            <span className="flex flex-col">
                <DateTime value={domain.expiry_date} />
                {domain.days_until_expiry !== null &&
                    domain.days_until_expiry <= 30 && (
                        <span className="text-xs text-muted-foreground">
                            {expiryHint(domain.days_until_expiry)}
                        </span>
                    )}
            </span>
        ),
    },
    {
        key: 'auto_renew',
        header: 'Auto-renew',
        hideOnMobile: true,
        cell: (domain) => (domain.auto_renew ? 'On' : 'Off'),
    },
    {
        key: 'amount',
        header: 'Renewal',
        align: 'right',
        cell: (domain) => (
            <Money
                amount={domain.recurring_amount}
                currency={domain.currency}
            />
        ),
    },
];

export default function DomainsIndex({
    domains,
    filters,
    statuses,
    registrars,
    registrarNames,
    tlds,
}: Props) {
    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <>
            <Head title="Domains" />
            <Page>
                <PageHeader
                    title="Domains"
                    description="Registrations, transfers and renewals across all clients."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={TldController.index()}>
                                TLD pricing
                            </Link>
                        </Button>
                    }
                />

                <FilterBar
                    searchPlaceholder="Search domains…"
                    filterKeys={FILTER_KEYS}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="All statuses"
                                options={statuses.map((status) => ({
                                    value: status,
                                    label: statusLabel(status),
                                }))}
                            />
                            <FilterSelect
                                name="registrar"
                                label="Registrars"
                                options={[
                                    ...registrars.map((registrar) => ({
                                        value: registrar,
                                        label:
                                            registrarNames[registrar] ??
                                            registrar,
                                    })),
                                    { value: 'manual', label: 'Manual' },
                                ]}
                            />
                            <FilterSelect
                                name="tld"
                                label="Extensions"
                                options={tlds.map((tld) => ({
                                    value: tld,
                                    label: tld,
                                }))}
                            />
                            <FilterSelect
                                name="expiring"
                                label="Expiring"
                                allLabel="Any expiry"
                                options={[
                                    { value: '7', label: 'Within 7 days' },
                                    { value: '30', label: 'Within 30 days' },
                                    { value: '90', label: 'Within 90 days' },
                                ]}
                            />
                        </>
                    }
                />

                <DataTable
                    caption="Domains"
                    columns={columns(registrarNames)}
                    rows={domains.data}
                    getRowKey={(domain) => domain.id}
                    rowHref={(domain) => DomainController.show(domain.id)}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Globe}
                                title="No domains match these filters"
                                description="Try a different status, registrar or search term."
                                action={
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={DomainController.index()}>
                                            Clear filters
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Globe}
                                title="No domains yet"
                                description="Domains appear here when clients register or transfer them through an order."
                            />
                        )
                    }
                    footer={
                        domains.meta.total > 0 ? (
                            <Pagination
                                paginator={domains}
                                itemLabel="domains"
                            />
                        ) : undefined
                    }
                />
            </Page>
        </>
    );
}
