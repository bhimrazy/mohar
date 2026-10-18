import { Head, Link } from '@inertiajs/react';
import { Inbox, Send } from 'lucide-react';
import ClientEmailController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/ClientEmailController';
import EmailLogController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailLogController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { DeliveryStatus } from '@/components/notifications/email-status';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated, SelectOption } from '@/types/app';
import type { EmailSummary } from '@/types/notifications';

const columns: DataTableColumn<EmailSummary>[] = [
    {
        key: 'subject',
        header: 'Email',
        primary: true,
        cell: (message) => (
            <div className="min-w-0">
                <div className="truncate">{message.subject}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {message.recipient}
                    {message.client && <> · {message.client.name}</>}
                </div>
            </div>
        ),
    },
    {
        key: 'template',
        header: 'Template',
        hideOnMobile: true,
        cell: (message) => (
            <span className="font-mono text-xs text-muted-foreground">
                {message.template_name}
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (message) => (
            <div className="grid gap-0.5">
                <DeliveryStatus status={message.status} />
                {message.attempts > 1 && (
                    <span className="text-xs text-muted-foreground">
                        {message.attempts} attempts
                    </span>
                )}
            </div>
        ),
    },
    {
        key: 'created',
        header: 'Logged',
        align: 'right',
        cell: (message) => <DateTime value={message.created_at} relative />,
    },
];

export default function EmailLogIndex({
    messages,
    statuses,
    templates,
    client,
    filters,
    canSend,
}: {
    messages: LaravelPaginated<EmailSummary>;
    statuses: SelectOption[];
    templates: SelectOption[];
    client: { id: number; name: string } | null;
    filters: Record<string, string>;
    canSend: boolean;
}) {
    const filtered = Boolean(
        filters.search || filters.status || filters.template,
    );

    return (
        <>
            <Head title="Email log" />
            <Page>
                <PageHeader
                    title={client ? `Emails · ${client.name}` : 'Email log'}
                    description="Every email the system sent or tried to send. Secrets such as reset links and passwords are masked in stored copies."
                    actions={
                        canSend &&
                        client && (
                            <Button asChild>
                                <Link
                                    href={ClientEmailController.create(
                                        client.id,
                                    )}
                                >
                                    <Send />
                                    Send email
                                </Link>
                            </Button>
                        )
                    }
                />
                <div className="flex flex-col gap-4">
                    <FilterBar
                        searchPlaceholder="Search recipient, subject or template…"
                        filterKeys={['status', 'template']}
                        filters={
                            <>
                                <FilterSelect
                                    name="status"
                                    label="Status"
                                    allLabel="All statuses"
                                    options={statuses}
                                />
                                <FilterSelect
                                    name="template"
                                    label="Template"
                                    allLabel="All templates"
                                    options={templates}
                                />
                            </>
                        }
                    />
                    <DataTable
                        caption="Email log"
                        columns={columns}
                        rows={messages.data}
                        getRowKey={(message) => message.id}
                        rowHref={(message) =>
                            EmailLogController.show(message.id)
                        }
                        empty={
                            <EmptyState
                                icon={Inbox}
                                title={
                                    filtered
                                        ? 'No emails match these filters'
                                        : 'No emails yet'
                                }
                                description={
                                    filtered
                                        ? 'Clear a filter to see more.'
                                        : 'Emails appear here as soon as invoices, orders or tickets trigger them.'
                                }
                            />
                        }
                        footer={
                            <Pagination
                                paginator={messages}
                                itemLabel="emails"
                            />
                        }
                    />
                </div>
            </Page>
        </>
    );
}
