import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DomainActionController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainActionController';
import DomainController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/DomainController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { statusLabel } from '@/components/app/status-badge';
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
import { fromMinor, labelWithCurrency, parseMoneyInput } from '@/lib/money';
import type { Domain } from '@/types/domains';

/** Staff forms on the admin domain page: billing/dates editor, forced status and move dialogs. */

export function DetailsForm({
    domain,
    registrars,
    registrarNames,
}: {
    domain: Domain;
    registrars: string[];
    registrarNames: Record<string, string>;
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
            first_payment_amount:
                parseMoneyInput(data.first_payment_amount) ??
                data.first_payment_amount,
            recurring_amount:
                parseMoneyInput(data.recurring_amount) ?? data.recurring_amount,
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
                <div className="grid items-start gap-4 sm:grid-cols-2">
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
                                className="w-full"
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
                                        {registrarNames[registrar] ?? registrar}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        htmlFor="first_payment_amount"
                        label={labelWithCurrency(
                            'First payment',
                            domain.currency,
                        )}
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
                        label={labelWithCurrency(
                            'Renewal amount',
                            domain.currency,
                        )}
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

export function ForceStatusDialog({
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
                            <SelectTrigger className="w-full" id="force-status">
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

export function MoveDialog({
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
