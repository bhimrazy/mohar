import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import ClientEmailController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/ClientEmailController';
import EmailLogController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailLogController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types/app';
import type { FieldGroups } from '@/types/notifications';

export default function ComposeClientEmail({
    client,
    templates,
    fields,
}: {
    client: { id: number; name: string; email: string };
    templates: SelectOption[];
    fields: FieldGroups;
}) {
    const [template, setTemplate] = useState('');

    return (
        <>
            <Head title={`Email ${client.name}`} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: EmailLogController.index({
                            query: { client: client.id },
                        }),
                        label: 'Emails',
                    }}
                    title={`Email ${client.name}`}
                    description={`Sent to ${client.email}. Email preferences don't apply to messages you send by hand.`}
                />
                <Form
                    {...ClientEmailController.store.form(client.id)}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <SectionCard>
                                <div className="grid gap-6">
                                    <Field
                                        htmlFor="template_key"
                                        label="Template"
                                        optional
                                        hint="Pick a template, or leave empty and write a message."
                                        error={errors.template_key}
                                    >
                                        <SelectField
                                            id="template_key"
                                            name="template_key"
                                            options={templates}
                                            value={template}
                                            onValueChange={setTemplate}
                                            placeholder="Write a message"
                                            emptyLabel="Write a message"
                                        />
                                    </Field>
                                    {!template && (
                                        <>
                                            <Field
                                                htmlFor="subject"
                                                label="Subject"
                                                error={errors.subject}
                                            >
                                                <Input
                                                    id="subject"
                                                    name="subject"
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                htmlFor="body"
                                                label="Message"
                                                hint={
                                                    <>
                                                        Markdown. Fields:{' '}
                                                        {(fields.client ?? [])
                                                            .slice(0, 6)
                                                            .map((path) => (
                                                                <code
                                                                    key={path}
                                                                    className="mx-0.5 rounded bg-muted px-1 font-mono text-xs"
                                                                >{`{{ ${path} }}`}</code>
                                                            ))}
                                                    </>
                                                }
                                                error={errors.body}
                                            >
                                                <Textarea
                                                    id="body"
                                                    name="body"
                                                    rows={12}
                                                    className="font-mono text-sm"
                                                    required
                                                />
                                            </Field>
                                        </>
                                    )}
                                </div>
                            </SectionCard>
                            <FormActions>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Send email
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
