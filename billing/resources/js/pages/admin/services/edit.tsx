import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { fromMinor, toMinor } from '@/lib/money';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import type { SelectOption } from '@/types/app';
import type { ServiceRow } from '@/types/services';

type Props = {
    service: ServiceRow;
    cycles: SelectOption[];
    servers: SelectOption[];
};

const NONE = '__none__';

function ToggleRow({
    id,
    title,
    description,
    checked,
    onChange,
}: {
    id: string;
    title: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-3 sm:col-span-2">
            <div className="space-y-0.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {title}
                </label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

export default function ServiceEdit({ service, cycles, servers }: Props) {
    const form = useForm({
        domain: service.domain ?? '',
        username: service.username ?? '',
        password: '',
        dedicated_ip: service.dedicated_ip ?? '',
        server_id: service.server_id ? String(service.server_id) : NONE,
        cycle: service.cycle,
        quantity: service.quantity,
        first_payment_amount: fromMinor(service.first_payment_amount).toFixed(
            2,
        ),
        recurring_amount: fromMinor(service.recurring_amount).toFixed(2),
        next_due_date: service.next_due_date ?? '',
        next_invoice_date: service.next_invoice_date ?? '',
        suspend_exempt_until: service.suspend_exempt_until ?? '',
        suspend_days_override: service.suspend_days_override?.toString() ?? '',
        terminate_days_override:
            service.terminate_days_override?.toString() ?? '',
        recurring_cycles_limit: service.recurring_cycles_limit ?? 0,
        do_not_renew: service.do_not_renew,
        terminate_at_period_end: service.terminate_at_period_end ?? false,
        payment_gateway: service.payment_gateway ?? '',
        admin_notes: service.admin_notes ?? '',
    });
    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            server_id:
                values.server_id === NONE ? null : Number(values.server_id),
            first_payment_amount: toMinor(values.first_payment_amount),
            recurring_amount: toMinor(values.recurring_amount),
            next_due_date: values.next_due_date || null,
            next_invoice_date: values.next_invoice_date || null,
            suspend_exempt_until: values.suspend_exempt_until || null,
            suspend_days_override:
                values.suspend_days_override === ''
                    ? null
                    : Number(values.suspend_days_override),
            terminate_days_override:
                values.terminate_days_override === ''
                    ? null
                    : Number(values.terminate_days_override),
        }));
        form.submit(ServiceController.update(service.id));
    };

    const text = (name: keyof typeof data) => ({
        id: name,
        value: String(data[name] ?? ''),
        onChange: (event: { target: { value: string } }) =>
            setData(name, event.target.value as never),
        'aria-invalid': !!errors[name],
        'aria-describedby': `${name}-description`,
    });

    return (
        <>
            <Head title={`Edit ${service.label}`} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: ServiceController.show(service.id),
                        label: service.label,
                    }}
                    title="Edit service"
                    description="Changes here do not call the server. Use the module actions on the service page to change the remote account."
                />

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Account">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="domain"
                                label="Domain"
                                optional
                                error={errors.domain}
                            >
                                <Input {...text('domain')} />
                            </Field>
                            <Field
                                htmlFor="server_id"
                                label="Server"
                                error={errors.server_id}
                            >
                                <Select
                                    value={data.server_id}
                                    onValueChange={(value) =>
                                        setData('server_id', value)
                                    }
                                >
                                    <SelectTrigger id="server_id">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No server
                                        </SelectItem>
                                        {servers.map((server) => (
                                            <SelectItem
                                                key={server.value}
                                                value={server.value}
                                            >
                                                {server.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                htmlFor="username"
                                label="Username"
                                optional
                                error={errors.username}
                            >
                                <Input
                                    {...text('username')}
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                htmlFor="password"
                                label="New password"
                                optional
                                hint="Leave blank to keep the current password."
                                error={errors.password}
                            >
                                <Input
                                    {...text('password')}
                                    type="password"
                                    autoComplete="new-password"
                                />
                            </Field>
                            <Field
                                htmlFor="dedicated_ip"
                                label="Dedicated IP"
                                optional
                                error={errors.dedicated_ip}
                            >
                                <Input {...text('dedicated_ip')} />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Billing"
                        description={`Amounts in ${service.currency}.`}
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="cycle"
                                label="Billing cycle"
                                error={errors.cycle}
                            >
                                <Select
                                    value={data.cycle}
                                    onValueChange={(value) =>
                                        setData('cycle', value)
                                    }
                                >
                                    <SelectTrigger id="cycle">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {cycles.map((cycle) => (
                                            <SelectItem
                                                key={cycle.value}
                                                value={cycle.value}
                                            >
                                                {cycle.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                htmlFor="quantity"
                                label="Quantity"
                                error={errors.quantity}
                            >
                                <Input
                                    {...text('quantity')}
                                    type="number"
                                    min={1}
                                />
                            </Field>
                            <Field
                                htmlFor="recurring_amount"
                                label="Recurring amount"
                                error={errors.recurring_amount}
                            >
                                <Input
                                    {...text('recurring_amount')}
                                    inputMode="decimal"
                                />
                            </Field>
                            <Field
                                htmlFor="first_payment_amount"
                                label="First payment amount"
                                error={errors.first_payment_amount}
                            >
                                <Input
                                    {...text('first_payment_amount')}
                                    inputMode="decimal"
                                />
                            </Field>
                            <Field
                                htmlFor="next_due_date"
                                label="Next due date"
                                hint="Changing it moves the billing anchor day."
                                error={errors.next_due_date}
                            >
                                <Input {...text('next_due_date')} type="date" />
                            </Field>
                            <Field
                                htmlFor="next_invoice_date"
                                label="Next invoice date"
                                hint="Start of the first period not yet invoiced."
                                error={errors.next_invoice_date}
                            >
                                <Input
                                    {...text('next_invoice_date')}
                                    type="date"
                                />
                            </Field>
                            <Field
                                htmlFor="recurring_cycles_limit"
                                label="Billing cycle limit"
                                hint="0 = renew indefinitely."
                                error={errors.recurring_cycles_limit}
                            >
                                <Input
                                    {...text('recurring_cycles_limit')}
                                    type="number"
                                    min={0}
                                />
                            </Field>
                            <Field
                                htmlFor="payment_gateway"
                                label="Payment method"
                                optional
                                error={errors.payment_gateway}
                            >
                                <Input {...text('payment_gateway')} />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Automation"
                        description="Overrides of the global suspension and termination settings."
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="suspend_days_override"
                                label="Suspend after (days overdue)"
                                optional
                                hint="Blank uses the default."
                                error={errors.suspend_days_override}
                            >
                                <Input
                                    {...text('suspend_days_override')}
                                    type="number"
                                    min={0}
                                />
                            </Field>
                            <Field
                                htmlFor="terminate_days_override"
                                label="Terminate after (days overdue)"
                                optional
                                hint="Blank uses the default."
                                error={errors.terminate_days_override}
                            >
                                <Input
                                    {...text('terminate_days_override')}
                                    type="number"
                                    min={0}
                                />
                            </Field>
                            <Field
                                htmlFor="suspend_exempt_until"
                                label="Exempt from suspension until"
                                optional
                                error={errors.suspend_exempt_until}
                            >
                                <Input
                                    {...text('suspend_exempt_until')}
                                    type="date"
                                />
                            </Field>
                            <ToggleRow
                                id="terminate_at_period_end"
                                title="Terminate at end of period"
                                description="Cancel the service on its next due date instead of renewing."
                                checked={data.terminate_at_period_end}
                                onChange={(checked) =>
                                    setData('terminate_at_period_end', checked)
                                }
                            />
                            <ToggleRow
                                id="do_not_renew"
                                title="Do not renew"
                                description="Stop generating renewal invoices."
                                checked={data.do_not_renew}
                                onChange={(checked) =>
                                    setData('do_not_renew', checked)
                                }
                            />
                            <Field
                                htmlFor="admin_notes"
                                label="Admin notes"
                                optional
                                className="sm:col-span-2"
                                error={errors.admin_notes}
                            >
                                <Textarea {...text('admin_notes')} rows={3} />
                            </Field>
                        </div>
                    </SectionCard>

                    <FormActions sticky>
                        <Button variant="outline" asChild>
                            <Link href={ServiceController.show(service.id)}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Save changes
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
