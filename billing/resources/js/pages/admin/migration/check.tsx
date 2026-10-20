import { Link, setLayoutProps } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleSlash,
    FilePlus2,
    FileSearch,
    HelpCircle,
    Pencil,
    Receipt,
} from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar } from '@/components/app/filter-bar';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import {
    count,
    ImportMeta,
    ImportProgress,
    ImportSteps,
} from '@/components/migration/import-status';
import type { ImportRun } from '@/components/migration/import-status';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import type { LaravelPaginated } from '@/types/app';

type Tab =
    | 'all'
    | 'create'
    | 'update'
    | 'unchanged'
    | 'skip'
    | 'needs_decision'
    | 'error';

type Row = {
    row: number;
    name: string;
    company: string | null;
    email: string | null;
    status: 'ok' | 'warning' | 'error';
    decision: Exclude<Tab, 'all' | 'error'> | null;
    problems: { severity: string; message: string }[];
    changes: string[];
    match: { name: string; href: string } | null;
};

type Props = {
    run: ImportRun;
    counts: Record<Tab | 'tax_exempt', number>;
    rows: LaravelPaginated<Row>;
    filters: { tab: Tab; search: string };
    matchesByEmail: boolean;
};

const TABS: { value: Tab; label: string }[] = [
    { value: 'all', label: 'All rows' },
    { value: 'create', label: 'New clients' },
    { value: 'update', label: 'Updates' },
    { value: 'unchanged', label: 'Unchanged' },
    { value: 'skip', label: 'Skipped' },
    { value: 'needs_decision', label: 'Needs a decision' },
    { value: 'error', label: 'Errors' },
];

const DECISIONS = {
    create: { label: 'New client', tone: 'success' },
    update: { label: 'Update', tone: 'info' },
    unchanged: { label: 'Unchanged', tone: 'neutral' },
    skip: { label: 'Skipped', tone: 'neutral' },
    needs_decision: { label: 'Needs a decision', tone: 'warning' },
} as const;

/** A card hint that wraps on narrow screens instead of being cut off. */
function Wrap({ children }: { children: React.ReactNode }) {
    return <span className="whitespace-normal">{children}</span>;
}

const columns: DataTableColumn<Row>[] = [
    {
        key: 'row',
        header: 'Row',
        cell: (row) => (
            <span className="text-muted-foreground tabular-nums">
                Row {count(row.row)}
            </span>
        ),
    },
    {
        key: 'client',
        header: 'Client in the file',
        primary: true,
        cell: (row) => (
            <span className="flex min-w-0 flex-col">
                <span className="font-medium">
                    {row.name || 'No name'}
                    {row.company && (
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            · {row.company}
                        </span>
                    )}
                </span>
                <span className="text-xs break-all text-muted-foreground">
                    {row.email ?? 'No email'}
                </span>
            </span>
        ),
    },
    {
        key: 'result',
        header: 'Result',
        cell: (row) =>
            row.status === 'error' || row.decision === null ? (
                <StatusBadge status="error" label="Error" tone="danger" />
            ) : (
                <StatusBadge
                    status={row.decision}
                    label={DECISIONS[row.decision].label}
                    tone={DECISIONS[row.decision].tone}
                />
            ),
    },
    {
        key: 'notes',
        header: 'Notes',
        cell: (row) => (
            <span className="flex max-w-md flex-col gap-1 text-sm whitespace-normal">
                {row.match && (
                    <span>
                        Matches{' '}
                        <Link href={row.match.href} className="hover:underline">
                            {row.match.name}
                        </Link>
                    </span>
                )}
                {row.changes.length > 0 && (
                    <span className="text-muted-foreground">
                        Changes: {row.changes.join(', ')}
                    </span>
                )}
                {row.problems.map((problem, index) => (
                    <span
                        key={index}
                        className={
                            problem.severity === 'error'
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        }
                    >
                        {problem.message}
                    </span>
                ))}
            </span>
        ),
    },
];

