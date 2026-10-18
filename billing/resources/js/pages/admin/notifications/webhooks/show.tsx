import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, Pencil, RefreshCw, Send } from 'lucide-react';
import WebhookEndpointController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/WebhookEndpointController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { DeliveryStatus } from '@/components/notifications/email-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import type { LaravelPaginated } from '@/types/app';
import type { WebhookEndpointSummary } from '@/types/notifications';

type Delivery = {
    id: number;
    event_id: string;
    event_type: string;
    status: string;
    attempt: number;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    duration_ms: number | null;
    payload: string;
    next_attempt_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

const STATUSES = [
    { value: 'pending', label: 'Pending' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed (retrying)' },
    { value: 'abandoned', label: 'Abandoned' },
];

export default function WebhookShow({
    endpoint,
    deliveries,
}: {
    endpoint: WebhookEndpointSummary;
    deliveries: LaravelPaginated<Delivery>;
}) {
    const secret = (usePage().flash as { webhookSecret?: string })
        .webhookSecret;
    const [copied, copy] = useClipboard();

    return (
        <>
            <Head title="Webhook endpoint" />
            <Page>
                <PageHeader
                    back={{
                        href: WebhookEndpointController.index(),
                        label: 'Webhooks',
                    }}
                    title={
                        <span className="font-mono text-lg break-all">
                            {endpoint.url}
                        </span>
                    }
                    description={endpoint.description}
                    badges={
                        <StatusBadge
                            status={endpoint.is_active ? 'active' : 'failed'}
                            label={
                                endpoint.is_active
                                    ? 'Active'
                                    : endpoint.disabled_reason
                                      ? 'Auto-disabled'
                                      : 'Paused'
                            }
                        />
                    }
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        WebhookEndpointController.ping.url(
                                            endpoint.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Send />
                                Send test event
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={WebhookEndpointController.edit(
                                        endpoint.id,
                                    )}
                                >
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        </>
                    }
                />
                <div className="grid items-start gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        {secret && (
                            <Alert>
                                <KeyRound />
                                <AlertTitle>
                                    Signing secret — copy it now
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        This is the only time it is shown. Store
                                        it with the receiver.
                                    </p>
                                    <div className="mt-2 flex items-center gap-2">
                                        <code className="rounded bg-muted px-2 py-1 font-mono text-xs break-all">
                                            {secret}
                                        </code>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Copy secret"
                                            onClick={() => copy(secret)}
                                        >
                                            {copied ? <Check /> : <Copy />}
                                        </Button>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}
                        {endpoint.disabled_reason && !endpoint.is_active && (
                            <Alert variant="destructive">
                                <AlertTitle>Endpoint disabled</AlertTitle>
                                <AlertDescription>
                                    {endpoint.disabled_reason} Fix the receiver,
                                    then set it active again.
                                </AlertDescription>
                            </Alert>
                        )}
                        <FilterBar
                            searchParam={null}
                            filterKeys={['status']}
                            filters={
                                <FilterSelect
                                    name="status"
                                    label="Status"
                                    allLabel="All deliveries"
                                    options={STATUSES}
                                />
                            }
                        />
                        <SectionCard title="Deliveries" flush>
                            {deliveries.data.length === 0 ? (
                                <EmptyState
                                    title="No deliveries yet"
                                    description="Send a test event, or wait for the next subscribed event."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {deliveries.data.map((delivery) => (
                                        <li key={delivery.id} className="p-4">
                                            <details>
                                                <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3">
                                                    <DeliveryStatus
                                                        status={delivery.status}
                                                    />
                                                    <span className="font-mono text-sm">
                                                        {delivery.event_type}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        attempt{' '}
                                                        {delivery.attempt}
                                                        {delivery.response_status !==
                                                            null && (
                                                            <>
                                                                {' '}
                                                                · HTTP{' '}
                                                                {
                                                                    delivery.response_status
                                                                }
                                                            </>
                                                        )}
                                                        {delivery.duration_ms !==
                                                            null && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                {
                                                                    delivery.duration_ms
                                                                }{' '}
                                                                ms
                                                            </>
                                                        )}
                                                    </span>
                                                    <span className="ml-auto text-xs text-muted-foreground">
                                                        <DateTime
                                                            value={
                                                                delivery.updated_at
                                                            }
                                                            relative
                                                        />
                                                    </span>
                                                </summary>
                                                <div className="mt-3 grid gap-3">
                                                    {delivery.error && (
                                                        <p className="text-sm text-destructive">
                                                            {delivery.error}
                                                        </p>
                                                    )}
                                                    {delivery.next_attempt_at && (
                                                        <p className="text-sm text-muted-foreground">
                                                            Next attempt{' '}
                                                            <DateTime
                                                                value={
                                                                    delivery.next_attempt_at
                                                                }
                                                                relative
                                                            />
                                                        </p>
                                                    )}
                                                    <div>
                                                        <div className="mb-1 text-xs font-medium text-muted-foreground">
                                                            Request body · event{' '}
                                                            {delivery.event_id}
                                                        </div>
                                                        <pre className="max-h-72 overflow-auto rounded-md bg-muted/50 p-3 font-mono text-xs">
                                                            {delivery.payload}
                                                        </pre>
                                                    </div>
                                                    {delivery.response_body && (
                                                        <div>
                                                            <div className="mb-1 text-xs font-medium text-muted-foreground">
                                                                Response (first
                                                                2 KB)
                                                            </div>
                                                            <pre className="max-h-48 overflow-auto rounded-md bg-muted/50 p-3 font-mono text-xs whitespace-pre-wrap">
                                                                {
                                                                    delivery.response_body
                                                                }
                                                            </pre>
                                                        </div>
                                                    )}
                                                    {delivery.status !==
                                                        'pending' && (
                                                        <div>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    router.post(
                                                                        WebhookEndpointController.resend.url(
                                                                            {
                                                                                endpoint:
                                                                                    endpoint.id,
                                                                                delivery:
                                                                                    delivery.id,
                                                                            },
                                                                        ),
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <RefreshCw />
                                                                Resend
                                                            </Button>
                                                        </div>
                                                    )}
                                                </div>
                                            </details>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="border-t p-3">
                                <Pagination
                                    paginator={deliveries}
                                    itemLabel="deliveries"
                                />
                            </div>
                        </SectionCard>
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard title="Endpoint">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Events',
                                        value: endpoint.events.includes('*')
                                            ? 'All events'
                                            : endpoint.events.join(', '),
                                    },
                                    {
                                        label: 'Consecutive failures',
                                        value: String(
                                            endpoint.consecutive_failures,
                                        ),
                                    },
                                    {
                                        label: 'Last success',
                                        value: endpoint.last_success_at && (
                                            <DateTime
                                                value={endpoint.last_success_at}
                                                relative
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Previous secret valid until',
                                        value: endpoint.rotating_until && (
                                            <DateTime
                                                value={endpoint.rotating_until}
                                            />
                                        ),
                                    },
                                ]}
                            />
                            <div className="mt-4">
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            variant="outline"
                                            className="w-full"
                                        >
                                            <KeyRound />
                                            Rotate secret
                                        </Button>
                                    }
                                    title="Rotate the signing secret?"
                                    description="A new secret is shown once. The current one keeps working for 24 hours so you can update the receiver."
                                    confirmLabel="Rotate secret"
                                    action={WebhookEndpointController.rotate(
                                        endpoint.id,
                                    )}
                                />
                            </div>
                        </SectionCard>
                        <SectionCard title="Verifying requests">
                            <div className="grid gap-2 text-sm text-muted-foreground">
                                <p>
                                    Compute{' '}
                                    <code className="font-mono text-xs">
                                        HMAC-SHA256(secret, timestamp + "." +
                                        body)
                                    </code>{' '}
                                    and compare it with each{' '}
                                    <code className="font-mono text-xs">
                                        v1=
                                    </code>{' '}
                                    value in{' '}
                                    <code className="font-mono text-xs">
                                        X-Webhook-Signature
                                    </code>
                                    .
                                </p>
                                <p>
                                    Reject requests whose{' '}
                                    <code className="font-mono text-xs">
                                        X-Webhook-Timestamp
                                    </code>{' '}
                                    is older than five minutes, and ignore event
                                    ids you have already processed.
                                </p>
                            </div>
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}
