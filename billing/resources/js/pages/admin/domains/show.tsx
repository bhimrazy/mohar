import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    MoreHorizontal,
    RefreshCw,
    Repeat,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import DomainActionController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainActionController';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge, statusLabel } from '@/components/app/status-badge';
import { ActionErrors } from '@/components/domains/action-errors';
import { ContactsForm } from '@/components/domains/contacts-form';
import { EppCode } from '@/components/domains/epp-code';
import { NameserversForm } from '@/components/domains/nameservers-form';
import {
    DetailsForm,
    ForceStatusDialog,
    MoveDialog,
} from '@/components/domains/admin-domain-forms';
import { SettingSwitch } from '@/components/domains/setting-switch';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate } from '@/lib/date';
import { expiryHint } from '@/types/domains';
import type { Domain, DomainContacts } from '@/types/domains';

type CommandLogEntry = {
    id: number;
    command: string;
    success: boolean;
    error: string | null;
    duration_ms: number;
    staff: string | null;
    created_at: string | null;
};

type Props = {
    domain: Domain;
    contacts: DomainContacts;
    capabilities: string[];
    registrars: string[];
    statuses: string[];
    allowedStatuses: string[];
    commandLog: CommandLogEntry[];
    reminders: {
        id: number;
        expiry_date: string;
        offset_days: number;
        dispatched_at: string;
    }[];
    invoices: {
        id: number;
        invoice_id: number;
        kind: string;
        period_start: string;
        years: number;
        status: string;
    }[];
    activity: {
        id: number;
        description: string;
        user: string | null;
        created_at: string | null;
    }[];
    can: { update: boolean; commands: boolean; delete: boolean };
    registrar: { key: string; name: string; active: boolean } | null;
    billingAvailable: boolean;
};

const logColumns: DataTableColumn<CommandLogEntry>[] = [
    {
        key: 'command',
        header: 'Command',
        primary: true,
        cell: (entry) => (
            <span className="font-mono text-xs">{entry.command}</span>
        ),
    },
    {
        key: 'result',
        header: 'Result',
        cell: (entry) => (
            <StatusBadge
                status={entry.success ? 'completed' : 'failed'}
                label={entry.success ? 'OK' : 'Failed'}
            />
        ),
    },
    {
        key: 'error',
        header: 'Error',
        cell: (entry) => entry.error ?? '—',
        className: 'max-w-72 truncate',
    },
    {
        key: 'by',
        header: 'By',
        hideOnMobile: true,
        cell: (entry) => entry.staff ?? 'System',
    },
    {
        key: 'duration',
        header: 'Time',
        align: 'right',
        hideOnMobile: true,
        cell: (entry) => `${entry.duration_ms} ms`,
    },
    {
        key: 'at',
        header: 'When',
        align: 'right',
        cell: (entry) => <DateTime value={entry.created_at} relative />,
    },
];

