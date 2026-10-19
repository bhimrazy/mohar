import {
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    CircleAlert,
    Link2,
    ServerCrash,
    Sparkles,
    UserRoundSearch,
    Users,
} from 'lucide-react';
import { useEffect } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar } from '@/components/app/filter-bar';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import { show as showClient } from '@/routes/admin/clients';
import type { LaravelPaginated } from '@/types/app';

type Category = 'proposed' | 'unmatched' | 'reseller' | 'linked';
type Tab = 'all' | Category | 'missing';

type LinkedService = { id: number; label: string; client: string };

type AccountRow = {
    username: string;
    domain: string | null;
    plan: string | null;
    owner: string | null;
    email: string | null;
    suspended: boolean;
    suspend_reason: string | null;
    category: Category;
    client: { id: number; name: string } | null;
    product: { id: number; name: string } | null;
    note: string | null;
    service: LinkedService | null;
    differences: string[];
};

type MissingRow = {
    username: string;
    domain: string | null;
    status: string;
    service: LinkedService;
};

type Props = {
    server: { id: number; name: string; hostname: string };
    supported: boolean;
    import: {
        status: 'fetching' | 'matching' | 'ready' | 'failed';
        error: string | null;
        account_count: number;
        requested_by: string | null;
        requested_at: string | null;
        fetched_at: string | null;
        expires_at: string;
        running: boolean;
    } | null;
    counts: Record<Tab, number> | null;
    rows: LaravelPaginated<AccountRow | MissingRow>;
    filters: { tab: Tab; search: string };
    createProductUrl: string | null;
};

const TABS: { value: Tab; label: string }[] = [
    { value: 'all', label: 'All accounts' },
    { value: 'proposed', label: 'Proposed' },
    { value: 'unmatched', label: 'Unmatched' },
    { value: 'reseller', label: 'Reseller-owned' },
    { value: 'linked', label: 'Already linked' },
    { value: 'missing', label: 'Missing on server' },
];

const CATEGORY_LABELS: Record<Category, string> = {
    proposed: 'Proposed',
    unmatched: 'Unmatched',
    reseller: 'Reseller-owned',
    linked: 'Already linked',
};

const CATEGORY_TONES = {
    proposed: 'success',
    unmatched: 'warning',
    reseller: 'neutral',
    linked: 'info',
} as const;

const plural = (count: number, noun: string) =>
    `${count} ${noun}${count === 1 ? '' : 's'}`;

/** A card hint that wraps on narrow screens instead of being cut off. */
function Wrap({ children }: { children: React.ReactNode }) {
    return <span className="whitespace-normal">{children}</span>;
}

function Muted({ children }: { children: React.ReactNode }) {
    return (
        <span className="text-xs font-normal text-muted-foreground">
            {children}
        </span>
    );
}

function Account({
    row,
}: {
    row: { username: string; domain: string | null };
}) {
    return (
        <span className="flex flex-col">
            <span className="font-mono">{row.username}</span>
            <Muted>{row.domain ?? 'No domain'}</Muted>
        </span>
    );
}

function ServiceLink({ service }: { service: LinkedService }) {
    return (
        <span className="flex min-w-0 flex-col whitespace-normal">
            <Link
                href={ServiceController.show(service.id)}
                className="hover:underline"
            >
                {service.label}
            </Link>
            <Muted>{service.client}</Muted>
        </span>
    );
}

const accountColumns = (
    createProductUrl: string | null,
): DataTableColumn<AccountRow>[] => [
    {
        key: 'account',
        header: 'Account',
        primary: true,
        cell: (row) => (
            <span className="flex flex-col items-start gap-1">
                <Account row={row} />
                {row.suspended && (
                    <StatusBadge
                        status="suspended"
                        label={
                            row.suspend_reason
                                ? `Suspended: ${row.suspend_reason}`
                                : 'Suspended'
                        }
                    />
                )}
            </span>
        ),
    },
    {
        key: 'server',
        header: 'On the server',
        cell: (row) => (
            <span className="flex flex-col break-words whitespace-normal sm:max-w-56">
                <span>{row.plan ?? 'No package'}</span>
                <Muted>{row.email ?? 'No contact email'}</Muted>
                {row.owner && <Muted>WHM owner: {row.owner}</Muted>}
            </span>
        ),
    },
    {
        key: 'match',
        header: 'Match here',
        cell: (row) => {
            if (row.service) {
                return <ServiceLink service={row.service} />;
            }

            return (
                <span className="flex flex-col whitespace-normal sm:min-w-44">
                    {row.client ? (
                        <Link
                            href={showClient(row.client.id)}
                            className="hover:underline"
                        >
                            {row.client.name}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">No client</span>
                    )}
                    <Muted>{row.product?.name ?? 'No product'}</Muted>
                </span>
            );
        },
    },
    {
        key: 'result',
        header: 'Result',
        cell: (row) => (
            <span className="flex flex-col items-start gap-1 break-words whitespace-normal sm:max-w-xs sm:min-w-56">
                <StatusBadge
                    status={row.category}
                    label={CATEGORY_LABELS[row.category]}
                    tone={CATEGORY_TONES[row.category]}
                />
                {row.category === 'linked' && (
                    <Muted>
                        {row.differences.length > 0
                            ? row.differences.join(' ')
                            : 'Matches the service. It is not imported again.'}
                    </Muted>
                )}
                {row.note && <Muted>{row.note}</Muted>}
                {createProductUrl &&
                    row.plan &&
                    !row.product &&
                    row.category === 'unmatched' && (
                        <a
                            href={createProductUrl}
                            className="text-xs text-primary hover:underline"
                        >
                            Create a product for package {row.plan}
                        </a>
                    )}
            </span>
        ),
    },
];

