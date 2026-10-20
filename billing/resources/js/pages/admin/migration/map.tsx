import { Link, setLayoutProps } from '@inertiajs/react';
import { CircleAlert, Lock } from 'lucide-react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import {
    ImportMeta,
    ImportProgress,
    ImportSteps,
} from '@/components/migration/import-status';
import type { ImportRun } from '@/components/migration/import-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';

type Column = { header: string; samples: string[]; forbidden: boolean };

type Props = {
    run: ImportRun;
    columns: Column[];
    earlier: {
        name: string;
        uploaded_at: string | null;
        by: string | null;
        href: string;
    } | null;
};

const tableColumns: DataTableColumn<Column & { index: number }>[] = [
    {
        key: 'column',
        header: 'Column in the file',
        primary: true,
        cell: (column) => (
            <span className="font-medium break-all">
                {column.header || `Column ${column.index + 1}`}
            </span>
        ),
    },
    {
        key: 'samples',
        header: 'First values',
        cell: (column) =>
            column.forbidden ? (
                <span className="flex max-w-xs items-start gap-2 text-sm whitespace-normal text-muted-foreground">
                    <Lock className="mt-0.5 size-4 shrink-0" />
                    Not stored: passwords and card data cannot be imported.
                    Clients set a password from their invitation.
                </span>
            ) : (
                <span className="flex max-w-xs flex-col text-xs break-all whitespace-normal text-muted-foreground">
                    {column.samples.length > 0
                        ? column.samples.map((sample, index) => (
                              <span key={index}>{sample}</span>
                          ))
                        : 'Empty in the first rows'}
                </span>
            ),
    },
];

export default function ImportMap({ run, columns, earlier }: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Clients', href: ClientController.index() },
            { title: 'Import clients', href: ClientImportController.index() },
            { title: run.name, href: ClientImportController.show(run.id) },
        ],
    });

    return (
        <>
            <Head title={`Map columns · ${run.name}`} />
            <Page>
                <PageHeader
                    back={{
                        href: ClientImportController.index(),
                        label: 'Import clients',
                    }}
                    title="Map columns"
                    meta={<ImportMeta run={run} />}
                    description="The columns of the file, with their first values."
                />
                <ImportSteps current={1} />
                <ImportProgress run={run} />

                {earlier && (
                    <Alert>
                        <CircleAlert />
                        <AlertTitle>
                            This file was uploaded to this source before
                        </AlertTitle>
                        <AlertDescription>
                            <span>
                                <Link href={earlier.href} className="underline">
                                    {earlier.name}
                                </Link>{' '}
                                was uploaded{' '}
                                <DateTime
                                    value={earlier.uploaded_at}
                                    format="datetime"
                                />
                                {earlier.by && ` by ${earlier.by}`}. You can
                                continue: rows imported before come out
                                unchanged.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {columns.length > 0 && (
                    <SectionCard title={`Columns (${columns.length})`} flush>
                        <DataTable
                            caption="Columns in the file"
                            columns={tableColumns}
                            rows={columns.map((column, index) => ({
                                ...column,
                                index,
                            }))}
                            getRowKey={(column) => column.index}
                        />
                    </SectionCard>
                )}
            </Page>
        </>
    );
}