export default function DomainShow({
    domain,
    contacts,
    capabilities,
    registrars,
    statuses,
    commandLog,
    reminders,
    invoices,
    activity,
    can,
    registrar,
    billingAvailable,
}: Props) {
    const [dialog, setDialog] = useState<
        'status' | 'move' | 'renew' | 'submit' | 'delete' | 'invoice' | null
    >(null);
    const supports = (capability: string) => capabilities.includes(capability);
    const isPending = domain.status === 'pending';
    const isManual = domain.registrar_key === null;
    const isLive = ['active', 'grace', 'redemption'].includes(domain.status);
    const canRenewAtRegistrar = isLive || domain.status === 'expired';

    return (
        <>
            <Head title={domain.name_unicode} />
            <Page>
                <PageHeader
                    back={{ href: DomainController.index(), label: 'Domains' }}
                    title={domain.name_unicode}
                    status={domain.status}
                    meta={
                        <>
                            {domain.client && (
                                <Link
                                    href={ClientController.show(
                                        domain.client.id,
                                    )}
                                    className="hover:underline"
                                >
                                    {domain.client.display_name}
                                </Link>
                            )}
                            <span aria-hidden="true">·</span>
                            <span>
                                {isManual
                                    ? 'Manual registrar'
                                    : (registrar?.name ?? domain.registrar_key)}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>
                                {domain.type === 'transfer'
                                    ? 'Transfer'
                                    : 'Registration'}
                            </span>
                            {domain.name !== domain.name_unicode && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span className="font-mono text-xs">
                                        {domain.name}
                                    </span>
                                </>
                            )}
                        </>
                    }
                    actions={
                        <>
                            {(can.update || can.delete || can.commands) && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label="More actions"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {can.commands && isPending && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setDialog('submit')
                                                }
                                            >
                                                {domain.type === 'transfer'
                                                    ? 'Submit transfer'
                                                    : 'Register now'}
                                            </DropdownMenuItem>
                                        )}
                                        {can.update &&
                                            isLive &&
                                            billingAvailable && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog('invoice')
                                                    }
                                                >
                                                    Create renewal invoice
                                                </DropdownMenuItem>
                                            )}
                                        {can.commands &&
                                            canRenewAtRegistrar && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog('renew')
                                                    }
                                                >
                                                    Renew {domain.period_years}{' '}
                                                    year
                                                    {domain.period_years === 1
                                                        ? ''
                                                        : 's'}
                                                </DropdownMenuItem>
                                            )}
                                        {can.update && (
                                            <>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog('status')
                                                    }
                                                >
                                                    Change status…
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog('move')
                                                    }
                                                >
                                                    Move to another client…
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                        {can.delete && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDialog('delete')
                                                    }
                                                >
                                                    Delete record
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {can.commands &&
                                !isManual &&
                                !isPending &&
                                supports('sync') && (
                                    <ConfirmDialog
                                        trigger={
                                            <Button>
                                                <RefreshCw />
                                                Sync now
                                            </Button>
                                        }
                                        title={`Sync ${domain.name_unicode} with the registrar?`}
                                        description="Status and expiry are read from the registrar and applied here. Manual date edits may be overwritten."
                                        confirmLabel="Sync now"
                                        destructive={false}
                                        action={DomainActionController.command({
                                            domain: domain.id,
                                            command: 'sync',
                                        })}
                                    />
                                )}
                        </>
                    }
                />

                <ActionErrors />

                {domain.needs_review && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>
                            Registrar outcome needs verification
                        </AlertTitle>
                        <AlertDescription>
                            {domain.last_error ??
                                'A registrar command may or may not have completed.'}{' '}
                            Nothing is re-sent automatically. Check the
                            registrar, then use Sync now or Change status.
                        </AlertDescription>
                    </Alert>
                )}

                {registrar && !registrar.active && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>{registrar.name} is disabled</AlertTitle>
                        <AlertDescription>
                            Registrar commands for this domain are refused until
                            the registrar is enabled again.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Expires"
                        icon={CalendarClock}
                        value={<DateTime value={domain.expiry_date} />}
                        hint={expiryHint(domain.days_until_expiry) ?? undefined}
                    />
                    <StatCard
                        label="Next due"
                        icon={CalendarClock}
                        value={<DateTime value={domain.next_due_date} />}
                        hint={
                            domain.next_invoice_date
                                ? `Invoice on ${formatDate(domain.next_invoice_date)}`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Renewal"
                        icon={Wallet}
                        value={
                            <Money
                                amount={domain.recurring_amount}
                                currency={domain.currency}
                            />
                        }
                        hint={`${domain.period_years} year${domain.period_years === 1 ? '' : 's'} · auto-renew ${domain.auto_renew ? 'on' : 'off'}`}
                    />
                    <StatCard
                        label="Last sync"
                        icon={Repeat}
                        value={
                            domain.last_synced_at ? (
                                <DateTime
                                    value={domain.last_synced_at}
                                    relative
                                />
                            ) : (
                                'Never'
                            )
                        }
                        hint={domain.last_sync_status ?? undefined}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        {can.update ? (
                            <DetailsForm
                                domain={domain}
                                registrars={registrars}
                            />
                        ) : (
                            <SectionCard title="Billing and dates">
                                <DescriptionList
                                    items={[
                                        {
                                            label: 'Registered',
                                            value: (
                                                <DateTime
                                                    value={
                                                        domain.registration_date
                                                    }
                                                />
                                            ),
                                        },
                                        {
                                            label: 'Expiry date',
                                            value: (
                                                <DateTime
                                                    value={domain.expiry_date}
                                                />
                                            ),
                                        },
                                        {
                                            label: 'Next due',
                                            value: (
                                                <DateTime
                                                    value={domain.next_due_date}
                                                />
                                            ),
                                        },
                                        {
                                            label: 'Renewal amount',
                                            value: (
                                                <Money
                                                    amount={
                                                        domain.recurring_amount
                                                    }
                                                    currency={domain.currency}
                                                />
                                            ),
                                        },
                                    ]}
                                />
                            </SectionCard>
                        )}

                        <NameserversForm
                            action={DomainActionController.nameservers(
                                domain.id,
                            )}
                            nameservers={domain.nameservers}
                            disabled={
                                !can.update ||
                                (!isManual && !supports('nameservers'))
                            }
                            description={
                                isManual
                                    ? 'Manual domain: saving records the change and creates a staff task.'
                                    : undefined
                            }
                        />

                        <ContactsForm
                            action={DomainActionController.contacts(domain.id)}
                            contacts={contacts}
                            disabled={
                                !can.update ||
                                (!isManual && !supports('contacts'))
                            }
                        />

                        <SectionCard
                            title="Registrar command log"
                            description="Every call to the registrar, newest first. Secrets are redacted."
                            flush
                        >
                            <DataTable
                                className="rounded-none border-0 shadow-none"
                                caption="Registrar command log"
                                columns={logColumns}
                                rows={commandLog}
                                getRowKey={(entry) => entry.id}
                                empty={
                                    <EmptyState
                                        title="No registrar commands yet"
                                        description="Commands appear here after registration, renewals and syncs."
                                    />
                                }
                            />
                        </SectionCard>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard title="Registrar">
                            <div className="grid gap-3">
                                {domain.last_error && (
                                    <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                        {domain.last_error}
                                    </p>
                                )}
                                {supports('lock') && can.update ? (
                                    <SettingSwitch
                                        id="registrar-lock"
                                        title="Transfer lock"
                                        description="Blocks transfers to another registrar."
                                        checked={domain.registrar_lock === true}
                                        action={DomainActionController.lock(
                                            domain.id,
                                        )}
                                        disabled={domain.status.startsWith(
                                            'pending',
                                        )}
                                    />
                                ) : (
                                    <DescriptionList
                                        columns={1}
                                        items={[
                                            {
                                                label: 'Transfer lock',
                                                value: supports('lock')
                                                    ? domain.registrar_lock
                                                        ? 'Locked'
                                                        : 'Unlocked'
                                                    : 'Not supported by this registrar',
                                            },
                                        ]}
                                    />
                                )}
                                {supports('epp') && can.commands && (
                                    <EppCode
                                        action={DomainActionController.epp(
                                            domain.id,
                                        )}
                                        domainName={domain.name_unicode}
                                        audience="staff"
                                        disabled={domain.status.startsWith(
                                            'pending',
                                        )}
                                    />
                                )}
                                <DescriptionList
                                    columns={1}
                                    items={[
                                        {
                                            label: 'EPP code on file',
                                            value:
                                                domain.type === 'transfer'
                                                    ? domain.has_epp_code
                                                        ? 'Yes (encrypted)'
                                                        : 'No'
                                                    : 'Not applicable',
                                        },
                                        {
                                            label: 'Capabilities',
                                            value: capabilities.length
                                                ? capabilities.join(', ')
                                                : 'None (manual)',
                                        },
                                    ]}
                                />
                            </div>
                        </SectionCard>

                        <SectionCard title="Invoices">
                            {invoices.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No invoices for this domain yet.
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {invoices.map((invoice) => (
                                        <li
                                            key={invoice.id}
                                            className="flex items-center justify-between gap-2 py-2"
                                        >
                                            <span>
                                                #{invoice.invoice_id} ·{' '}
                                                {statusLabel(invoice.kind)}
                                                <span className="block text-xs text-muted-foreground">
                                                    from {invoice.period_start}
                                                </span>
                                            </span>
                                            <StatusBadge
                                                status={
                                                    invoice.status === 'open'
                                                        ? 'unpaid'
                                                        : invoice.status
                                                }
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard
                            title="Expiry reminders"
                            description="Dispatched to Notifications for delivery."
                        >
                            {reminders.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No reminders dispatched yet.
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {reminders.map((reminder) => (
                                        <li
                                            key={reminder.id}
                                            className="flex justify-between gap-2 py-2"
                                        >
                                            <span>
                                                {reminder.offset_days >= 0
                                                    ? `${reminder.offset_days} days before`
                                                    : `${Math.abs(reminder.offset_days)} days after`}
                                            </span>
                                            <DateTime
                                                value={reminder.dispatched_at}
                                                relative
                                                className="text-muted-foreground"
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard title="Activity">
                            {activity.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No activity yet.
                                </p>
                            ) : (
                                <ul className="grid gap-3 text-sm">
                                    {activity.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="grid gap-0.5"
                                        >
                                            <span>{entry.description}</span>
                                            <span className="text-xs text-muted-foreground">
                                                {entry.user ?? 'System'} ·{' '}
                                                <DateTime
                                                    value={entry.created_at}
                                                    relative
                                                />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>
                </div>
            </Page>

            {can.update && (
                <>
                    <ForceStatusDialog
                        domain={domain}
                        statuses={statuses}
                        open={dialog === 'status'}
                        onOpenChange={(open) =>
                            setDialog(open ? 'status' : null)
                        }
                    />
                    <MoveDialog
                        domain={domain}
                        open={dialog === 'move'}
                        onOpenChange={(open) => setDialog(open ? 'move' : null)}
                    />
                </>
            )}
            <ConfirmDialog
                open={dialog === 'renew'}
                onOpenChange={(open) => setDialog(open ? 'renew' : null)}
                title={`Renew ${domain.name_unicode} for ${domain.period_years} year${domain.period_years === 1 ? '' : 's'}?`}
                description={
                    isManual
                        ? 'The dates are extended here and a staff task is created to renew at the registrar. No invoice is created.'
                        : 'The renewal is sent to the registrar now and charged to your registrar account. No invoice is created.'
                }
                confirmLabel="Renew domain"
                destructive={false}
                action={DomainActionController.command({
                    domain: domain.id,
                    command: 'renew',
                })}
            />
            <ConfirmDialog
                open={dialog === 'invoice'}
                onOpenChange={(open) => setDialog(open ? 'invoice' : null)}
                title={`Create a renewal invoice for ${domain.name_unicode}?`}
                description="The client is invoiced now for the next period at the locked renewal amount. The domain renews at the registrar when the invoice is paid."
                confirmLabel="Create renewal invoice"
                destructive={false}
                action={DomainActionController.renewalInvoice(domain.id)}
            />
            <ConfirmDialog
                open={dialog === 'submit'}
                onOpenChange={(open) => setDialog(open ? 'submit' : null)}
                title={
                    domain.type === 'transfer'
                        ? `Submit the transfer of ${domain.name_unicode}?`
                        : `Register ${domain.name_unicode} now?`
                }
                description="The request is queued for the registrar even if the order invoice is unpaid."
                confirmLabel={
                    domain.type === 'transfer'
                        ? 'Submit transfer'
                        : 'Register domain'
                }
                destructive={false}
                action={DomainActionController.command({
                    domain: domain.id,
                    command: domain.type,
                })}
            />
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(open) => setDialog(open ? 'delete' : null)}
                title={`Delete the record of ${domain.name_unicode}?`}
                description="Only this record is removed; nothing is sent to the registrar and the domain stays registered there."
                confirmLabel="Delete record"
                confirmText={domain.name_unicode}
                action={DomainController.destroy(domain.id)}
            />
        </>
    );
}
