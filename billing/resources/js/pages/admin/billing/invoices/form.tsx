import { Head, Link, useForm } from '@inertiajs/react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import {
    LineItemsEditor,
    blankLine,
} from '@/components/billing/line-items-editor';
import type { EditableLine } from '@/components/billing/line-items-editor';
import { SelectField } from '@/components/billing/select-field';
import type {
    ClientOption,
    GatewayOption,
    Invoice,
} from '@/components/billing/types';
import { normalizeMoney, toDecimalInput } from '@/components/billing/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    index,
    show,
    store,
    update,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';

type Props = {
    invoice: Invoice | null;
    defaults: { client_id: number | null; due_date: string | null };
    clients: ClientOption[];
    gateways: GatewayOption[];
};

type FormData = {
    client_id: string;
    due_date: string;
    payment_gateway: string;
    notes: string;
    admin_notes: string;
    items: EditableLine[];
};

export default function InvoiceForm({
    invoice,
    defaults,
    clients,
    gateways,
}: Props) {
    const form = useForm<FormData>({
        client_id: defaults.client_id ? String(defaults.client_id) : '',
        due_date: defaults.due_date ?? '',
        payment_gateway: invoice?.payment_gateway ?? '',
        notes: invoice?.notes ?? '',
        admin_notes: invoice?.admin_notes ?? '',
        items: invoice?.items?.length
            ? invoice.items.map((item) => ({
                  description: item.description,
                  quantity: String(item.quantity),
                  unit_amount: toDecimalInput(item.unit_amount),
                  taxable: item.taxable,
              }))
            : [blankLine()],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const client = clients.find(
        (option) => String(option.id) === form.data.client_id,
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            client_id: Number(data.client_id),
            payment_gateway: data.payment_gateway || null,
            items: data.items.map((item) => ({
                ...item,
                quantity: Number(item.quantity),
                unit_amount: normalizeMoney(item.unit_amount),
            })),
        }));
        form.submit(invoice ? update(invoice.id) : store());
    };

    const title = invoice ? `Edit ${invoice.number}` : 'New invoice';

    return (
        <>
            <Head title={title} />
            <Page size="narrow">
                <PageHeader
                    back={
                        invoice
                            ? { href: show(invoice.id), label: invoice.number }
                            : { href: index(), label: 'Invoices' }
                    }
                    title={title}
                    description="Drafts are unnumbered and editable. Publishing assigns the number and locks the lines."
                />
                <form onSubmit={submit} className="grid gap-6">
                    <SectionCard title="Client">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="client_id"
                                label="Client"
                                error={errors.client_id}
                                className="content-start sm:col-span-2"
                            >
                                <SelectField
                                    id="client_id"
                                    value={form.data.client_id}
                                    onChange={(value) =>
                                        form.setData('client_id', value)
                                    }
                                    options={clients.map((option) => ({
                                        value: String(option.id),
                                        label: `${option.display_name} · ${option.currency}`,
                                    }))}
                                    placeholder="Choose a client"
                                    invalid={!!errors.client_id}
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="due_date"
                                label="Due date"
                                optional
                                hint="Defaults to the configured payment term."
                                error={errors.due_date}
                            >
                                <Input
                                    id="due_date"
                                    type="date"
                                    value={form.data.due_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'due_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="payment_gateway"
                                label="Gateway"
                                optional
                                error={errors.payment_gateway}
                            >
                                <SelectField
                                    id="payment_gateway"
                                    value={form.data.payment_gateway}
                                    onChange={(value) =>
                                        form.setData('payment_gateway', value)
                                    }
                                    options={gateways}
                                    placeholder="Client's choice"
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Lines"
                        description="Totals and tax are calculated by the server when you save."
                    >
                        <LineItemsEditor
                            lines={form.data.items}
                            onChange={(items) => form.setData('items', items)}
                            errors={errors}
                            currency={client?.currency}
                        />
                    </SectionCard>

                    <SectionCard title="Notes">
                        <div className="grid gap-6">
                            <Field
                                className="content-start"
                                htmlFor="notes"
                                label="Notes shown on the invoice"
                                optional
                                error={errors.notes}
                            >
                                <Textarea
                                    id="notes"
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="admin_notes"
                                label="Staff notes"
                                optional
                                error={errors.admin_notes}
                            >
                                <Textarea
                                    id="admin_notes"
                                    rows={3}
                                    value={form.data.admin_notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'admin_notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <InputError message={errors.billing} />

                    <FormActions sticky>
                        <Button variant="outline" asChild>
                            <Link href={invoice ? show(invoice.id) : index()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {invoice ? 'Save draft' : 'Create draft'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
