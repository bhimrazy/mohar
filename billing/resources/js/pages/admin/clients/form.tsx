import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { statusLabel } from '@/components/app/status-badge';
import { SwitchField } from '@/components/app/switch-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ClientRecord } from '@/types';

type Props = {
    client?: ClientRecord;
    currencies: string[];
    /** Why the client's currency can't change (it has records in it), or null. */
    currencyLock?: string | null;
    groups: { id: number; name: string }[];
    statuses: string[];
    defaultCountry: string;
};

type TextFieldProps = {
    name: keyof ClientRecord;
    label: string;
    errors: Record<string, string | undefined>;
    client?: ClientRecord;
    type?: string;
    optional?: boolean;
    autoComplete?: string;
    className?: string;
    fallback?: string;
};

function TextField({
    name,
    label,
    errors,
    client,
    type = 'text',
    optional,
    autoComplete,
    className,
    fallback,
}: TextFieldProps) {
    const value = client?.[name];

    return (
        <Field
            htmlFor={name}
            label={label}
            error={errors[name]}
            optional={optional}
            className={className}
        >
            <Input
                id={name}
                name={name}
                type={type}
                defaultValue={
                    typeof value === 'string' ? value : (fallback ?? '')
                }
                autoComplete={autoComplete}
                aria-invalid={Boolean(errors[name])}
                aria-describedby={`${name}-description`}
            />
        </Field>
    );
}

export default function ClientForm({
    client,
    currencies,
    currencyLock = null,
    groups,
    statuses,
    defaultCountry,
}: Props) {
    const editing = Boolean(client);
    const action = client
        ? ClientController.update.form(client.id)
        : ClientController.store.form();
    const back = client
        ? { href: ClientController.show(client.id), label: client.display_name }
        : { href: ClientController.index(), label: 'Clients' };

    return (
        <>
            <Head title={editing ? 'Edit client' : 'New client'} />
            <Page size="narrow">
                <PageHeader
                    back={back}
                    title={editing ? 'Edit client' : 'New client'}
                />

                <Form {...action} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            <SectionCard title="Contact">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <TextField
                                        name="first_name"
                                        label="First name"
                                        errors={errors}
                                        client={client}
                                        autoComplete="given-name"
                                    />
                                    <TextField
                                        name="last_name"
                                        label="Last name"
                                        errors={errors}
                                        client={client}
                                        autoComplete="family-name"
                                    />
                                    <TextField
                                        name="company_name"
                                        label="Company"
                                        errors={errors}
                                        client={client}
                                        optional
                                        autoComplete="organization"
                                        className="sm:col-span-2"
                                    />
                                    <TextField
                                        name="email"
                                        label="Email"
                                        type="email"
                                        errors={errors}
                                        client={client}
                                        autoComplete="email"
                                    />
                                    <TextField
                                        name="phone"
                                        label="Phone"
                                        type="tel"
                                        errors={errors}
                                        client={client}
                                        optional
                                        autoComplete="tel"
                                    />
                                </div>
                            </SectionCard>

                            <SectionCard title="Address">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <TextField
                                        name="address_1"
                                        label="Address line 1"
                                        errors={errors}
                                        client={client}
                                        optional
                                        className="sm:col-span-2"
                                    />
                                    <TextField
                                        name="address_2"
                                        label="Address line 2"
                                        errors={errors}
                                        client={client}
                                        optional
                                        className="sm:col-span-2"
                                    />
                                    <TextField
                                        name="city"
                                        label="City"
                                        errors={errors}
                                        client={client}
                                        optional
                                    />
                                    <TextField
                                        name="state"
                                        label="State / region"
                                        errors={errors}
                                        client={client}
                                        optional
                                    />
                                    <TextField
                                        name="postcode"
                                        label="Postcode"
                                        errors={errors}
                                        client={client}
                                        optional
                                    />
                                    <TextField
                                        name="country"
                                        label="Country code"
                                        errors={errors}
                                        client={client}
                                        optional
                                        fallback={defaultCountry}
                                    />
                                </div>
                            </SectionCard>

                            <SectionCard title="Billing">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="currency"
                                        label="Currency"
                                        error={errors.currency}
                                        hint={
                                            currencyLock ??
                                            "Can't be changed once the client has invoices, payments, services, orders or domains."
                                        }
                                    >
                                        <SelectField
                                            id="currency"
                                            name="currency"
                                            defaultValue={
                                                client?.currency ??
                                                currencies[0]
                                            }
                                            options={currencies.map((code) => ({
                                                value: code,
                                                label: code,
                                            }))}
                                            invalid={Boolean(errors.currency)}
                                            disabled={Boolean(currencyLock)}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="client_group_id"
                                        label="Client group"
                                        error={errors.client_group_id}
                                        optional
                                    >
                                        <SelectField
                                            id="client_group_id"
                                            name="client_group_id"
                                            defaultValue={
                                                client?.client_group_id
                                                    ? String(
                                                          client.client_group_id,
                                                      )
                                                    : ''
                                            }
                                            emptyLabel="No group"
                                            options={groups.map((group) => ({
                                                value: String(group.id),
                                                label: group.name,
                                            }))}
                                        />
                                    </Field>
                                    <TextField
                                        name="tax_id"
                                        label="Tax ID"
                                        errors={errors}
                                        client={client}
                                        optional
                                    />
                                    {client?.status === 'closed' ? (
                                        <Field
                                            htmlFor="status-closed"
                                            label="Status"
                                            hint="Reopen the client from its profile to change the status."
                                        >
                                            <Input
                                                id="status-closed"
                                                value={statusLabel('closed')}
                                                disabled
                                            />
                                        </Field>
                                    ) : (
                                        <Field
                                            htmlFor="status"
                                            label="Status"
                                            error={errors.status}
                                        >
                                            <SelectField
                                                id="status"
                                                name="status"
                                                defaultValue={
                                                    client?.status ?? 'active'
                                                }
                                                options={statuses.map(
                                                    (status) => ({
                                                        value: status,
                                                        label: statusLabel(
                                                            status,
                                                        ),
                                                    }),
                                                )}
                                            />
                                        </Field>
                                    )}
                                    <SwitchField
                                        id="tax_exempt"
                                        name="tax_exempt"
                                        label="Tax exempt"
                                        description="No tax is added to this client's invoices."
                                        defaultChecked={
                                            client?.tax_exempt ?? false
                                        }
                                        className="sm:col-span-2"
                                    />
                                </div>
                            </SectionCard>

                            <SectionCard
                                title="Notes"
                                description="Only visible to staff."
                            >
                                <Field
                                    htmlFor="notes"
                                    label="Internal notes"
                                    error={errors.notes}
                                    optional
                                >
                                    <Textarea
                                        id="notes"
                                        name="notes"
                                        rows={4}
                                        defaultValue={client?.notes ?? ''}
                                    />
                                </Field>
                            </SectionCard>

                            <FormActions sticky>
                                <Button variant="outline" asChild>
                                    <Link href={back.href}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {editing ? 'Save changes' : 'Create client'}
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

ClientForm.layout = {
    breadcrumbs: [{ title: 'Clients', href: ClientController.index() }],
};
