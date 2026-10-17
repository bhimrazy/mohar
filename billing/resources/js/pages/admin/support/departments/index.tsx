import { Head, Link } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';
import DepartmentController from '@/actions/App/Modules/Support/Http/Controllers/Admin/DepartmentController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';

type DepartmentRow = {
    id: number;
    name: string;
    email: string;
    is_hidden: boolean;
    clients_only: boolean;
    replies_only_by_email: boolean;
    tickets_count: number;
    staff_count: number;
};

const columns: DataTableColumn<DepartmentRow>[] = [
    {
        key: 'name',
        header: 'Department',
        primary: true,
        cell: (row) => row.name,
    },
    {
        key: 'email',
        header: 'Email',
        cell: (row) => <span className="font-mono text-xs">{row.email}</span>,
    },
    {
        key: 'visibility',
        header: 'Visibility',
        cell: (row) =>
            row.is_hidden ? (
                <StatusBadge status="hidden" label="Hidden" />
            ) : row.clients_only ? (
                <StatusBadge
                    status="clients-only"
                    label="Clients only"
                    tone="info"
                />
            ) : (
                <StatusBadge status="public" label="Public" tone="success" />
            ),
    },
    {
        key: 'staff',
        header: 'Staff',
        align: 'right',
        cell: (row) => row.staff_count,
    },
    {
        key: 'tickets',
        header: 'Tickets',
        align: 'right',
        cell: (row) => row.tickets_count,
    },
];

export default function DepartmentsIndex({
    departments,
}: {
    departments: DepartmentRow[];
}) {
    return (
        <>
            <Head title="Departments" />
            <Page>
                <PageHeader
                    title="Departments"
                    description="Where tickets are routed. Staff only see departments they belong to."
                    actions={
                        <Button asChild>
                            <Link href={DepartmentController.create()}>
                                <Plus />
                                New department
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Departments"
                    columns={columns}
                    rows={departments}
                    getRowKey={(row) => row.id}
                    rowHref={(row) => DepartmentController.edit(row.id)}
                    empty={
                        <EmptyState
                            icon={Inbox}
                            title="No departments yet"
                            description="Create a department so clients can open tickets."
                            action={
                                <Button size="sm" asChild>
                                    <Link href={DepartmentController.create()}>
                                        <Plus />
                                        New department
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
            </Page>
        </>
    );
}
