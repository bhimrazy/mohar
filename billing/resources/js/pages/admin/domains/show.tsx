import { Head, Link, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    MoreHorizontal,
    RefreshCw,
    Repeat,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import DomainActionController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainActionController';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
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
import { SettingSwitch } from '@/components/domains/setting-switch';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { fromMinor, toMinor } from '@/lib/money';
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
        sent_at: string;
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

function DetailsForm({
    domain,
    registrars,
}: {
    domain: Domain;
    registrars: string[];
}) {
    const form = useForm({
        registration_date: domain.registration_date ?? '',
        expiry_date: domain.expiry_date ?? '',
        next_due_date: domain.next_due_date,
        next_invoice_date: domain.next_invoice_date ?? domain.next_due_date,
        period_years: String(domain.period_years),
        first_payment_amount: fromMinor(
            domain.first_payment_amount ?? 0,
        ).toFixed(2),
        recurring_amount: fromMinor(domain.recurring_amount).toFixed(2),
        registrar_key: domain.registrar_key ?? 'manual',
        linked_service_id: domain.linked_service_id
            ? String(domain.linked_service_id)
            : '',
        auto_renew: domain.auto_renew,
        id_protection: domain.id_protection,
        dns_management: domain.dns_management,
        email_forwarding: domain.email_forwarding,
        recalculate_recurring: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            registration_date: data.registration_date || null,
            expiry_date: data.expiry_date || null,
            period_years: Number(data.period_years),
            first_payment_amount: toMinor(data.first_payment_amount),
            recurring_amount: toMinor(data.recurring_amount),
            registrar_key:
                data.registrar_key === 'manual' ? null : data.registrar_key,
            linked_service_id: data.linked_service_id
                ? Number(data.linked_service_id)
                : null,
        }));
        form.submit(DomainController.update(domain.id), {
            preserveScroll: true,
        });
    };

    const dateField = (
        key:
            | 'registration_date'
            | 'expiry_date'
            | 'next_due_date'
            | 'next_invoice_date',
        label: string,
        optional = false,
    ) => (
        <Field
            htmlFor={key}
            label={label}
            error={errors[key]}
            optional={optional}
        >
            <Input
                id={key}
                type="date"
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                aria-invalid={!!errors[key]}
                aria-describedby={`${key}-description`}
            />
        </Field>
    );

    const flags = [
        ['auto_renew', 'Auto-renew'],
        ['id_protection', 'ID protection'],
        ['dns_management', 'DNS management'],
        ['email_forwarding', 'Email forwarding'],
    ] as const;

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Billing and dates"
                description={
                    domain.last_synced_at
                        ? 'Registrar sync can overwrite manual date changes.'
                        : undefined
                }
                footer={
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Save changes
                    </Button>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {dateField('registration_date', 'Registered', true)}
                    {dateField('expiry_date', 'Expiry date', true)}
                    {dateField('next_due_date', 'Next due date')}
                    {dateField('next_invoice_date', 'Next invoice date')}
                    <Field
                        htmlFor="period_years"
                        label="Renewal period (years)"
                        error={errors.period_years}
                    >
                        <Input
                            id="period_years"
                            type="number"
                            min={1}
                            max={10}
                            value={form.data.period_years}
                            onChange={(event) =>
                                form.setData('period_years', event.target.value)
                            }
                            aria-describedby="period_years-description"
                        />
                    </Field>
                    <Field
                        htmlFor="registrar_key"
                        label="Registrar"
                        error={errors.registrar_key}
                    >
                        <Select
                            value={form.data.registrar_key}
                            onValueChange={(value) =>
                                form.setData('registrar_key', value)
                            }
                        >
                            <SelectTrigger
                                id="registrar_key"
                                aria-describedby="registrar_key-description"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="manual">
                                    Manual (no registrar)
                                </SelectItem>
                                {registrars.map((registrar) => (
                                    <SelectItem
                                        key={registrar}
                                        value={registrar}
                                    >
                                        {registrar}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        htmlFor="first_payment_amount"
                        label={`First payment (${domain.currency})`}
                        error={errors.first_payment_amount}
                    >
                        <Input
                            id="first_payment_amount"
                            inputMode="decimal"
                            value={form.data.first_payment_amount}
                            onChange={(event) =>
                                form.setData(
                                    'first_payment_amount',
                                    event.target.value,
                                )
                            }
                            aria-describedby="first_payment_amount-description"
                        />
                    </Field>
                    <Field
                        htmlFor="recurring_amount"
                        label={`Renewal amount (${domain.currency})`}
                        error={errors.recurring_amount}
                        hint="Locked at purchase; price list changes don't affect it."
                    >
                        <Input
                            id="recurring_amount"
                            inputMode="decimal"
                            value={form.data.recurring_amount}
                            disabled={form.data.recalculate_recurring}
                            onChange={(event) =>
                                form.setData(
                                    'recurring_amount',
                                    event.target.value,
                                )
                            }
                            aria-describedby="recurring_amount-description"
                        />
                    </Field>
                    <Field
                        htmlFor="linked_service_id"
                        label="Linked service ID"
                        optional
                        error={errors.linked_service_id}
                        hint="For free domains bundled with hosting."
                    >
                        <Input
                            id="linked_service_id"
                            inputMode="numeric"
                            value={form.data.linked_service_id}
                            onChange={(event) =>
                                form.setData(
                                    'linked_service_id',
                                    event.target.value,
                                )
                            }
                            aria-describedby="linked_service_id-description"
                        />
                    </Field>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <Checkbox
                            checked={form.data.recalculate_recurring}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'recalculate_recurring',
                                    checked === true,
                                )
                            }
                        />
                        Recalculate renewal amount from current prices
                    </label>
                    <div className="grid gap-2 sm:col-span-2 sm:grid-cols-2">
                        {flags.map(([key, label]) => (
                            <label
                                key={key}
                                className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm font-medium"
                            >
                                {label}
                                <Switch
                                    checked={form.data[key]}
                                    onCheckedChange={(checked) =>
                                        form.setData(key, checked)
                                    }
                                />
                            </label>
                        ))}
                    </div>
                </div>
            </SectionCard>
        </form>
    );
}