const missingColumns: DataTableColumn<MissingRow>[] = [
    {
        key: 'account',
        header: 'Account',
        primary: true,
        cell: (row) => <Account row={row} />,
    },
    {
        key: 'service',
        header: 'Service here',
        cell: (row) => <ServiceLink service={row.service} />,
    },
    {
        key: 'status',
        header: 'Status here',
        cell: (row) => <StatusBadge status={row.status} />,
    },
    {
        key: 'next',
        header: 'What to do',
        cell: (row) => (
            <span className="block max-w-xs text-sm whitespace-normal text-muted-foreground">
                If the account is gone,{' '}
                <Link
                    href={ServiceController.show(row.service.id)}
                    className="text-primary hover:underline"
                >
                    cancel or terminate the service
                </Link>{' '}
                here.
            </span>
        ),
    },
];

export default function ServerImport({
    server,
    supported,
    import: fetched,
    counts,
    rows,
    filters,
    createProductUrl,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const { apply } = useQueryParams();
    const running = fetched?.running ?? false;
    const matching = fetched?.status === 'matching';
    const stalled = (fetched?.status === 'fetching' || matching) && !running;
    const ready = fetched?.status === 'ready' && counts !== null;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Servers', href: ServerController.index().url },
            { title: server.name, href: ServerController.edit(server.id).url },
        ],
    });

    // Poll only while a fetch runs; stop as soon as the list (or the failure) arrives.
    const { start, stop } = usePoll(
        3000,
        { only: ['import', 'counts', 'rows'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    const fetchButton = supported && (
        <Button
            onClick={() => post(ServerImportController.fetch(server.id).url)}
            disabled={running}
            variant={fetched ? 'outline' : 'default'}
        >
            {running && <Spinner />}
            {fetched ? 'Fetch again' : 'Fetch accounts'}
        </Button>
    );

    return (
        <>
            <Head title="Import accounts" />
            <Page>
                <PageHeader
                    back={{
                        href: ServerController.edit(server.id),
                        label: server.name,
                    }}
                    title="Import accounts"
                    meta={<span className="font-mono">{server.hostname}</span>}
                    description="The accounts already on this server, matched to clients, products and services here."
                    actions={
                        <>
                            {ready && (
                                <Button
                                    variant="outline"
                                    title="Match the list again against today's clients, products and services, without asking the server."
                                    onClick={() =>
                                        post(
                                            ServerImportController.rematch(
                                                server.id,
                                            ).url,
                                        )
                                    }
                                >
                                    Match again
                                </Button>
                            )}
                            {fetchButton}
                        </>
                    }
                />

                <p className="-mt-2 text-sm text-muted-foreground">
                    This page changes nothing. Importing comes next: you tick
                    the accounts to bring in, review them, and confirm. Each
                    becomes a service for its client, with no invoice, email or
                    change on the server.
                </p>

                {!supported && (
                    <Alert>
                        <CircleAlert />
                        <AlertTitle>
                            This server cannot list its accounts
                        </AlertTitle>
                        <AlertDescription>
                            Its driver does not report the accounts on the
                            server, so they cannot be imported. Add the services
                            by hand instead.
                        </AlertDescription>
                    </Alert>
                )}

                {errors.import && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.import}</AlertDescription>
                    </Alert>
                )}

                {running && (
                    <Alert>
                        <Spinner />
                        <AlertTitle>
                            {matching ? 'Matching' : 'Reading'} the accounts on{' '}
                            {server.name}
                        </AlertTitle>
                        <AlertDescription>
                            Large servers take a minute or two. This page
                            updates when the list is ready.
                        </AlertDescription>
                    </Alert>
                )}

                {stalled && (
                    <Alert variant="destructive">
                        <ServerCrash />
                        <AlertTitle>
                            The last {matching ? 'match' : 'fetch'} did not
                            finish
                        </AlertTitle>
                        <AlertDescription>
                            <span>
                                Started{' '}
                                <DateTime
                                    value={fetched?.requested_at ?? null}
                                    relative
                                />
                                {fetched.requested_by &&
                                    ` by ${fetched.requested_by}`}
                                , but no worker reported back. Check that the
                                queue worker runs, then fetch again.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {fetched?.status === 'failed' && (
                    <Alert variant="destructive">
                        <ServerCrash />
                        <AlertTitle>The accounts could not be read</AlertTitle>
                        <AlertDescription>
                            {fetched.error} Check the server with Test
                            connection, then fetch again.
                        </AlertDescription>
                    </Alert>
                )}

                {supported && fetched === null && (
                    <EmptyState
                        variant="bordered"
                        icon={UserRoundSearch}
                        title="No account list yet"
                        description="Fetch the accounts to see which ones match a client and a product here. The server is only read, and the list expires after a day."
                        action={fetchButton}
                    />
                )}

                {ready && fetched && counts && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {plural(fetched.account_count, 'account')} read{' '}
                            <DateTime value={fetched.fetched_at} relative />
                            {fetched.requested_by &&
                                ` by ${fetched.requested_by}`}
                            . Expires{' '}
                            <DateTime
                                value={fetched.expires_at}
                                format="datetime"
                            />
                            .
                        </p>

                        <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-5">
                            <StatCard
                                label="Proposed"
                                value={counts.proposed}
                                hint={<Wrap>One client, one product</Wrap>}
                                icon={Sparkles}
                            />
                            <StatCard
                                label="Unmatched"
                                value={counts.unmatched}
                                hint={<Wrap>Needs a choice</Wrap>}
                                icon={UserRoundSearch}
                                tone={
                                    counts.unmatched > 0 ? 'warning' : 'default'
                                }
                            />
                            <StatCard
                                label="Reseller-owned"
                                value={counts.reseller}
                                hint={<Wrap>Other WHM owner</Wrap>}
                                icon={Users}
                            />
                            <StatCard
                                label="Already linked"
                                value={counts.linked}
                                hint={<Wrap>Not imported again</Wrap>}
                                icon={Link2}
                            />
                            <StatCard
                                label="Missing on server"
                                value={counts.missing}
                                hint={<Wrap>Only in billing</Wrap>}
                                icon={ServerCrash}
                                tone={
                                    counts.missing > 0 ? 'warning' : 'default'
                                }
                            />
                        </div>

                        <Tabs
                            value={filters.tab}
                            onValueChange={(value) =>
                                apply({
                                    tab: value === 'all' ? null : value,
                                    page: null,
                                })
                            }
                        >
                            {/* The tabs scroll on phones; the fade shows there are more. */}
                            <TabsList className="max-w-full justify-start overflow-x-auto [mask-image:linear-gradient(to_right,black_85%,transparent)] sm:[mask-image:none]">
                                {TABS.map((tab) => (
                                    <TabsTrigger
                                        key={tab.value}
                                        value={tab.value}
                                    >
                                        {tab.label}
                                        <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                            {counts[tab.value]}
                                        </span>
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>

                        <FilterBar searchPlaceholder="Search username, domain or email…" />

                        {filters.tab === 'missing' ? (
                            <DataTable
                                caption="Services missing on the server"
                                columns={missingColumns}
                                rows={rows.data as MissingRow[]}
                                getRowKey={(row) => row.service.id}
                                empty={
                                    <EmptyState
                                        icon={Link2}
                                        title="Nothing missing"
                                        description="Every live service on this server had its account there when the list was read."
                                    />
                                }
                                footer={
                                    <Pagination
                                        paginator={rows}
                                        itemLabel="services"
                                    />
                                }
                            />
                        ) : (
                            <DataTable
                                caption="Accounts on the server"
                                columns={accountColumns(createProductUrl)}
                                rows={rows.data as AccountRow[]}
                                getRowKey={(row) => row.username}
                                empty={
                                    <EmptyState
                                        icon={UserRoundSearch}
                                        title="No accounts here"
                                        description="No account on the server falls in this list."
                                    />
                                }
                                footer={
                                    <Pagination
                                        paginator={rows}
                                        itemLabel="accounts"
                                    />
                                }
                            />
                        )}
                    </>
                )}
            </Page>
        </>
    );
}
