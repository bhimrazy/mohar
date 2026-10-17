import { Form, Head, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import NetworkIncidentController from '@/actions/App/Modules/Support/Http/Controllers/Admin/NetworkIncidentController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
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

type Incident = {
    id: number;
    title: string;
    type: string;
    affected: string | null;
    server_id: number | null;
    priority: string;
    status: string;
    description: string;
    starts_at: string;
    ends_at: string | null;
};

type Update = { id: number; body: string; status: string; created_at: string | null };

type Props = {
    incident?: Incident;
    updates?: Update[];
    types: SelectOption[];
    priorities: SelectOption[];
    statuses: SelectOption[];
};

function SelectField({ name, label, options, value, error }: { name: string; label: string; options: SelectOption[]; value: string; error?: string }) {
    return (
        <Field htmlFor={name} label={label} error={error}>
            <Select name={name} defaultValue={value}>
                <SelectTrigger id={name} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

export default function IncidentForm({ incident, updates = [], types, priorities, statuses }: Props) {
    const action = incident ? NetworkIncidentController.update.form(incident.id) : NetworkIncidentController.store.form();

    return (
        <>
            <Head title={incident ? incident.title : 'New incident'} />
            <Page>
                <PageHeader
                    back={{ href: NetworkIncidentController.index(), label: 'Network status' }}
                    title={incident ? incident.title : 'New incident'}
                    status={incident?.status}
                    actions={
                        incident && incident.status !== 'resolved' && (
                            <Button onClick={() => router.post(NetworkIncidentController.resolve.url(incident.id), {}, { preserveScroll: true })}>
                                <CheckCircle2 />
                                Mark resolved
                            </Button>
                        )
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <Form {...action} className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        {({ errors, processing }) => (
                            <>
                                <SectionCard title="Incident">
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <Field htmlFor="title" label="Title" error={errors.title} className="sm:col-span-2">
                                            <Input id="title" name="title" defaultValue={incident?.title} required aria-invalid={!!errors.title} />
                                        </Field>
                                        <SelectField name="type" label="Type" options={types} value={incident?.type ?? 'server'} error={errors.type} />
                                        <Field htmlFor="affected" label="Affected" optional hint="Server label or service, e.g. web03." error={errors.affected}>
                                            <Input id="affected" name="affected" defaultValue={incident?.affected ?? ''} aria-describedby="affected-description" />
                                        </Field>
                                        <SelectField name="priority" label="Priority" options={priorities} value={incident?.priority ?? 'medium'} error={errors.priority} />
                                        <SelectField name="status" label="Status" options={statuses} value={incident?.status ?? 'investigating'} error={errors.status} />
                                        <Field htmlFor="starts_at" label="Starts" error={errors.starts_at}>
                                            <Input id="starts_at" name="starts_at" type="datetime-local" defaultValue={incident?.starts_at} required />
                                        </Field>
                                        <Field htmlFor="ends_at" label="Ends" optional error={errors.ends_at}>
                                            <Input id="ends_at" name="ends_at" type="datetime-local" defaultValue={incident?.ends_at ?? ''} />
                                        </Field>
                                        <Field htmlFor="server_id" label="Server ID" optional hint="Highlights the incident for clients on that server." error={errors.server_id}>
                                            <Input id="server_id" name="server_id" inputMode="numeric" defaultValue={incident?.server_id ?? ''} aria-describedby="server_id-description" />
                                        </Field>
                                        <Field htmlFor="description" label="Description" hint="Markdown." error={errors.description} className="sm:col-span-2">
                                            <Textarea id="description" name="description" rows={6} defaultValue={incident?.description} required aria-describedby="description-description" />
                                        </Field>
                                    </div>
                                </SectionCard>
                                <FormActions
                                    status={incident && (
                                        <ConfirmDialog
                                            trigger={<Button type="button" variant="ghost" className="text-destructive">Delete</Button>}
                                            title={`Delete "${incident.title}"?`}
                                            description="The incident and its updates disappear from the status page. This can't be undone."
                                            confirmLabel="Delete incident"
                                            action={NetworkIncidentController.destroy(incident.id)}
                                        />
                                    )}
                                >
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {incident ? 'Save incident' : 'Publish incident'}
                                    </Button>
                                </FormActions>
                            </>
                        )}
                    </Form>

                    {incident && (
                        <SectionCard title="Updates" description="Newest first. Posting an update also sets the incident status.">
                            <Form {...NetworkIncidentController.addUpdate.form(incident.id)} resetOnSuccess options={{ preserveScroll: true }} className="mb-5 grid gap-3">
                                {({ errors, processing }) => (
                                    <>
                                        <Field htmlFor="update-body" label="New update" error={errors.body}>
                                            <Textarea id="update-body" name="body" rows={3} required />
                                        </Field>
                                        <div className="flex gap-2">
                                            <Select name="status" defaultValue={incident.status}>
                                                <SelectTrigger className="w-full" aria-label="Status">
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
                                            <Button type="submit" variant="outline" disabled={processing}>
                                                Post update
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                            <ol className="grid gap-4">
                                {updates.map((update) => (
                                    <li key={update.id} className="grid gap-1 border-l-2 pl-3">
                                        <div className="flex items-center gap-2">
                                            <StatusBadge status={update.status} />
                                            <DateTime value={update.created_at} relative className="text-xs text-muted-foreground" />
                                        </div>
                                        <p className="text-sm">{update.body}</p>
                                    </li>
                                ))}
                                {updates.length === 0 && <li className="text-sm text-muted-foreground">No updates posted yet.</li>}
                            </ol>
                        </SectionCard>
                    )}
                </div>
            </Page>
        </>
    );
}
