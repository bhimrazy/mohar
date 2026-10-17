import { Form, Head } from '@inertiajs/react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Admin/TicketController';
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
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types/app';
import type { UploadLimits } from '@/types/support';

type Props = {
    departments: SelectOption[];
    priorities: SelectOption[];
    statuses: SelectOption[];
    client: { id: number; display_name: string; name: string; email: string } | null;
    limits: UploadLimits;
};

export default function CreateTicket({ departments, priorities, statuses, client, limits }: Props) {
    return (
        <>
            <Head title="Open ticket" />
            <Page size="narrow">
                <PageHeader
                    back={{ href: TicketController.index(), label: 'Tickets' }}
                    title="Open ticket"
                    description={client ? `On behalf of ${client.display_name}.` : 'Start a conversation with a client or any email address.'}
                />
                <Form {...TicketController.store.form()} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            {client && <input type="hidden" name="client_id" value={client.id} />}
                            <SectionCard title="Recipient">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field htmlFor="requester_name" label="Name" error={errors.requester_name}>
                                        <Input id="requester_name" name="requester_name" defaultValue={client?.name} required aria-invalid={!!errors.requester_name} />
                                    </Field>
                                    <Field htmlFor="requester_email" label="Email" error={errors.requester_email}>
                                        <Input id="requester_email" name="requester_email" type="email" defaultValue={client?.email} required aria-invalid={!!errors.requester_email} />
                                    </Field>
                                </div>
                            </SectionCard>
                            <SectionCard title="Message">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field htmlFor="department_id" label="Department" error={errors.department_id}>
                                        <Select name="department_id" defaultValue={departments[0]?.value}>
                                            <SelectTrigger id="department_id" className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {departments.map((department) => (
                                                    <SelectItem key={department.value} value={department.value}>
                                                        {department.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field htmlFor="priority" label="Priority" error={errors.priority}>
                                        <Select name="priority" defaultValue="medium">
                                            <SelectTrigger id="priority" className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {priorities.map((priority) => (
                                                    <SelectItem key={priority.value} value={priority.value}>
                                                        {priority.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field htmlFor="subject" label="Subject" error={errors.subject} className="sm:col-span-2">
                                        <Input id="subject" name="subject" required maxLength={255} aria-invalid={!!errors.subject} />
                                    </Field>
                                    <Field htmlFor="body" label="Message" hint="Markdown and tokens like {{ requester.first_name }} are supported." error={errors.body} className="sm:col-span-2">
                                        <Textarea id="body" name="body" rows={8} required aria-invalid={!!errors.body} aria-describedby="body-description" />
                                    </Field>
                                    <Field htmlFor="status" label="Status after sending" error={errors.status}>
                                        <Select name="status" defaultValue="answered">
                                            <SelectTrigger id="status" className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {statuses.map((status) => (
                                                    <SelectItem key={status.value} value={status.value}>
                                                        {status.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field htmlFor="service_id" label="Related service ID" optional error={errors.service_id}>
                                        <Input id="service_id" name="service_id" inputMode="numeric" />
                                    </Field>
                                    <Field htmlFor="attachments" label="Attachments" optional hint={`Up to ${limits.max_files} files, ${limits.max_mb} MB each.`} error={errors.attachments ?? errors['attachments.0']} className="sm:col-span-2">
                                        <Input id="attachments" name="attachments[]" type="file" multiple className="h-auto py-1.5" />
                                    </Field>
                                </div>
                            </SectionCard>
                            <FormActions>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Open ticket
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
