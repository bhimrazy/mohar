import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    CalendarClock,
    HardDrive,
    MoreHorizontal,
    Package,
    Pencil,
    Receipt,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import InputError from '@/components/input-error';
import { labelWithCurrency, toMinor } from '@/lib/money';
import { DataTable } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { ChangeOptionsPicker } from '@/components/services/change-options';
import { FormDialog } from '@/components/services/form-dialog';
import { ModuleLogTable } from '@/components/services/module-log-table';
import { PasswordReveal } from '@/components/services/password-reveal';
import { UsageMeter } from '@/components/services/usage-meter';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
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
import { Textarea } from '@/components/ui/textarea';
import ServiceActionController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceActionController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import { index as moduleLogIndex } from '@/routes/admin/services/module-log';
import { index as servicesIndex } from '@/routes/admin/services';
import { show as showClient } from '@/routes/admin/clients';
import type { SelectOption } from '@/types/app';
import {
    CYCLE_LABELS,
    cycleLabel,
    suspensionReasonLabel,
} from '@/types/services';
import type {
    AddonRow,
    ChangeOptions,
    ModuleCommandRow,
    ServiceRow,
    UsageData,
} from '@/types/services';

type InvoiceLink = {
    id: number;
    invoice_id: number;
    invoice_number: string | null;
    invoice_url: string | null;
    kind: string;
    period_start: string | null;
    period_end: string | null;
    amount: number;
    status: string;
};

type ChangeRow = {
    id: number;
    kind: string;
    from: string | null;
    to: string | null;
    to_cycle: string | null;
    amount_due: number;
    currency: string;
    status: string;
    invoice_id: number | null;
    created_at: string | null;
};

type Props = {
    service: ServiceRow;
    addons: AddonRow[];
    invoices: InvoiceLink[];
    changes: ChangeRow[];
    cancellation: {
        id: number;
        type: string;
        reason: string | null;
        process_after: string;
    } | null;
    moduleLog: ModuleCommandRow[];
    usage: UsageData;
    driver: { key: string; name: string; requires_server: boolean };
    commands: SelectOption[];
    statusTargets: SelectOption[];
    changeOptions?: ChangeOptions;
    creditOnDowngrade: boolean;
    paymentGatewayLabel: string | null;
    order: { number: string | null; url: string | null } | null;
    can: {
        manage: boolean;
        provision: boolean;
        credentials: boolean;
        moduleLog: boolean;
    };
};

type DialogName =
    | 'suspend'
    | 'password'
    | 'status'
    | 'cancel'
    | 'terminate'
    | 'delete'
    | 'addon'
    | null;

const PRIMARY_COMMANDS = ['create', 'unsuspend', 'suspend'];

function CommandsMenu({
    commands,
    onSelect,
}: {
    commands: SelectOption[];
    onSelect: (command: string) => void;
}) {
    return (
        <>
            {commands.map((command) => (
                <DropdownMenuItem
                    key={command.value}
                    variant={
                        command.value === 'terminate'
                            ? 'destructive'
                            : 'default'
                    }
                    onSelect={() => onSelect(command.value)}
                >
                    {command.label}
                </DropdownMenuItem>
            ))}
        </>
    );
}

