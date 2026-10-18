import { Form, Head, Link } from '@inertiajs/react';
import { Mail, X } from 'lucide-react';
import SpamRuleController from '@/actions/App/Modules/Support/Http/Controllers/Admin/SpamRuleController';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Admin/TicketController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { LaravelPaginated, SelectOption } from '@/types/app';

type Email = {
    id: number;
    from: string;
    to: string | null;
    subject: string | null;
    status: string;
    reason: string | null;
    ticket_id: number | null;
    ticket_number: string | null;
    received_at: string;
};

type Rule = { id: number; field: string; match: string; value: string };

function tone(status: string): StatusTone {
    if (
        status === 'ticket_created' ||
        status === 'reply_added' ||
        status === 'imported'
    ) {
        return 'success';
    }

    if (status === 'blocked') {
        return 'warning';
    }

    return status.startsWith('bounced') ? 'info' : 'danger';
}

const columns: DataTableColumn<Email>[] = [
    {
        key: 'subject',
        header: 'Email',
        primary: true,
        cell: (email) => (
            <div className="min-w-0">
                <div className="truncate">
                    {email.subject || '(no subject)'}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                    {email.from}
                </div>
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Outcome',
        cell: (email) => (
            <div className="grid gap-0.5">
                <StatusBadge status={email.status} tone={tone(email.status)} />
                {email.reason && (
                    <span className="text-xs text-muted-foreground">
                        {email.reason}
                    </span>
                )}
            </div>
        ),
    },
    {
        key: 'ticket',
        header: 'Ticket',
        cell: (email) =>
            email.ticket_id ? (
                <Link
                    href={TicketController.show(email.ticket_id)}
                    className="font-mono text-xs underline-offset-4 hover:underline"
                >
                    #{email.ticket_number}
                </Link>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        key: 'received',
        header: 'Received',
        align: 'right',
        cell: (email) => <DateTime value={email.received_at} relative />,
    },
];

export default function InboundIndex({
    emails,
    rules,
    statuses,
    filters,
    endpoint,
}: {
    emails: LaravelPaginated<Email>;
    rules: Rule[];
    statuses: SelectOption[];
    filters: Record<string, string>;
    endpoint: string;
}) {
    return (
        <>
            <Head title="Email piping" />
            <Page>
                <PageHeader
                    title="Email piping"
                    description="Every inbound email and what happened to it."
                    meta={
                        <span>
                            Webhook:{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs">
                                {endpoint}
                            </code>
                        </span>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <FilterBar
                            searchPlaceholder="Search sender or subject…"
                            filterKeys={['status']}
                            filters={
                                <FilterSelect
                                    name="status"
                                    label="Outcome"
                                    allLabel="All outcomes"
                                    options={statuses}
                                />
                            }
                        />
                        <DataTable
                            caption="Inbound emails"
                            columns={columns}
                            rows={emails.data}
                            getRowKey={(email) => email.id}
                            empty={
                                <EmptyState
                                    icon={Mail}
                                    title={
                                        filters.status || filters.search
                                            ? 'No emails match these filters'
                                            : 'No inbound email yet'
                                    }
                                    description="Point your mail provider's inbound webhook at the URL above."
                                />
                            }
                            footer={
                                <Pagination
                                    paginator={emails}
                                    itemLabel="emails"
                                />
                            }
                        />
                    </div>
                    <SectionCard
                        title="Spam rules"
                        description="Matching mail is dropped (case-insensitive)."
                    >
                        <ul className="mb-4 grid gap-1">
                            {rules.map((rule) => (
                                <li
                                    key={rule.id}
                                    className="flex items-center justify-between gap-2 rounded-md px-2 py-1 text-sm hover:bg-muted/50"
                                >
                                    <span className="min-w-0 truncate">
                                        <span className="text-muted-foreground">
                                            {rule.field} {rule.match}
                                        </span>{' '}
                                        <span className="font-medium">
                                            {rule.value}
                                        </span>
                                    </span>
                                    <ConfirmDialog
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                aria-label={`Remove rule ${rule.value}`}
                                            >
                                                <X className="size-3.5" />
                                            </Button>
                                        }
                                        title="Remove this spam rule?"
                                        description={`Mail where ${rule.field} ${rule.match} "${rule.value}" will be accepted again.`}
                                        confirmLabel="Remove rule"
                                        action={SpamRuleController.destroy(
                                            rule.id,
                                        )}
                                    />
                                </li>
                            ))}
                            {rules.length === 0 && (
                                <li className="text-sm text-muted-foreground">
                                    No spam rules.
                                </li>
                            )}
                        </ul>
                        <Form
                            {...SpamRuleController.store.form()}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="grid gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Select
                                            name="field"
                                            defaultValue="sender"
                                        >
                                            <SelectTrigger aria-label="Field">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="sender">
                                                    Sender
                                                </SelectItem>
                                                <SelectItem value="subject">
                                                    Subject
                                                </SelectItem>
                                                <SelectItem value="body">
                                                    Body
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Select
                                            name="match"
                                            defaultValue="contains"
                                        >
                                            <SelectTrigger aria-label="Match">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="contains">
                                                    contains
                                                </SelectItem>
                                                <SelectItem value="equals">
                                                    equals
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="flex gap-2">
                                        <Input
                                            name="value"
                                            placeholder="e.g. casino"
                                            aria-label="Value"
                                            required
                                            aria-invalid={!!errors.value}
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Add rule
                                        </Button>
                                    </div>
                                    {errors.value && (
                                        <p className="text-sm text-destructive">
                                            {errors.value}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}
