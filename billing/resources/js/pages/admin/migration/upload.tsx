import { Form, Link, setLayoutProps } from '@inertiajs/react';
import { FileUp, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { StatusBadge } from '@/components/app/status-badge';
import { count, ImportSteps } from '@/components/migration/import-status';
import type { ImportRun } from '@/components/migration/import-status';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import type { LaravelPaginated, SelectOption } from '@/types/app';

type Props = {
    sources: { id: number; name: string }[];
    runs: LaravelPaginated<ImportRun & { source: string }>;
    limits: {
        megabytes: number;
        rows: number;
        columns: number;
        server_megabytes: number;
    };
    presets: SelectOption[];
};

const STATUS_LABELS: Record<ImportRun['status'], string> = {
    uploaded: 'Waiting',
    staging: 'Reading',
    mapping: 'Map columns',
    checking: 'Checking',
    ready: 'Checked',
    failed: 'Stopped',
    expired: 'Expired',
};

const DELIMITERS: SelectOption[] = [
    { value: 'comma', label: 'Comma' },
    { value: 'semicolon', label: 'Semicolon' },
    { value: 'tab', label: 'Tab' },
];

const NEW_SOURCE = 'new';

const columns: DataTableColumn<ImportRun & { source: string }>[] = [
    {
        key: 'file',
        header: 'File',
        primary: true,
        cell: (run) => (
            <span className="flex min-w-0 flex-col">
                <Link
                    href={ClientImportController.show(run.id)}
                    className="font-medium break-all hover:underline"
                >
                    {run.name}
                </Link>
                <span className="text-xs text-muted-foreground">
                    {run.source}
                </span>
            </span>
        ),
    },
    {
        key: 'rows',
        header: 'Rows',
        align: 'right',
        cell: (run) => <span className="tabular-nums">{count(run.rows)}</span>,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (run) => (
            <StatusBadge
                status={run.status}
                label={STATUS_LABELS[run.status]}
            />
        ),
    },
    {
        key: 'uploaded',
        header: 'Uploaded',
        cell: (run) => (
            <span className="flex flex-col">
                <DateTime value={run.uploaded_at} format="datetime" />
                {run.requested_by && (
                    <span className="text-xs text-muted-foreground">
                        {run.requested_by}
                    </span>
                )}
            </span>
        ),
    },
];

export default function ImportUpload({
    sources,
    runs,
    limits,
    presets,
}: Props) {
    const [source, setSource] = useState(
        sources[0] ? String(sources[0].id) : NEW_SOURCE,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: 'Clients', href: ClientController.index() },
            { title: 'Import clients', href: ClientImportController.index() },
        ],
    });

    return (
        <>
            <Head title="Import clients" />
            <Page>
                <PageHeader
                    title="Import clients"
                    description="Bring your clients over from a spreadsheet or another billing system's export. Nothing is created until you review and confirm."
                />
                <ImportSteps current={0} />

                <div className="grid items-start gap-(--page-gap) lg:grid-cols-3">
                    <Form
                        {...ClientImportController.store.form()}
                        className="lg:col-span-2"
                    >
                        {({ errors, processing }) => (
                            <SectionCard
                                title="Upload a file"
                                description={`A CSV file of up to ${limits.megabytes} MB, ${count(limits.rows)} rows and ${limits.columns} columns, with the column names in the first row.`}
                                footer={
                                    <Button type="submit" disabled={processing}>
                                        {processing ? <Spinner /> : <FileUp />}
                                        Upload and read
                                    </Button>
                                }
                            >
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="file"
                                        label="File"
                                        hint="Save spreadsheets as CSV UTF-8."
                                        error={errors.file}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="file"
                                            name="file"
                                            type="file"
                                            accept=".csv,.txt,.tsv,text/csv"
                                            aria-invalid={!!errors.file}
                                            aria-describedby="file-description"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="preset"
                                        label="The file comes from"
                                        hint="Used to suggest how each column maps. You can change every one."
                                        error={errors.preset}
                                    >
                                        <SelectField
                                            id="preset"
                                            name="preset"
                                            options={presets}
                                            defaultValue="whmcs"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="delimiter"
                                        label="Separator"
                                        optional
                                        hint="Detected from the first row when left empty."
                                        error={errors.delimiter}
                                    >
                                        <SelectField
                                            id="delimiter"
                                            name="delimiter"
                                            options={DELIMITERS}
                                            emptyLabel="Detect"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="source"
                                        label="Source"
                                        hint="Re-uploads into the same source update the clients imported before instead of adding them twice."
                                        error={errors.source_id}
                                    >
                                        <SelectField
                                            id="source"
                                            value={source}
                                            onValueChange={setSource}
                                            options={[
                                                ...sources.map((item) => ({
                                                    value: String(item.id),
                                                    label: item.name,
                                                })),
                                                {
                                                    value: NEW_SOURCE,
                                                    label: 'A new source…',
                                                },
                                            ]}
                                        />
                                        {source !== NEW_SOURCE && (
                                            <input
                                                type="hidden"
                                                name="source_id"
                                                value={source}
                                            />
                                        )}
                                    </Field>
                                    {source === NEW_SOURCE && (
                                        <Field
                                            htmlFor="source_name"
                                            label="Name of the new source"
                                            error={errors.source_name}
                                        >
                                            <Input
                                                id="source_name"
                                                name="source_name"
                                                maxLength={100}
                                                placeholder="Old billing system"
                                                aria-invalid={
                                                    !!errors.source_name
                                                }
                                            />
                                        </Field>
                                    )}
                                </div>
                            </SectionCard>
                        )}
                    </Form>

                    <SectionCard title="What happens to the file">
                        <div className="grid gap-3 text-sm text-muted-foreground">
                            <p className="flex gap-2">
                                <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
                                It is encrypted before it is stored, read into
                                encrypted rows, and then deleted.
                            </p>
                            <p>
                                Columns named like passwords, card numbers or
                                security answers are never stored. Clients set a
                                password from their invitation.
                            </p>
                            <p>
                                An upload you do not import is removed after a
                                week.
                            </p>
                        </div>
                    </SectionCard>
                </div>

                {limits.server_megabytes < limits.megabytes && (
                    <Alert>
                        <AlertDescription>
                            This server accepts uploads of up to{' '}
                            {limits.server_megabytes} MB. Ask your administrator
                            to raise PHP's upload_max_filesize to upload larger
                            files.
                        </AlertDescription>
                    </Alert>
                )}

                <SectionCard title="Earlier uploads" flush>
                    <DataTable
                        caption="Earlier uploads"
                        columns={columns}
                        rows={runs.data}
                        getRowKey={(run) => run.id}
                        empty={
                            <EmptyState
                                icon={FileUp}
                                title="No uploads yet"
                                description="Files you upload appear here with their progress."
                            />
                        }
                        footer={
                            <Pagination paginator={runs} itemLabel="uploads" />
                        }
                    />
                </SectionCard>
            </Page>
        </>
    );
}
