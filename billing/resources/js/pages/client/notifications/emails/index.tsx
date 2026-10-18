import { Head } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import EmailHistoryController from '@/actions/App/Modules/Notifications/Http/Controllers/Client/EmailHistoryController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import type { LaravelPaginated } from '@/types/app';

type Message = {
    uuid: string;
    recipient: string;
    subject: string;
    sent_at: string | null;
};

const columns: DataTableColumn<Message>[] = [
    {
        key: 'subject',
        header: 'Subject',
        primary: true,
        cell: (message) => <span className="truncate">{message.subject}</span>,
    },
    {
        key: 'recipient',
        header: 'Sent to',
        hideOnMobile: true,
        cell: (message) => (
            <span className="text-sm text-muted-foreground">
                {message.recipient}
            </span>
        ),
    },
    {
        key: 'sent',
        header: 'Date',
        align: 'right',
        cell: (message) => <DateTime value={message.sent_at} />,
    },
];

export default function EmailHistory({
    messages,
}: {
    messages: LaravelPaginated<Message>;
}) {
    return (
        <>
            <Head title="Email history" />
            <Page>
                <PageHeader
                    title="Email history"
                    description="Emails we sent to addresses on this account."
                />
                <DataTable
                    caption="Email history"
                    columns={columns}
                    rows={messages.data}
                    getRowKey={(message) => message.uuid}
                    rowHref={(message) =>
                        EmailHistoryController.show(message.uuid)
                    }
                    empty={
                        <EmptyState
                            icon={Inbox}
                            title="No emails yet"
                            description="Invoices, receipts and service emails will show up here."
                        />
                    }
                    footer={
                        <Pagination paginator={messages} itemLabel="emails" />
                    }
                />
            </Page>
        </>
    );
}
