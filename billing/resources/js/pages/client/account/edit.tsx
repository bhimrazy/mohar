import { Form, Head } from '@inertiajs/react';
import AccountController from '@/actions/App/Modules/Core/Http/Controllers/Client/AccountController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { ClientRecord } from '@/types';

type Props = { client: ClientRecord };

type FieldSpec = {
    name: keyof ClientRecord;
    label: string;
    type?: string;
    optional?: boolean;
    autoComplete?: string;
    wide?: boolean;
};

const contactFields: FieldSpec[] = [
    { name: 'first_name', label: 'First name', autoComplete: 'given-name' },
    { name: 'last_name', label: 'Last name', autoComplete: 'family-name' },
    {
        name: 'company_name',
        label: 'Company',
        optional: true,
        autoComplete: 'organization',
        wide: true,
    },
    {
        name: 'email',
        label: 'Billing email',
        type: 'email',
        autoComplete: 'email',
    },
    {
        name: 'phone',
        label: 'Phone',
        type: 'tel',
        optional: true,
        autoComplete: 'tel',
    },
];

const addressFields: FieldSpec[] = [
    {
        name: 'address_1',
        label: 'Address',
        autoComplete: 'address-line1',
        wide: true,
    },
    {
        name: 'address_2',
        label: 'Apartment, suite, etc.',
        optional: true,
        autoComplete: 'address-line2',
        wide: true,
    },
    { name: 'city', label: 'City', autoComplete: 'address-level2' },
    {
        name: 'state',
        label: 'State / region',
        optional: true,
        autoComplete: 'address-level1',
    },
    { name: 'postcode', label: 'Postcode', autoComplete: 'postal-code' },
    { name: 'country', label: 'Country code', autoComplete: 'country' },
    { name: 'tax_id', label: 'Tax / VAT number', optional: true, wide: true },
];

export default function AccountEdit({ client }: Props) {
    const render = (
        fields: FieldSpec[],
        errors: Record<string, string | undefined>,
    ) => (
        <div className="grid gap-6 sm:grid-cols-2">
            {fields.map((field) => {
                const value = client[field.name];

                return (
                    <Field
                        key={field.name}
                        htmlFor={field.name}
                        label={field.label}
                        optional={field.optional}
                        error={errors[field.name]}
                        className={field.wide ? 'sm:col-span-2' : undefined}
                    >
                        <Input
                            id={field.name}
                            name={field.name}
                            type={field.type ?? 'text'}
                            defaultValue={
                                typeof value === 'string' ? value : ''
                            }
                            autoComplete={field.autoComplete}
                            aria-invalid={Boolean(errors[field.name])}
                            aria-describedby={`${field.name}-description`}
                        />
                    </Field>
                );
            })}
        </div>
    );

    return (
        <>
            <Head title="Account details" />
            <Page size="narrow">
                <PageHeader
                    title="Account details"
                    description="These details appear on your invoices. Your sign-in email and password are in your profile settings."
                />

                <Form
                    {...AccountController.update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <SectionCard title="Contact">
                                {render(contactFields, errors)}
                            </SectionCard>
                            <SectionCard
                                title="Billing address"
                                footer={
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Save details
                                    </Button>
                                }
                            >
                                {render(addressFields, errors)}
                            </SectionCard>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

AccountEdit.layout = {
    breadcrumbs: [{ title: 'Account details', href: AccountController.edit() }],
};