export default function ServiceShow({
    service,
    addons,
    invoices,
    changes,
    cancellation,
    moduleLog,
    usage,
    driver,
    commands,
    statusTargets,
    changeOptions,
    creditOnDowngrade,
    paymentGatewayLabel,
    order,
    can,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [dialog, setDialog] = useState<DialogName>(null);
    const commandForm = useForm({ command: '', reason: '', password: '' });
    const statusForm = useForm({ status: '', note: '' });
    const cancelForm = useForm({ type: 'end_of_period', reason: '' });
    const addonForm = useForm({
        name: '',
        cycle: service.cycle,
        recurring_amount: '0.00',
        setup_fee: '0.00',
        status: 'active',
        suspend_parent_on_overdue: false,
    });

    const commandAction = ServiceActionController.command(service.id);
    const primary = commands.find((command) =>
        PRIMARY_COMMANDS.includes(command.value),
    );
    const secondary = commands.filter((command) => command !== primary);

    const runCommand = (command: string) => {
        if (command === 'suspend') {
            commandForm.setData({ command, reason: '', password: '' });
            setDialog('suspend');

            return;
        }

        if (command === 'change_password') {
            commandForm.setData({ command, reason: '', password: '' });
            setDialog('password');

            return;
        }

        if (command === 'terminate') {
            setDialog('terminate');

            return;
        }

        router.post(commandAction.url, { command }, { preserveScroll: true });
    };

    const submitCommand = () =>
        commandForm.submit(commandAction, {
            preserveScroll: true,
            onSuccess: () => setDialog(null),
        });

    const name = service.domain ?? service.label;

    return (
        <>
            <Head title={name} />
            <Page>
                <PageHeader
                    back={{ href: servicesIndex(), label: 'Services' }}
                    title={name}
                    status={service.status}
                    badges={
                        service.is_overdue ? (
                            <StatusBadge status="overdue" />
                        ) : undefined
                    }
                    meta={
                        <>
                            <span>{service.product_name}</span>
                            {service.client && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <Link
                                        href={showClient(service.client.id)}
                                        className="hover:text-foreground hover:underline"
                                    >
                                        {service.client.name}
                                    </Link>
                                </>
                            )}
                        </>
                    }
                    actions={
                        <>
                            {(can.manage ||
                                (can.provision && secondary.length > 0)) && (
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
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-56"
                                    >
                                        {can.provision &&
                                            secondary.length > 0 && (
                                                <>
                                                    <DropdownMenuLabel>
                                                        Module ({driver.name})
                                                    </DropdownMenuLabel>
                                                    <CommandsMenu
                                                        commands={secondary}
                                                        onSelect={runCommand}
                                                    />
                                                </>
                                            )}
                                        {can.manage && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setDialog('status')
                                                    }
                                                    disabled={
                                                        statusTargets.length ===
                                                        0
                                                    }
                                                >
                                                    Set status without module
                                                </DropdownMenuItem>
                                                {!cancellation &&
                                                    [
                                                        'active',
                                                        'suspended',
                                                    ].includes(
                                                        service.status,
                                                    ) && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setDialog(
                                                                    'cancel',
                                                                )
                                                            }
                                                        >
                                                            Request cancellation
                                                        </DropdownMenuItem>
                                                    )}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDialog('delete')
                                                    }
                                                >
                                                    Delete service record
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {can.manage && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={ServiceController.edit(
                                            service.id,
                                        )}
                                    >
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.provision && primary && (
                                <Button
                                    variant={
                                        primary.value === 'suspend'
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() => runCommand(primary.value)}
                                >
                                    {primary.value === 'create'
                                        ? 'Create account'
                                        : `${primary.label} service`}
                                </Button>
                            )}
                        </>
                    }
                />

                {errors.action && (
                    <Alert variant="destructive">
                        <AlertTitle>Action failed</AlertTitle>
                        <AlertDescription>{errors.action}</AlertDescription>
                    </Alert>
                )}

                {cancellation && (
                    <Alert>
                        <CalendarClock />
                        <AlertTitle>
                            Cancellation requested (
                            {cancellation.type === 'immediate'
                                ? 'immediately'
                                : 'end of billing period'}
                            )
                        </AlertTitle>
                        <AlertDescription>
                            <span>
                                Processed on{' '}
                                <DateTime value={cancellation.process_after} />
                                {cancellation.reason &&
                                    ` · “${cancellation.reason}”`}
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Recurring"
                        value={
                            <Money
                                amount={service.recurring_amount}
                                currency={service.currency}
                            />
                        }
                        hint={cycleLabel(service.cycle)}
                        icon={Wallet}
                    />
                    <StatCard
                        label="Next due"
                        value={<DateTime value={service.next_due_date} />}
                        hint={
                            service.next_invoice_date ? (
                                <>
                                    Invoiced through{' '}
                                    <DateTime
                                        value={service.next_invoice_date}
                                    />
                                </>
                            ) : (
                                'No renewals'
                            )
                        }
                        icon={CalendarClock}
                    />
                    <StatCard
                        label="Renewals invoiced"
                        value={service.renewals_invoiced ?? 0}
                        hint={
                            service.recurring_cycles_limit
                                ? `of ${service.recurring_cycles_limit} allowed`
                                : 'No cycle limit'
                        }
                        icon={Receipt}
                    />
                    <StatCard
                        label="Disk used"
                        value={
                            usage
                                ? `${Math.round((usage.disk_used_mb / 1024) * 10) / 10} GB`
                                : '—'
                        }
                        hint={usage ? 'Last usage sync' : 'No usage data yet'}
                        icon={HardDrive}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <SectionCard title="Overview">
                            <DescriptionList
                                items={[
                                    {
                                        label: 'Product',
                                        value: service.product_name,
                                    },
                                    { label: 'Domain', value: service.domain },
                                    {
                                        label: 'Billing cycle',
                                        value: cycleLabel(service.cycle),
                                    },
                                    {
                                        label: 'First payment',
                                        value: (
                                            <Money
                                                amount={
                                                    service.first_payment_amount
                                                }
                                                currency={service.currency}
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Registered',
                                        value: (
                                            <DateTime
                                                value={
                                                    service.registration_date
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Next invoice',
                                        value: (
                                            <DateTime
                                                value={
                                                    service.next_invoice_date
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Billing anchor day',
                                        value: service.billing_anchor_day,
                                    },
                                    {
                                        label: 'Payment method',
                                        value: paymentGatewayLabel,
                                    },
                                    {
                                        label: 'Suspension reason',
                                        value: suspensionReasonLabel(
                                            service.suspension_reason ?? null,
                                        ),
                                    },
                                    {
                                        label: 'Automation exempt until',
                                        value: service.suspend_exempt_until ? (
                                            <DateTime
                                                value={
                                                    service.suspend_exempt_until
                                                }
                                            />
                                        ) : null,
                                    },
                                    {
                                        label: 'Suspend / terminate after',
                                        value:
                                            service.suspend_days_override !==
                                                null ||
                                            service.terminate_days_override !==
                                                null
                                                ? `${service.suspend_days_override ?? 'default'} / ${service.terminate_days_override ?? 'default'} days`
                                                : 'Default settings',
                                    },
                                    {
                                        label: 'Renewal',
                                        value: service.terminate_at_period_end
                                            ? 'Ends at period end'
                                            : service.do_not_renew
                                              ? 'Will not renew'
                                              : 'Renews automatically',
                                    },
                                    {
                                        label: 'Terminated on',
                                        value: service.termination_date ? (
                                            <DateTime
                                                value={service.termination_date}
                                            />
                                        ) : null,
                                    },
                                    {
                                        label: 'Order',
                                        value:
                                            order?.number && order.url ? (
                                                <Link
                                                    href={order.url}
                                                    className="hover:underline"
                                                >
                                                    {order.number}
                                                </Link>
                                            ) : (
                                                (order?.number ?? null)
                                            ),
                                    },
                                    {
                                        label: 'Admin notes',
                                        value: service.admin_notes,
                                        full: true,
                                    },
                                ]}
                            />
                        </SectionCard>

                        <SectionCard
                            title="Access"
                            description={`Provisioned with the ${driver.name} driver.`}
                        >
                            <DescriptionList
                                columns={3}
                                items={[
                                    {
                                        label: 'Username',
                                        value: service.username && (
                                            <span className="font-mono">
                                                {service.username}
                                            </span>
                                        ),
                                    },
                                    {
                                        label: 'Password',
                                        value:
                                            can.credentials &&
                                            service.username ? (
                                                <PasswordReveal
                                                    url={
                                                        ServiceController.credentials(
                                                            service.id,
                                                        ).url
                                                    }
                                                />
                                            ) : service.username ? (
                                                <span className="font-mono">
                                                    ••••••••••••
                                                </span>
                                            ) : null,
                                    },
                                    {
                                        label: 'Dedicated IP',
                                        value: service.dedicated_ip,
                                    },
                                ]}
                            />
                        </SectionCard>

                        <SectionCard
                            title="Addons"
                            flush
                            description="Addons have their own billing dates and status."
                            actions={
                                can.manage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setDialog('addon')}
                                    >
                                        Add addon
                                    </Button>
                                )
                            }
                        >
                            <DataTable
                                className="rounded-none border-0 shadow-none"
                                caption="Addons"
                                columns={[
                                    {
                                        key: 'name',
                                        header: 'Addon',
                                        primary: true,
                                        cell: (addon) => addon.name,
                                    },
                                    {
                                        key: 'status',
                                        header: 'Status',
                                        cell: (addon) => (
                                            <StatusBadge
                                                status={addon.status}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'due',
                                        header: 'Next due',
                                        cell: (addon) => (
                                            <DateTime
                                                value={addon.next_due_date}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'amount',
                                        header: 'Recurring',
                                        align: 'right',
                                        cell: (addon) => (
                                            <Money
                                                amount={addon.recurring_amount}
                                                currency={addon.currency}
                                            />
                                        ),
                                    },
                                ]}
                                rows={addons}
                                getRowKey={(addon) => addon.id}
                                empty={
                                    <EmptyState
                                        icon={Package}
                                        title="No addons"
                                        description="Addons ordered with this service appear here."
                                    />
                                }
                            />
                        </SectionCard>

                        <SectionCard
                            title="Billing periods"
                            flush
                            description="Invoices that bill this service, newest first."
                        >
                            <DataTable
                                className="rounded-none border-0 shadow-none"
                                caption="Billing periods"
                                columns={[
                                    {
                                        key: 'invoice',
                                        header: 'Invoice',
                                        primary: true,
                                        cell: (link) =>
                                            link.invoice_url ? (
                                                <Link
                                                    href={link.invoice_url}
                                                    className="hover:underline"
                                                >
                                                    {link.invoice_number ??
                                                        'Invoice'}
                                                </Link>
                                            ) : (
                                                (link.invoice_number ?? '—')
                                            ),
                                    },
                                    {
                                        key: 'period',
                                        header: 'Period',
                                        cell: (link) =>
                                            link.period_start ? (
                                                <span>
                                                    <DateTime
                                                        value={
                                                            link.period_start
                                                        }
                                                    />{' '}
                                                    –{' '}
                                                    <DateTime
                                                        value={link.period_end}
                                                    />
                                                </span>
                                            ) : (
                                                '—'
                                            ),
                                    },
                                    {
                                        key: 'kind',
                                        header: 'Type',
                                        hideOnMobile: true,
                                        cell: (link) =>
                                            link.kind === 'order'
                                                ? 'Order'
                                                : 'Renewal',
                                    },
                                    {
                                        key: 'status',
                                        header: 'Status',
                                        cell: (link) => (
                                            <StatusBadge
                                                status={
                                                    link.status === 'open'
                                                        ? 'unpaid'
                                                        : link.status
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        key: 'amount',
                                        header: 'Amount',
                                        align: 'right',
                                        cell: (link) => (
                                            <Money
                                                amount={link.amount}
                                                currency={service.currency}
                                            />
                                        ),
                                    },
                                ]}
                                rows={invoices}
                                getRowKey={(link) => link.id}
                                empty={
                                    <EmptyState
                                        icon={Receipt}
                                        title="No invoices yet"
                                        description="Renewal invoices are generated before each period starts."
                                    />
                                }
                            />
                        </SectionCard>

                        <SectionCard
                            title="Upgrade or downgrade"
                            description="Prorated for the days left in the current period. Upgrades apply when the invoice is paid."
                        >
                            <div className="space-y-4">
                                {can.manage ? (
                                    <ChangeOptionsPicker
                                        options={changeOptions}
                                        action={ServiceActionController.change(
                                            service.id,
                                        )}
                                        currentProduct={service.product_name}
                                        creditOnDowngrade={creditOnDowngrade}
                                        staff
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        You need permission to manage services
                                        to order changes.
                                    </p>
                                )}
                                {changes.length > 0 && (
                                    <ul className="divide-y rounded-lg border text-sm">
                                        {changes.map((change) => (
                                            <li
                                                key={change.id}
                                                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                            >
                                                <span>
                                                    {change.from} → {change.to}
                                                    {change.kind === 'cycle' &&
                                                        change.to_cycle &&
                                                        ` (${cycleLabel(change.to_cycle)})`}
                                                    <span className="ml-2 text-muted-foreground">
                                                        <DateTime
                                                            value={
                                                                change.created_at
                                                            }
                                                            relative
                                                        />
                                                    </span>
                                                </span>
                                                <span className="flex items-center gap-2">
                                                    <Money
                                                        amount={
                                                            change.amount_due
                                                        }
                                                        currency={
                                                            change.currency
                                                        }
                                                        signed
                                                    />
                                                    <StatusBadge
                                                        status={change.status}
                                                    />
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </SectionCard>

                        <section className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h2 className="text-base font-semibold">
                                    Module log
                                </h2>
                                {can.moduleLog && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={moduleLogIndex({
                                                query: {
                                                    search:
                                                        service.domain ??
                                                        service.id,
                                                },
                                            })}
                                        >
                                            View all
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            {can.moduleLog ? (
                                <ModuleLogTable entries={moduleLog} />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    You need permission to view the module log.
                                </p>
                            )}
                        </section>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard title="Server">
                            {service.server ? (
                                <DescriptionList
                                    columns={1}
                                    items={[
                                        {
                                            label: 'Name',
                                            value: service.server.name,
                                        },
                                        {
                                            label: 'Hostname',
                                            value: (
                                                <span className="font-mono text-xs">
                                                    {service.server.hostname}
                                                </span>
                                            ),
                                        },
                                        {
                                            label: 'Nameservers',
                                            value: service.server.nameservers
                                                .length ? (
                                                <ul className="font-mono text-xs">
                                                    {service.server.nameservers.map(
                                                        (ns) => (
                                                            <li key={ns}>
                                                                {ns}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            ) : null,
                                        },
                                    ]}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {driver.requires_server
                                        ? 'A server is picked when the account is created.'
                                        : 'This product is provisioned manually.'}
                                </p>
                            )}
                        </SectionCard>

                        <SectionCard title="Usage">
                            {usage ? (
                                <div className="space-y-4">
                                    <UsageMeter
                                        label="Disk"
                                        used={usage.disk_used_mb}
                                        limit={usage.disk_limit_mb}
                                    />
                                    <UsageMeter
                                        label="Bandwidth"
                                        used={usage.bw_used_mb}
                                        limit={usage.bw_limit_mb}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Updated{' '}
                                        <DateTime
                                            value={usage.captured_at}
                                            relative
                                        />
                                    </p>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Usage is synced daily from the server.
                                </p>
                            )}
                        </SectionCard>

                        {service.client && (
                            <SectionCard title="Client">
                                <div className="space-y-2 text-sm">
                                    <Link
                                        href={showClient(service.client.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {service.client.name}
                                    </Link>
                                    <p>
                                        <Link
                                            href={servicesIndex({
                                                query: {
                                                    client: service.client.id,
                                                },
                                            })}
                                            className="text-muted-foreground hover:text-foreground hover:underline"
                                        >
                                            All services of this client
                                        </Link>
                                    </p>
                                </div>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </Page>

            <FormDialog
                open={dialog === 'suspend'}
                onOpenChange={(open) => setDialog(open ? 'suspend' : null)}
                title={`Suspend ${name}?`}
                description="The account stops serving traffic until it is unsuspended. Staff suspensions are never lifted automatically by a payment."
                submitLabel="Suspend service"
                destructive
                processing={commandForm.processing}
                onSubmit={submitCommand}
            >
                <Field
                    htmlFor="suspend-reason"
                    label="Reason"
                    optional
                    error={commandForm.errors.reason}
                >
                    <Input
                        id="suspend-reason"
                        value={commandForm.data.reason}
                        onChange={(event) =>
                            commandForm.setData('reason', event.target.value)
                        }
                        placeholder="e.g. abuse report"
                        maxLength={200}
                    />
                </Field>
            </FormDialog>

            <FormDialog
                open={dialog === 'password'}
                onOpenChange={(open) => setDialog(open ? 'password' : null)}
                title="Change password"
                description="The new password is set on the server and stored encrypted."
                submitLabel="Change password"
                processing={commandForm.processing}
                onSubmit={submitCommand}
            >
                <Field
                    htmlFor="new-password"
                    label="New password"
                    error={commandForm.errors.password}
                >
                    <Input
                        id="new-password"
                        type="password"
                        autoComplete="new-password"
                        value={commandForm.data.password}
                        onChange={(event) =>
                            commandForm.setData('password', event.target.value)
                        }
                        aria-invalid={!!commandForm.errors.password}
                        aria-describedby="new-password-description"
                    />
                </Field>
            </FormDialog>

            <FormDialog
                open={dialog === 'status'}
                onOpenChange={(open) => setDialog(open ? 'status' : null)}
                title="Set status without module"
                description="Only the status in this system changes; nothing is sent to the server. Use this when the account was changed by hand."
                submitLabel="Set status"
                processing={statusForm.processing}
                onSubmit={() =>
                    statusForm.submit(
                        ServiceActionController.status(service.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDialog(null),
                        },
                    )
                }
            >
                <Field
                    htmlFor="target-status"
                    label="New status"
                    error={statusForm.errors.status}
                >
                    <Select
                        value={statusForm.data.status}
                        onValueChange={(value) =>
                            statusForm.setData('status', value)
                        }
                    >
                        <SelectTrigger id="target-status">
                            <SelectValue placeholder="Choose a status" />
                        </SelectTrigger>
                        <SelectContent>
                            {statusTargets.map((target) => (
                                <SelectItem
                                    key={target.value}
                                    value={target.value}
                                >
                                    {target.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    htmlFor="status-note"
                    label="Note for the activity log"
                    optional
                >
                    <Input
                        id="status-note"
                        value={statusForm.data.note}
                        onChange={(event) =>
                            statusForm.setData('note', event.target.value)
                        }
                        maxLength={200}
                    />
                </Field>
            </FormDialog>

            <FormDialog
                open={dialog === 'cancel'}
                onOpenChange={(open) => setDialog(open ? 'cancel' : null)}
                title={`Request cancellation of ${name}?`}
                description="Renewals stop now. The service is terminated on the processing date."
                submitLabel="Request cancellation"
                destructive
                processing={cancelForm.processing}
                onSubmit={() =>
                    cancelForm.submit(
                        ServiceActionController.cancel(service.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDialog(null),
                        },
                    )
                }
            >
                <Field
                    htmlFor="cancel-type"
                    label="When"
                    error={cancelForm.errors.type}
                >
                    <Select
                        value={cancelForm.data.type}
                        onValueChange={(value) =>
                            cancelForm.setData('type', value)
                        }
                    >
                        <SelectTrigger id="cancel-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="end_of_period">
                                End of billing period
                            </SelectItem>
                            <SelectItem value="immediate">
                                Immediately
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    htmlFor="cancel-reason"
                    label="Reason"
                    error={cancelForm.errors.reason}
                >
                    <Textarea
                        id="cancel-reason"
                        rows={3}
                        value={cancelForm.data.reason}
                        onChange={(event) =>
                            cancelForm.setData('reason', event.target.value)
                        }
                    />
                </Field>
            </FormDialog>

            <ConfirmDialog
                open={dialog === 'terminate'}
                onOpenChange={(open) => setDialog(open ? 'terminate' : null)}
                title={`Terminate ${name}?`}
                description="The hosting account and all its files, databases and mailboxes are deleted on the server. This can't be undone."
                confirmLabel="Terminate service"
                confirmText={service.domain ?? String(service.id)}
                action={commandAction}
                data={{ command: 'terminate' }}
            />

            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(open) => setDialog(open ? 'delete' : null)}
                title={`Delete the record of ${name}?`}
                description={
                    service.status === 'terminated' ||
                    service.status === 'cancelled'
                        ? 'The service is removed from lists. Nothing is changed on the server.'
                        : 'This service is not terminated: the account keeps running on the server and will no longer be billed. Terminate it first unless that is intended.'
                }
                confirmLabel="Delete record"
                action={ServiceController.destroy(service.id)}
            />

            <FormDialog
                open={dialog === 'addon'}
                onOpenChange={(open) => setDialog(open ? 'addon' : null)}
                title="Add addon"
                description="Added without an invoice. It renews from the service's next due date."
                submitLabel="Add addon"
                processing={addonForm.processing}
                onSubmit={() => {
                    addonForm.transform((values) => ({
                        ...values,
                        recurring_amount: toMinor(values.recurring_amount),
                        setup_fee: toMinor(values.setup_fee),
                    }));
                    addonForm.submit(
                        ServiceActionController.addAddon(service.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                addonForm.reset();
                                setDialog(null);
                            },
                        },
                    );
                }}
            >
                <Field
                    htmlFor="addon-name"
                    label="Name"
                    error={addonForm.errors.name}
                >
                    <Input
                        id="addon-name"
                        value={addonForm.data.name}
                        onChange={(event) =>
                            addonForm.setData('name', event.target.value)
                        }
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        htmlFor="addon-amount"
                        label={labelWithCurrency('Recurring', service.currency)}
                        error={addonForm.errors.recurring_amount}
                    >
                        <Input
                            id="addon-amount"
                            inputMode="decimal"
                            value={addonForm.data.recurring_amount}
                            onChange={(event) =>
                                addonForm.setData(
                                    'recurring_amount',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field
                        htmlFor="addon-cycle"
                        label="Billing cycle"
                        error={addonForm.errors.cycle}
                    >
                        <Select
                            value={addonForm.data.cycle}
                            onValueChange={(value) =>
                                addonForm.setData('cycle', value)
                            }
                        >
                            <SelectTrigger id="addon-cycle" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(CYCLE_LABELS).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                <InputError
                    message={
                        addonForm.errors.setup_fee ??
                        addonForm.errors.status ??
                        errors.action
                    }
                />
            </FormDialog>
        </>
    );
}
