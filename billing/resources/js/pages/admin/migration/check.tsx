import { Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleSlash,
    Download,
    FilePlus2,
    FileSearch,
    HelpCircle,
    Pencil,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import { cn } from '@/lib/utils';
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
    choices: Choice[];
    outcome: Outcome | null;
    client_href: string | null;
};

type Choice = 'create' | 'update' | 'skip';
type Outcome = 'created' | 'updated' | 'unchanged' | 'skipped' | 'failed';

type Props = {
    run: ImportRun;
    counts: Record<Tab | 'tax_exempt', number>;
    rows: LaravelPaginated<Row>;
    filters: { tab: Tab | Outcome; search: string };
    errorsUrl: string | null;
    matchesByEmail: boolean;
    digest: string | null;
    report: {
        created: number;
        updated: number;
        unchanged: number;
        skipped: number;
        failed: number;
        applied_by: string | null;
        applied_at: string;
        undo_until: string | null;
    } | null;
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

const OUTCOME_TABS: { value: 'all' | Outcome; label: string }[] = [
    { value: 'all', label: 'All rows' },
    { value: 'created', label: 'Created' },
    { value: 'updated', label: 'Updated' },
    { value: 'unchanged', label: 'Unchanged' },
    { value: 'skipped', label: 'Skipped' },
    { value: 'failed', label: 'Not imported' },
];

const OUTCOMES = {
    created: { label: 'Created', tone: 'success' },
    updated: { label: 'Updated', tone: 'info' },
    unchanged: { label: 'Unchanged', tone: 'neutral' },
    skipped: { label: 'Skipped', tone: 'neutral' },
    failed: { label: 'Not imported', tone: 'danger' },
} as const;

const CHOICE_LABELS: Record<Choice, string> = {
    create: 'Create a new client',
    update: 'Update the matched client',
    skip: 'Skip this row',
};

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

const columns = (
    decide: (row: Row, choice: string) => void,
): DataTableColumn<Row>[] => [
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
        cell: (row) => (
            <span className="flex flex-col items-start gap-2">
                {row.outcome ? (
                    <StatusBadge
                        status={row.outcome}
                        label={OUTCOMES[row.outcome].label}
                        tone={OUTCOMES[row.outcome].tone}
                    />
                ) : row.status === 'error' || row.decision === null ? (
                    <StatusBadge status="error" label="Error" tone="danger" />
                ) : (
                    <StatusBadge
                        status={row.decision}
                        label={DECISIONS[row.decision].label}
                        tone={DECISIONS[row.decision].tone}
                    />
                )}
                {row.choices.length > 0 && (
                    <SelectField
                        id={`row-${row.row}`}
                        className="w-full sm:w-52"
                        placeholder="Change…"
                        options={row.choices.map((choice) => ({
                            value: choice,
                            label: CHOICE_LABELS[choice],
                        }))}
                        onValueChange={(choice) => decide(row, choice)}
                    />
                )}
            </span>
        ),
    },
    {
        key: 'notes',
        header: 'Notes',
        cell: (row) => (
            <span className="flex max-w-md flex-col gap-1 text-sm whitespace-normal">
                {row.client_href && (
                    <Link href={row.client_href} className="hover:underline">
                        Open the client
                    </Link>
                )}
                {row.match && !row.client_href && (
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
    digest,
    report,
    errorsUrl,
}: Props) {
    const decide = (row: Row, decision: string) =>
        router.put(
            ClientImportController.decide(run.id).url,
            { row: row.row, decision },
            { preserveScroll: true },
        );
    const bulk = (url: string) =>
        router.post(url, {}, { preserveScroll: true });
    const tabs = report
        ? OUTCOME_TABS.map((tab) => ({
              ...tab,
              count: tab.value === 'all' ? run.rows : report[tab.value],
          }))
        : TABS.map((tab) => ({ ...tab, count: counts[tab.value] }));
    const { apply } = useQueryParams();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [taxExempt, setTaxExempt] = useState(false);
    const ready = ['ready', 'applying', 'completed'].includes(run.status);
    const blocked =
        counts.needs_decision > 0
            ? `Decide what to do with the ${count(counts.needs_decision)} ${counts.needs_decision === 1 ? 'row that needs' : 'rows that need'} a decision first.`
            : counts.create + counts.update === 0
              ? 'No row creates or updates a client.'
              : null;

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
                    title={
                        report
                            ? 'Import report'
                            : ready
                              ? 'Check results'
                              : 'Checking the rows'
                    }
                    meta={<ImportMeta run={run} />}
                    description={
                        report
                            ? 'Every row of the file, as it was checked before the import.'
                            : "Every row checked against the client form's rules and your existing clients. Nothing has been imported."
                    }
                    actions={
                        !report && (
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
                        )
                    }
                />
                <ImportSteps
                    current={
                        run.status === 'completed'
                            ? 5
                            : run.status === 'ready'
                              ? 3
                              : run.applied > 0 || report
                                ? 4
                                : 2
                    }
                />
                <ImportProgress run={run} />

                {errorsUrl && (
                    <p className="text-sm text-muted-foreground">
                        <a
                            href={errorsUrl}
                            className="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <Download className="size-4" />
                            Download the rows with errors
                        </a>{' '}
                        (CSV, with the reason and the original cells, to fix the
                        file).
                    </p>
                )}

                {(errors.apply || errors.tax_exempt || errors.digest) && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertDescription>
                            {errors.apply ?? errors.tax_exempt ?? errors.digest}
                        </AlertDescription>
                    </Alert>
                )}

                {report && (
                    <SectionCard
                        title={
                            run.status === 'completed'
                                ? 'Import finished'
                                : 'Import in progress'
                        }
                        description={
                            <>
                                Confirmed{' '}
                                <DateTime
                                    value={report.applied_at}
                                    format="datetime"
                                />
                                {report.applied_by &&
                                    ` by ${report.applied_by}`}
                                .{' '}
                                {report.undo_until && (
                                    <>
                                        Row details are kept until{' '}
                                        <DateTime value={report.undo_until} />.
                                    </>
                                )}
                            </>
                        }
                    >
                        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
                            {(
                                [
                                    ['Created', report.created],
                                    ['Updated', report.updated],
                                    ['Unchanged', report.unchanged],
                                    ['Skipped', report.skipped],
                                    ['Not imported', report.failed],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd
                                        className={cn(
                                            'text-2xl font-semibold tabular-nums',
                                            label === 'Not imported' &&
                                                value > 0 &&
                                                'text-destructive',
                                        )}
                                    >
                                        {count(value)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </SectionCard>
                )}

                {run.status === 'ready' && digest && (
                    <SectionCard
                        title="Import the checked rows"
                        footer={
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <ConfirmDialog
                                    trigger={
                                        <Button disabled={blocked !== null}>
                                            Import {count(counts.create)} new
                                            and {count(counts.update)} updated
                                            clients
                                        </Button>
                                    }
                                    title="Import these clients?"
                                    description={
                                        <span className="flex flex-col gap-3">
                                            <span>
                                                Create {count(counts.create)}{' '}
                                                clients and update{' '}
                                                {count(counts.update)}. No email
                                                is sent. Rows with errors, and
                                                rows that are skipped or
                                                unchanged, are left as they are.
                                            </span>
                                            {counts.tax_exempt > 0 && (
                                                <label className="flex items-start gap-2">
                                                    <Checkbox
                                                        checked={taxExempt}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setTaxExempt(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    {count(counts.tax_exempt)}{' '}
                                                    {counts.tax_exempt === 1
                                                        ? 'client'
                                                        : 'clients'}{' '}
                                                    will be invoiced without
                                                    tax.
                                                </label>
                                            )}
                                        </span>
                                    }
                                    confirmLabel="Import clients"
                                    destructive={false}
                                    action={ClientImportController.apply(
                                        run.id,
                                    )}
                                    data={{ digest, tax_exempt: taxExempt }}
                                />
                                {counts.needs_decision > 0 && (
                                    <>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                bulk(
                                                    ClientImportController.accept(
                                                        run.id,
                                                    ).url,
                                                )
                                            }
                                        >
                                            Accept all proposed
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                bulk(
                                                    ClientImportController.skipUndecided(
                                                        run.id,
                                                    ).url,
                                                )
                                            }
                                        >
                                            Skip the rows that need a decision
                                        </Button>
                                    </>
                                )}
                                {blocked && (
                                    <span className="text-sm text-muted-foreground">
                                        {blocked}
                                    </span>
                                )}
                            </div>
                        }
                    >
                        <p className="text-sm text-muted-foreground">
                            Imported clients get no welcome email and no
                            invoice. Each new client gets an owner login with no
                            usable password until you send invitations.
                        </p>
                    </SectionCard>
                )}

                {ready && (
                    <>
                        {!report && (
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
                                    tone={
                                        counts.error > 0 ? 'danger' : 'default'
                                    }
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
                        )}

                        {matchesByEmail && (
                            <Alert>
                                <FileSearch />
                                <AlertDescription>
                                    No External ID column is mapped, so
                                    re-running this import matches clients by
                                    email.
                                </AlertDescription>
                            </Alert>
                        )}

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
                                {tabs.map((tab) => (
                                    <TabsTrigger
                                        key={tab.value}
                                        value={tab.value}
                                    >
                                        {tab.label}
                                        <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                            {count(tab.count)}
                                        </span>
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>

                        <FilterBar searchPlaceholder="Row number or exact email…" />

                        <DataTable
                            caption="Checked rows"
                            columns={columns(decide)}
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