export default function ImportCheck({
    run,
    counts,
    rows,
    filters,
    matchesByEmail,
}: Props) {
    const { apply } = useQueryParams();
    const ready = run.status === 'ready';

    setLayoutProps({
        breadcrumbs: [
            { title: 'Clients', href: ClientController.index() },
            { title: 'Import clients', href: ClientImportController.index() },
            { title: run.name, href: ClientImportController.show(run.id) },
        ],
    });

    return (
        <>
            <Head title={`Check · ${run.name}`} />
            <Page>
                <PageHeader
                    back={{
                        href: ClientImportController.index(),
                        label: 'Import clients',
                    }}
                    title={ready ? 'Check results' : 'Checking the rows'}
                    meta={<ImportMeta run={run} />}
                    description="Every row checked against the client form's rules and your existing clients. Nothing has been imported."
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href={ClientImportController.show(run.id, {
                                    query: { step: 'map' },
                                })}
                            >
                                <Pencil />
                                Change mapping
                            </Link>
                        </Button>
                    }
                />
                <ImportSteps current={2} />
                <ImportProgress run={run} />

                {ready && (
                    <>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 xl:grid-cols-4">
                            <StatCard
                                label="New clients"
                                value={count(counts.create)}
                                icon={FilePlus2}
                            />
                            <StatCard
                                label="Updates"
                                value={count(counts.update)}
                                icon={Pencil}
                            />
                            <StatCard
                                label="Unchanged"
                                value={count(counts.unchanged)}
                                icon={CircleCheck}
                            />
                            <StatCard
                                label="Skipped"
                                value={count(counts.skip)}
                                hint={<Wrap>Email already here</Wrap>}
                                icon={CircleSlash}
                            />
                            <StatCard
                                label="Needs a decision"
                                value={count(counts.needs_decision)}
                                icon={HelpCircle}
                                tone={
                                    counts.needs_decision > 0
                                        ? 'warning'
                                        : 'default'
                                }
                            />
                            <StatCard
                                label="Errors"
                                value={count(counts.error)}
                                icon={CircleAlert}
                                tone={counts.error > 0 ? 'danger' : 'default'}
                            />
                            {counts.tax_exempt > 0 && (
                                <StatCard
                                    label="Tax-exempt"
                                    value={count(counts.tax_exempt)}
                                    hint={<Wrap>Invoiced without tax</Wrap>}
                                    icon={Receipt}
                                />
                            )}
                        </div>

                        <Alert>
                            <FileSearch />
                            <AlertDescription>
                                {matchesByEmail
                                    ? 'No External ID column is mapped, so re-running this import matches clients by email. '
                                    : ''}
                                Reviewing the rows and importing them is the
                                next step; until then nothing changes in your
                                clients.
                            </AlertDescription>
                        </Alert>

                        <Tabs
                            value={filters.tab}
                            onValueChange={(value) =>
                                apply({
                                    tab: value === 'all' ? null : value,
                                    page: null,
                                })
                            }
                        >
                            <TabsList className="max-w-full justify-start overflow-x-auto [mask-image:linear-gradient(to_right,black_85%,transparent)] sm:[mask-image:none]">
                                {TABS.map((tab) => (
                                    <TabsTrigger
                                        key={tab.value}
                                        value={tab.value}
                                    >
                                        {tab.label}
                                        <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                            {count(counts[tab.value])}
                                        </span>
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>

                        <FilterBar searchPlaceholder="Row number or exact email…" />

                        <DataTable
                            caption="Checked rows"
                            columns={columns}
                            rows={rows.data}
                            getRowKey={(row) => row.row}
                            empty={
                                <EmptyState
                                    icon={FileSearch}
                                    title="No rows here"
                                    description={
                                        filters.search
                                            ? 'No row has that number or exact email.'
                                            : 'No row of the file falls in this list.'
                                    }
                                />
                            }
                            footer={
                                <Pagination paginator={rows} itemLabel="rows" />
                            }
                        />
                    </>
                )}
            </Page>
        </>
    );
}
