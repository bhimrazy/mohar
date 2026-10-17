import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { RouteAction } from '@/types/app';
import { CONTACT_ROLE_LABELS, CONTACT_ROLES } from '@/types/domains';
import type { ContactFields, DomainContacts } from '@/types/domains';

const FIELDS: {
    key: keyof ContactFields;
    label: string;
    optional?: boolean;
    span?: boolean;
    autoComplete?: string;
}[] = [
    { key: 'first_name', label: 'First name', autoComplete: 'given-name' },
    { key: 'last_name', label: 'Last name', autoComplete: 'family-name' },
    { key: 'company', label: 'Company', optional: true, span: true },
    { key: 'email', label: 'Email', autoComplete: 'email' },
    { key: 'phone', label: 'Phone', optional: true, autoComplete: 'tel' },
    { key: 'address_1', label: 'Address', optional: true, span: true },
    { key: 'address_2', label: 'Address line 2', optional: true, span: true },
    { key: 'city', label: 'City', optional: true },
    { key: 'state', label: 'State / region', optional: true },
    { key: 'postcode', label: 'Postcode', optional: true },
    { key: 'country', label: 'Country code', optional: true },
];

type ContactsFormProps = {
    action: RouteAction;
    contacts: DomainContacts;
    disabled?: boolean;
};

export function ContactsForm({
    action,
    contacts,
    disabled = false,
}: ContactsFormProps) {
    const form = useForm({ contacts });
    const errors = form.errors as Record<string, string | undefined>;

    const setField = (
        role: keyof DomainContacts,
        key: keyof ContactFields,
        value: string,
    ) =>
        form.setData('contacts', {
            ...form.data.contacts,
            [role]: { ...form.data.contacts[role], [key]: value },
        });

    const copyRegistrant = () =>
        form.setData('contacts', {
            registrant: form.data.contacts.registrant,
            admin: { ...form.data.contacts.registrant },
            tech: { ...form.data.contacts.registrant },
            billing: { ...form.data.contacts.registrant },
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Contacts"
                description="Changing the registrant can start an ownership verification and a 60-day transfer lock at the registry."
                actions={
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={copyRegistrant}
                    >
                        Use registrant for all
                    </Button>
                }
                footer={
                    <Button
                        type="submit"
                        disabled={disabled || form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Save contacts
                    </Button>
                }
            >
                <Tabs defaultValue="registrant">
                    <TabsList className="mb-4 flex-wrap">
                        {CONTACT_ROLES.map((role) => (
                            <TabsTrigger key={role} value={role}>
                                {CONTACT_ROLE_LABELS[role]}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {CONTACT_ROLES.map((role) => (
                        <TabsContent key={role} value={role}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {FIELDS.map((field) => {
                                    const id = `contact-${role}-${field.key}`;
                                    const error =
                                        errors[`contacts.${role}.${field.key}`];

                                    return (
                                        <Field
                                            key={field.key}
                                            htmlFor={id}
                                            label={field.label}
                                            optional={field.optional}
                                            error={error}
                                            className={
                                                field.span
                                                    ? 'sm:col-span-2'
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                id={id}
                                                value={
                                                    form.data.contacts[role][
                                                        field.key
                                                    ] ?? ''
                                                }
                                                disabled={disabled}
                                                autoComplete={
                                                    field.autoComplete
                                                }
                                                maxLength={
                                                    field.key === 'country'
                                                        ? 2
                                                        : 255
                                                }
                                                onChange={(event) =>
                                                    setField(
                                                        role,
                                                        field.key,
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={!!error}
                                                aria-describedby={`${id}-description`}
                                            />
                                        </Field>
                                    );
                                })}
                            </div>
                        </TabsContent>
                    ))}
                </Tabs>
            </SectionCard>
        </form>
    );
}
