import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Megaphone, Plus } from 'lucide-react';
import AnnouncementController from '@/actions/App/Modules/Support/Http/Controllers/Admin/AnnouncementController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types/app';

type Row = {
    id: number;
    title: string;
    slug: string;
    status: string;
    published_at: string | null;
};

const columns: DataTableColumn<Row>[] = [
    { key: 'title', header: 'Title', primary: true, cell: (row) => row.title },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => <StatusBadge status={row.status} />,
    },
    {
        key: 'published',
        header: 'Publish date',
        align: 'right',
        cell: (row) => <DateTime value={row.published_at} format="datetime" />,
    },
];

export default function AnnouncementsIndex({
    announcements,
}: {
    announcements: LaravelPaginated<Row>;
}) {
    const create = (
        <Button asChild>
            <Link href={AnnouncementController.create()}>
                <Plus />
                New announcement
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Announcements" />
            <Page>
                <PageHeader
                    title="Announcements"
                    description="News shown in the client area and on the public announcements page."
                    actions={create}
                />
                <DataTable
                    caption="Announcements"
                    columns={columns}
                    rows={announcements.data}
                    getRowKey={(row) => row.id}
                    rowHref={(row) => AnnouncementController.edit(row.id)}
                    empty={
                        <EmptyState
                            icon={Megaphone}
                            title="No announcements yet"
                            description="Post maintenance windows, launches and other news for your clients."
                        />
                    }
                    footer={
                        <Pagination
                            paginator={announcements}
                            itemLabel="announcements"
                        />
                    }
                />
            </Page>
        </>
    );
}