function ForceStatusDialog({
    domain,
    statuses,
    open,
    onOpenChange,
}: {
    domain: Domain;
    statuses: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ status: domain.status as string, reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(DomainActionController.status(domain.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('reason');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            Change status of {domain.name_unicode}?
                        </DialogTitle>
                        <DialogDescription>
                            Forcing a status skips the normal lifecycle rules
                            and does not call the registrar. The reason is kept
                            in the activity log.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        htmlFor="force-status"
                        label="New status"
                        error={form.errors.status}
                    >
                        <Select
                            value={form.data.status}
                            onValueChange={(value) =>
                                form.setData('status', value)
                            }
                        >
                            <SelectTrigger id="force-status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {statusLabel(status)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        htmlFor="force-reason"
                        label="Reason"
                        error={form.errors.reason}
                    >
                        <Textarea
                            id="force-reason"
                            rows={3}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            aria-invalid={!!form.errors.reason}
                            aria-describedby="force-reason-description"
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Change status
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MoveDialog({
    domain,
    open,
    onOpenChange,
}: {
    domain: Domain;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ client_id: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ client_id: Number(data.client_id) }));
        form.submit(DomainActionController.move(domain.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            Move {domain.name_unicode} to another client?
                        </DialogTitle>
                        <DialogDescription>
                            Existing invoices stay with{' '}
                            {domain.client?.display_name ?? 'the current owner'}
                            . The next renewal invoice goes to the new client.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        htmlFor="move-client"
                        label="Client ID"
                        error={form.errors.client_id}
                    >
                        <Input
                            id="move-client"
                            inputMode="numeric"
                            value={form.data.client_id}
                            onChange={(event) =>
                                form.setData('client_id', event.target.value)
                            }
                            aria-invalid={!!form.errors.client_id}
                            aria-describedby="move-client-description"
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.client_id}
                        >
                            {form.processing && <Spinner />}
                            Move domain
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

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
}: Props) {
    const [dialog, setDialog] = useState<
        'status' | 'move' | 'renew' | 'submit' | 'delete' | null
    >(null);
    const supports = (capability: string) => capabilities.includes(capability);
    const isPending = domain.status === 'pending';
    const isManual = domain.registrar_key === null;

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
                                    : domain.registrar_key}
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
                                        {can.commands && !isPending && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setDialog('renew')
                                                }
                                            >
                                                Renew {domain.period_years} year
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
                                ? `Invoice on ${domain.next_invoice_date}`
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
                                        disabled={isPending}
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
                                        disabled={isPending}
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

                        <SectionCard title="Expiry reminders">
                            {reminders.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No reminders sent yet.
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
                                                value={reminder.sent_at}
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
