import { useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    AlertTriangle,
    CalendarClock,
    CalendarPlus,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { ChangeOptionsPicker } from '@/components/services/change-options';
import { FormDialog } from '@/components/services/form-dialog';
import { UsageMeter } from '@/components/services/usage-meter';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import ServiceActionController from '@/actions/App/Modules/Services/Http/Controllers/Client/ServiceActionController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Client/ServiceController';
import type { SelectOption } from '@/types/app';
import { formatDate } from '@/lib/date';
import { cycleLabel } from '@/types/services';
import type {
    AddonRow,
    ChangeOptions,
    ServiceRow,
    UsageData,
} from '@/types/services';

type Props = {
    service: ServiceRow;
    addons: AddonRow[];
    usage: UsageData;
    cancellation: { id: number; type: string; process_after: string } | null;
    cancellationTypes: SelectOption[];
    changeOptions?: ChangeOptions;
    creditOnDowngrade: boolean;
    /** Nameservers the service's domain should use: the server's, else the default setting. */
    nameservers: string[];
    can: { cancel: boolean; changePassword: boolean };
};

export default function ClientServiceShow({
    service,
    addons,
    usage,
    cancellation,
    cancellationTypes,
    changeOptions,
    creditOnDowngrade,
    nameservers,
    can,
}: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const cancelForm = useForm({ type: 'end_of_period', reason: '' });
    const passwordForm = useForm({ password: '', password_confirmation: '' });
    const name = service.domain ?? service.product_name ?? 'Service';
    const live = ['active', 'suspended'].includes(service.status);

    return (
        <>
            <Head title={name} />
            <Page>
                <PageHeader
                    back={{
                        href: ServiceController.index(),
                        label: 'My services',
                    }}
                    title={name}
                    status={service.status}
                    meta={<span>{service.product_name}</span>}
                    actions={
                        can.cancel && (
                            <Button
                                variant="outline"
                                onClick={() => setCancelOpen(true)}
                            >
                                Request cancellation
                            </Button>
                        )
                    }
                />

                {service.status === 'suspended' && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>This service is suspended</AlertTitle>
                        <AlertDescription>
                            {service.suspension_reason === 'overdue' ||
                            service.suspension_reason === 'addon_overdue'
                                ? 'An invoice is overdue. Once it is paid, the service is restored automatically.'
                                : 'Please contact support to restore it.'}
                        </AlertDescription>
                    </Alert>
                )}

                {cancellation && (
                    <Alert>
                        <CalendarClock />
                        <AlertTitle>Cancellation scheduled</AlertTitle>
                        <AlertDescription>
                            <span>
                                This service will be cancelled on{' '}
                                <DateTime value={cancellation.process_after} />.
                                Contact support if you change your mind.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        label={
                            service.do_not_renew
                                ? 'Ends on'
                                : 'Next payment due'
                        }
                        value={<DateTime value={service.next_due_date} />}
                        hint={
                            service.next_due_date
                                ? cycleLabel(service.cycle)
                                : 'No renewal'
                        }
                        icon={CalendarClock}
                    />
                    <StatCard
                        label="Price"
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
                        label="Registered"
                        value={<DateTime value={service.registration_date} />}
                        icon={CalendarPlus}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <SectionCard title="Details">
                            <DescriptionList
                                items={[
                                    {
                                        label: 'Plan',
                                        value: service.product_name,
                                    },
                                    { label: 'Domain', value: service.domain },
                                    {
                                        label: 'Username',
                                        value: service.username && (
                                            <span className="font-mono">
                                                {service.username}
                                            </span>
                                        ),
                                    },
                                    {
                                        label: 'Dedicated IP',
                                        value: service.dedicated_ip,
                                    },
                                    {
                                        label: 'Server',
                                        value: service.server?.hostname && (
                                            <span className="font-mono text-xs">
                                                {service.server.hostname}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </SectionCard>

                        {nameservers.length > 0 && (
                            <SectionCard
                                title="Nameservers"
                                description="Set these at your registry if your domain is registered elsewhere, such as a .np domain."
                            >
                                <ul className="grid gap-1 font-mono text-sm">
                                    {nameservers.map((host) => (
                                        <li key={host}>{host}</li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}

                        {live && (
                            <SectionCard
                                title="Change plan"
                                description="You only pay the difference for the days left in this billing period."
                            >
                                <ChangeOptionsPicker
                                    options={changeOptions}
                                    action={ServiceActionController.change(
                                        service.id,
                                    )}
                                    currentProduct={service.product_name}
                                    creditOnDowngrade={creditOnDowngrade}
                                />
                            </SectionCard>
                        )}

                        {addons.length > 0 && (
                            <SectionCard title="Addons">
                                <ul className="divide-y text-sm">
                                    {addons.map((addon) => (
                                        <li
                                            key={addon.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <span className="flex items-center gap-2">
                                                {addon.name}
                                                <StatusBadge
                                                    status={addon.status}
                                                />
                                            </span>
                                            <span className="text-muted-foreground">
                                                <Money
                                                    amount={
                                                        addon.recurring_amount
                                                    }
                                                    currency={addon.currency}
                                                />{' '}
                                                {cycleLabel(
                                                    addon.cycle,
                                                ).toLowerCase()}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard title="Usage">
                            {usage ? (
                                <div className="space-y-4">
                                    <UsageMeter
                                        label="Disk space"
                                        used={usage.disk_used_mb}
                                        limit={usage.disk_limit_mb}
                                    />
                                    <UsageMeter
                                        label="Bandwidth this month"
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
                                    Usage appears here after the first daily
                                    update.
                                </p>
                            )}
                        </SectionCard>

                        {can.changePassword && (
                            <SectionCard
                                title="Change password"
                                description="Sets a new password for your hosting account."
                                footer={
                                    <Button
                                        onClick={() =>
                                            passwordForm.submit(
                                                ServiceActionController.password(
                                                    service.id,
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        passwordForm.reset(),
                                                },
                                            )
                                        }
                                        disabled={passwordForm.processing}
                                    >
                                        {passwordForm.processing && <Spinner />}
                                        Change password
                                    </Button>
                                }
                            >
                                <div className="grid gap-4">
                                    <Field
                                        htmlFor="password"
                                        label="New password"
                                        hint="At least 10 characters with upper and lower case letters and a number."
                                        error={passwordForm.errors.password}
                                    >
                                        <Input
                                            id="password"
                                            type="password"
                                            autoComplete="new-password"
                                            value={passwordForm.data.password}
                                            onChange={(event) =>
                                                passwordForm.setData(
                                                    'password',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                !!passwordForm.errors.password
                                            }
                                            aria-describedby="password-description"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="password_confirmation"
                                        label="Confirm new password"
                                    >
                                        <Input
                                            id="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            value={
                                                passwordForm.data
                                                    .password_confirmation
                                            }
                                            onChange={(event) =>
                                                passwordForm.setData(
                                                    'password_confirmation',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </Page>

            <FormDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                title={`Cancel ${name}?`}
                description={
                    cancelForm.data.type === 'immediate'
                        ? 'Your account and its files are removed right away. This can’t be undone.'
                        : `Renewals stop right away. On ${service.next_due_date ? formatDate(service.next_due_date) : 'the last day you paid for'} your account and its files are removed.`
                }
                submitLabel="Request cancellation"
                destructive
                processing={cancelForm.processing}
                onSubmit={() =>
                    cancelForm.submit(
                        ServiceActionController.cancel(service.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => setCancelOpen(false),
                        },
                    )
                }
            >
                <Field
                    htmlFor="cancel-type"
                    label="When should it end?"
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
                            {cancellationTypes.map((type) => (
                                <SelectItem key={type.value} value={type.value}>
                                    {type.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    htmlFor="cancel-reason"
                    label="Why are you leaving?"
                    error={cancelForm.errors.reason}
                >
                    <Textarea
                        id="cancel-reason"
                        rows={3}
                        value={cancelForm.data.reason}
                        onChange={(event) =>
                            cancelForm.setData('reason', event.target.value)
                        }
                        aria-invalid={!!cancelForm.errors.reason}
                    />
                </Field>
            </FormDialog>
        </>
    );
}
