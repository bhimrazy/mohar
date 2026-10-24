import { FileX2, Trash2 } from 'lucide-react';
import OrphanFileController from '@/actions/App/Modules/Kyc/Http/Controllers/Admin/OrphanFileController';
import ReviewController from '@/actions/App/Modules/Kyc/Http/Controllers/Admin/ReviewController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type Row = {
    id: number;
    client: { id: number; name: string } | null;
    modified_at: string | null;
    found_at: string;
    last_seen_at: string;
};

type Props = {
    files: Row[];
    digest: string;
};

const columns: DataTableColumn<Row>[] = [
    {
        key: 'client',
        header: 'Client in the file name',
        primary: true,
        cell: (row) =>
            row.client ? (
                row.client.name
            ) : (
                <span className="text-muted-foreground">
                    No client with this number
                </span>
            ),
    },
    {
        key: 'modified',
        header: 'File written',
        cell: (row) =>
            row.modified_at ? <DateTime value={row.modified_at} /> : '—',
    },
    {
        key: 'found',
        header: 'First found',
        hideOnMobile: true,
        cell: (row) => <DateTime value={row.found_at} />,
    },
    {
        key: 'seen',
        header: 'Last checked',
        hideOnMobile: true,
        cell: (row) => <DateTime value={row.last_seen_at} relative />,
    },
];

export default function KycOrphanFiles({ files, digest }: Props) {
    const count = files.length;
    const noun = count === 1 ? 'file' : 'files';

    return (
        <>
            <Head title="Identity images without a document" />
            <Page>
                <PageHeader
                    title="Images without a document"
                    description="Encrypted identity images that no document refers to, usually left when an upload stopped partway. The daily check lists them; nothing is deleted until you confirm."
                    actions={
                        count > 0 ? (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="destructive">
                                        <Trash2 />
                                        Delete {count} {noun}
                                    </Button>
                                }
                                title={`Delete ${count} identity ${noun}?`}
                                description={
                                    <>
                                        The {noun} listed here{' '}
                                        {count === 1 ? 'is' : 'are'} deleted for
                                        good. Each file is checked again first:
                                        one that a document refers to by then is
                                        kept. If this database is a copy, or
                                        another installation shares the storage
                                        bucket, these files may belong to it: do
                                        not delete them.
                                    </>
                                }
                                confirmLabel={`Delete ${count} ${noun}`}
                                confirmText="DELETE"
                                action={OrphanFileController.destroy()}
                                data={{ digest }}
                            />
                        ) : undefined
                    }
                />
                {count > 20 && (
                    <Alert>
                        <AlertTitle>
                            More files than an upload failure leaves
                        </AlertTitle>
                        <AlertDescription>
                            A failed upload leaves one or two files. {count}{' '}
                            files usually means this database does not own the
                            storage bucket (a restored or older database, or a
                            second installation). Check that before deleting.
                        </AlertDescription>
                    </Alert>
                )}
                <SectionCard flush>
                    <DataTable
                        columns={columns}
                        rows={files}
                        getRowKey={(row) => row.id}
                        caption="Identity image files without a document"
                        empty={
                            <EmptyState
                                icon={FileX2}
                                title="No identity images without a document"
                                description="The daily automation checks the stored images and lists any that no document refers to."
                            />
                        }
                    />
                </SectionCard>
            </Page>
        </>
    );
}

KycOrphanFiles.layout = {
    breadcrumbs: [
        { title: 'Identity verification', href: ReviewController.index() },
        {
            title: 'Images without a document',
            href: OrphanFileController.index(),
        },
    ],
};
