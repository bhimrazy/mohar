import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Globe, Search } from 'lucide-react';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Client/DomainController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { search } from '@/routes/domains';
import type { ResourcePaginated } from '@/types/app';
import { expiryHint } from '@/types/domains';
import type { Domain } from '@/types/domains';

type Props = {
    domains: ResourcePaginated<Domain>;
    filters: { search?: string; status?: string };
    expiringSoon: number;
};

const columns: DataTableColumn<Domain>[] = [
    {
        key: 'name',
        header: 'Domain',
        primary: true,
        cell: (domain) => domain.name_unicode,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (domain) => <StatusBadge status={domain.status} />,
    },
    {
        key: 'expiry',
        header: 'Expires',
        cell: (domain) =>
            domain.expiry_date ? (
                <span className="flex flex-col">
                    <DateTime value={domain.expiry_date} />
                    {domain.days_until_expiry !== null &&
                        domain.days_until_expiry <= 60 && (
                            <span className="text-xs text-muted-foreground">
                                {expiryHint(domain.days_until_expiry)}
                            </span>
                        )}
                </span>
            ) : (
                <span className="text-muted-foreground">
                    {domain.type === 'transfer'
                        ? 'After the transfer'
                        : 'After registration'}
                </span>
            ),
    },
    {
        key: 'auto_renew',
        header: 'Auto-renew',
        cell: (domain) => (
            <Badge variant={domain.auto_renew ? 'secondary' : 'outline'}>
                {domain.auto_renew ? 'On' : 'Off'}
            </Badge>
        ),
    },
];

export default function MyDomains({ domains, filters, expiringSoon }: Props) {
    const filtered = Boolean(filters.search || filters.status);

    return (
        <>
            <Head title="My domains" />
            <Page>
                <PageHeader
                    title="My domains"
                    description="Manage nameservers, renewals and transfer settings for your domains."
                    actions={
                        <Button asChild>
                            <Link href={search()}>
                                <Search />
                                Register a domain
                            </Link>
                        </Button>
                    }
                />

                {expiringSoon > 0 && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>
                            {expiringSoon === 1
                                ? '1 domain expires'
                                : `${expiringSoon} domains expire`}{' '}
                            in the next 30 days
                        </AlertTitle>
                        <AlertDescription>
                            Domains with auto-renew on are renewed when their
                            renewal invoice is paid.
                        </AlertDescription>
                    </Alert>
                )}

                {(domains.meta.total > 0 || filtered) && (
                    <FilterBar searchPlaceholder="Search your domains…" />
                )}

                <DataTable
                    caption="My domains"
                    columns={columns}
                    rows={domains.data}
                    getRowKey={(domain) => domain.id}
                    rowHref={(domain) => DomainController.show(domain.id)}
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Globe}
                                title="No domains match your search"
                            />
                        ) : (
                            <EmptyState
                                icon={Globe}
                                title="You don't have any domains yet"
                                description="Find a name for your website and register it in a few minutes."
                                action={
                                    <Button size="sm" asChild>
                                        <Link href={search()}>
                                            Register a domain
                                        </Link>
                                    </Button>
                                }
                            />
                        )
                    }
                    footer={
                        domains.meta.last_page > 1 ? (
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
