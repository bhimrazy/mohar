import { setLayoutProps, usePage } from '@inertiajs/react';
import { CircleAlert, Clock, MailCheck, MailPlus, Send } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { count } from '@/components/migration/import-status';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ImportInvitationController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ImportInvitationController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import type { LaravelPaginated } from '@/types/app';

type State = 'not_invited' | 'open' | 'expired' | 'queued';

type Login = {
    name: string;
    email: string;
    client: string;
    client_href: string;
    state: State;
    not_sent: string | null;
    invited_at: string | null;
    expires_at: string | null;
};

type Props = {
    run: { id: number; name: string; status: string };
    counts: Record<State | 'total', number>;
    logins: LaravelPaginated<Login>;
    filters: {
        scope: 'created' | 'all';
        group: string | null;
        status: 'active' | 'inactive' | null;
    };
    groups: { value: string; label: string }[];
    perHour: number;
    sending: number;
    held: string | null;
    blocked: string | null;
};

const STATES = {
    not_invited: { label: 'Not invited', tone: 'neutral' },
    queued: { label: 'Waiting to send', tone: 'info' },
    open: { label: 'Invitation sent', tone: 'success' },
    expired: { label: 'Invitation expired', tone: 'warning' },
} as const;

const columns: DataTableColumn<Login>[] = [
    {
        key: 'login',
        header: 'Login',
        primary: true,
        cell: (login) => (
            <span className="flex flex-col">
                <span className="font-medium">{login.name}</span>
                <span className="text-sm break-all text-muted-foreground">
                    {login.email}
                </span>
            </span>
        ),
    },
    {
        key: 'client',
        header: 'Client',
        cell: (login) => (
            <a
                href={login.client_href}
                className="whitespace-normal text-primary hover:underline"
            >
                {login.client}
            </a>
        ),
    },
    {
        key: 'state',
        header: 'Invitation',
        cell: (login) => (
            <span className="flex flex-col gap-1">
                <StatusBadge
                    status={login.state}
                    label={STATES[login.state].label}
                    tone={STATES[login.state].tone}
                />
                {login.not_sent && (
                    <span className="text-xs whitespace-normal text-destructive">
                        Not sent: {login.not_sent}
                    </span>
                )}
                {login.state === 'open' && login.expires_at && (
                    <span className="text-xs whitespace-normal text-muted-foreground">
                        Link works until{' '}
                        <DateTime value={login.expires_at} format="datetime" />
                    </span>
                )}
            </span>
        ),
    },
];

function hours(logins: number, perHour: number): string {
    const total = Math.ceil(logins / perHour);

    return total <= 1 ? 'within an hour' : `in about ${count(total)} hours`;
}

export default function ImportInvitations({
    run,
    counts,
    logins,
    filters,
    groups,
    perHour,
    sending,
    held,
    blocked,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const toSend = counts.not_invited + counts.expired;
    const reason =
        blocked ??
        (toSend === 0 && sending === 0
            ? 'Every login in this list has been invited or is waiting to be sent.'
            : null);
    const query = {
        scope: filters.scope,
        group: filters.group,
        status: filters.status,
    };

    setLayoutProps({
        breadcrumbs: [
            { title: 'Clients', href: ClientController.index() },
            { title: 'Import clients', href: ClientImportController.index() },
            { title: run.name, href: ClientImportController.show(run.id) },
            {
                title: 'Invitations',
                href: ImportInvitationController.index(run.id),
            },
        ],
    });

    return (
        <>
            <Head title={`Invitations · ${run.name}`} />
            <Page>
                <PageHeader
                    back={{
                        href: ClientImportController.show(run.id),
                        label: 'Import report',
                    }}
                    title="Send invitations"
                    description="Imported logins have no password. An invitation mails each one a link, valid once, that sets the password and confirms the email address. Closed clients are never invited."
                    actions={
                        <ConfirmDialog
                            trigger={
                                <Button disabled={reason !== null}>
                                    <Send />
                                    {toSend > 0
                                        ? `Send ${count(toSend)} ${toSend === 1 ? 'invitation' : 'invitations'}`
                                        : sending > 0
                                          ? 'Continue sending'
                                          : 'Send invitations'}
                                </Button>
                            }
                            title={
                                toSend > 0
                                    ? `Send ${count(toSend)} ${toSend === 1 ? 'invitation' : 'invitations'}?`
                                    : 'Continue sending the waiting invitations?'
                            }
                            description={
                                toSend > 0
                                    ? `Each login in this list that was not invited, or whose invitation expired, gets an email with its own link. They go out at most ${count(perHour)} an hour, so these are sent ${hours(toSend + sending, perHour)}. A sent invitation cannot be sent again from here; send a new link from the client's logins.`
                                    : `${count(sending)} invitations are waiting. They go out at most ${count(perHour)} an hour.`
                            }
                            confirmLabel={
                                toSend > 0
                                    ? 'Send invitations'
                                    : 'Continue sending'
                            }
                            destructive={false}
                            action={ImportInvitationController.store(run.id)}
                            data={query}
                        />
                    }
                />

                {reason && (
                    <p className="text-sm text-muted-foreground">{reason}</p>
                )}

                {held && !blocked && (
                    <Alert>
                        <CircleAlert />
                        <AlertDescription>{held}</AlertDescription>
                    </Alert>
                )}

                {errors.invitations && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertDescription>
                            {errors.invitations}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Logins without a password"
                        value={count(counts.total)}
                        hint="In this list"
                        icon={MailPlus}
                    />
                    <StatCard
                        label="Not invited"
                        value={count(counts.not_invited)}
                        hint={
                            counts.expired > 0
                                ? `and ${count(counts.expired)} expired`
                                : undefined
                        }
                        icon={Send}
                    />
                    <StatCard
                        label="Waiting to send"
                        value={count(counts.queued)}
                        hint={`At most ${count(perHour)} an hour`}
                        icon={Clock}
                    />
                    <StatCard
                        label="Invitation sent"
                        value={count(counts.open)}
                        hint="Link not used yet"
                        icon={MailCheck}
                    />
                </div>

                <FilterBar
                    searchParam={null}
                    filterKeys={['scope', 'group', 'status']}
                    filters={
                        <>
                            <FilterSelect
                                name="scope"
                                label="Clients"
                                allLabel="Created by this import"
                                className="sm:w-72"
                                options={[
                                    {
                                        value: 'all',
                                        label: 'Created or updated by this import',
                                    },
                                ]}
                            />
                            <FilterSelect
                                name="group"
                                label="Client group"
                                allLabel="Every group"
                                options={[
                                    { value: 'none', label: 'No group' },
                                    ...groups,
                                ]}
                            />
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="Active and inactive"
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                            />
                        </>
                    }
                />

                <DataTable
                    caption="Logins without a password"
                    columns={columns}
                    rows={logins.data}
                    getRowKey={(login) => login.email}
                    empty={
                        <EmptyState
                            icon={MailCheck}
                            title="No login to invite"
                            description="Every imported login in this list has a password, has signed in, or belongs to a closed client."
                        />
                    }
                    footer={
                        <Pagination paginator={logins} itemLabel="logins" />
                    }
                />
            </Page>
        </>
    );
}
