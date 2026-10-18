import { Head, Link } from '@inertiajs/react';
import { Plus, Webhook } from 'lucide-react';
import WebhookEndpointController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/WebhookEndpointController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { DeliveryStatus } from '@/components/notifications/email-status';
import { Button } from '@/components/ui/button';
import type { WebhookEndpointSummary } from '@/types/notifications';

type Endpoint = WebhookEndpointSummary & {
    last_delivery: { status: string; updated_at: string } | null;
};

const columns: DataTableColumn<Endpoint>[] = [
    {
        key: 'url',
        header: 'Endpoint',
        primary: true,
        cell: (endpoint) => (
            <div className="min-w-0">
                <div className="truncate font-mono text-sm">{endpoint.url}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {endpoint.description ||
                        (endpoint.events.includes('*')
                            ? 'All events'
                            : endpoint.events.join(', '))}
                </div>
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (endpoint) => (
            <StatusBadge
                status={
                    endpoint.is_active
                        ? 'active'
                        : endpoint.disabled_reason
                          ? 'failed'
                          : 'inactive'
                }
                label={
                    endpoint.is_active
                        ? 'Active'
                        : endpoint.disabled_reason
                          ? 'Auto-disabled'
                          : 'Paused'
                }
            />
        ),
    },
    {
        key: 'last',
        header: 'Last delivery',
        cell: (endpoint) =>
            endpoint.last_delivery ? (
                <div className="flex items-center gap-2">
                    <DeliveryStatus status={endpoint.last_delivery.status} />
                    <DateTime
                        value={endpoint.last_delivery.updated_at}
                        relative
                        className="text-xs text-muted-foreground"
                    />
                </div>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
];

export default function WebhooksIndex({
    endpoints,
}: {
    endpoints: Endpoint[];
}) {
    return (
        <>
            <Head title="Webhooks" />
            <Page>
                <PageHeader
                    title="Webhooks"
                    description="Signed HTTPS callbacks for billing, service, domain and support events."
                    actions={
                        <Button asChild>
                            <Link href={WebhookEndpointController.create()}>
                                <Plus />
                                Add endpoint
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Webhook endpoints"
                    columns={columns}
                    rows={endpoints}
                    getRowKey={(endpoint) => endpoint.id}
                    rowHref={(endpoint) =>
                        WebhookEndpointController.show(endpoint.id)
                    }
                    empty={
                        <EmptyState
                            icon={Webhook}
                            title="No webhook endpoints"
                            description="Add an HTTPS endpoint to receive events such as invoice.paid or service.suspended."
                        />
                    }
                />
            </Page>
        </>
    );
}
