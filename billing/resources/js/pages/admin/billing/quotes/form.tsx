import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import { ClientPicker } from '@/components/billing/client-picker';
import type { ClientOption, Quote } from '@/components/billing/types';
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
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/QuoteController';

type Props = {
    quote: Quote | null;
    defaults: { client_id: number | null };
    client: ClientOption | null;
};

type FormData = {
    client_id: string;
    subject: string;
    valid_until: string;
    proposal_text: string;
    customer_notes: string;
    admin_notes: string;
    items: EditableLine[];
};

export default function QuoteForm({
    quote,
    defaults,
    client: initialClient,
}: Props) {
    const form = useForm<FormData>({
        client_id: defaults.client_id ? String(defaults.client_id) : '',
        subject: quote?.subject ?? '',
        valid_until: quote?.valid_until ?? '',
        proposal_text: quote?.proposal_text ?? '',
        customer_notes: quote?.customer_notes ?? '',
        admin_notes: quote?.admin_notes ?? '',
        items: quote?.items?.length
            ? quote.items.map((item) => ({
                  description: item.description,
                  quantity: String(item.quantity),
                  unit_amount: toDecimalInput(item.unit_amount),
                  discount_percent: String(item.discount_percent),
                  taxable: item.taxable,
              }))
            : [blankLine(true)],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [client, setClient] = useState<ClientOption | null>(initialClient);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            client_id: Number(data.client_id),
            valid_until: data.valid_until || null,
            items: data.items.map((item) => ({
                ...item,
                quantity: Number(item.quantity),
                unit_amount: normalizeMoney(item.unit_amount),
            })),
        }));
        form.submit(quote ? update(quote.id) : store());
    };

    const title = quote ? `Edit ${quote.number}` : 'New quote';

    return (
        <>
            <Head title={title} />
            <Page size="narrow">
                <PageHeader
                    back={
                        quote
                            ? { href: show(quote.id), label: quote.number }
                            : { href: index(), label: 'Quotes' }
                    }
                    title={title}
                />
                <form onSubmit={submit} className="grid gap-6">
                    <SectionCard title="Proposal">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                className="content-start"
                                htmlFor="client_id"
                                label="Client"
                                error={errors.client_id}
                            >
                                <ClientPicker
                                    id="client_id"
                                    value={client}
                                    onChange={(option) => {
                                        setClient(option);
                                        form.setData(
                                            'client_id',
                                            option ? String(option.id) : '',
                                        );
                                    }}
                                    invalid={!!errors.client_id}
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="valid_until"
                                label="Valid until"
                                optional
                                error={errors.valid_until}
                            >
                                <Input
                                    id="valid_until"
                                    type="date"
                                    value={form.data.valid_until}
                                    onChange={(event) =>
                                        form.setData(
                                            'valid_until',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="subject"
                                label="Subject"
                                error={errors.subject}
                                className="content-start sm:col-span-2"
                            >
                                <Input
                                    id="subject"
                                    value={form.data.subject}
                                    onChange={(event) =>
                                        form.setData(
                                            'subject',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!errors.subject}
                                />
                            </Field>
                            <Field
                                htmlFor="proposal_text"
                                label="Proposal text"
                                optional
                                error={errors.proposal_text}
                                className="content-start sm:col-span-2"
                            >
                                <Textarea
                                    id="proposal_text"
                                    rows={4}
                                    value={form.data.proposal_text}
                                    onChange={(event) =>
                                        form.setData(
                                            'proposal_text',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </SectionCard>
                    <SectionCard
                        title="Lines"
                        description="Line total = quantity × unit price × (1 − discount). Tax is applied on save."
                    >
                        <LineItemsEditor
                            lines={form.data.items}
                            onChange={(items) => form.setData('items', items)}
                            errors={errors}
                            currency={client?.currency}
                            withDiscount
                        />
                    </SectionCard>
                    <SectionCard title="Notes">
                        <div className="grid gap-6">
                            <Field
                                className="content-start"
                                htmlFor="customer_notes"
                                label="Notes for the client"
                                optional
                                error={errors.customer_notes}
                            >
                                <Textarea
                                    id="customer_notes"
                                    rows={3}
                                    value={form.data.customer_notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'customer_notes',
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
                            <Link href={quote ? show(quote.id) : index()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {quote ? 'Save quote' : 'Create quote'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
