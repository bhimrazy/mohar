import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import DepartmentController from '@/actions/App/Modules/Support/Http/Controllers/Admin/DepartmentController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types/app';

type Department = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    email: string;
    is_hidden: boolean;
    clients_only: boolean;
    replies_only_by_email: boolean;
    send_autoresponse: boolean;
    autoresponse_message: string | null;
    prevent_client_close: boolean;
    sort_order: number;
    staff: string[];
};

const TOGGLES: { key: keyof Department; label: string; description: string }[] = [
    { key: 'clients_only', label: 'Clients only', description: 'Guests cannot open tickets here; unknown email senders are bounced.' },
    { key: 'is_hidden', label: 'Hidden', description: 'Not listed; reachable only through a direct link (?department=slug).' },
    { key: 'replies_only_by_email', label: 'Email replies only', description: 'Email cannot open new tickets, only reply to existing ones.' },
    { key: 'send_autoresponse', label: 'Send auto-response', description: 'Acknowledge new tickets from clients, guests and email.' },
    { key: 'prevent_client_close', label: 'Prevent client close', description: 'Only staff can close tickets opened here.' },
];

export default function DepartmentForm({ department, staff }: { department?: Department; staff: SelectOption[] }) {
    const [flags, setFlags] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(TOGGLES.map((toggle) => [toggle.key, Boolean(department?.[toggle.key] ?? toggle.key === 'send_autoresponse')])),
    );
    const [members, setMembers] = useState<string[]>(department?.staff ?? []);
    const action = department ? DepartmentController.update.form(department.id) : DepartmentController.store.form();

    return (
        <>
            <Head title={department ? `Edit ${department.name}` : 'New department'} />
            <Page size="narrow">
                <PageHeader back={{ href: DepartmentController.index(), label: 'Departments' }} title={department ? 'Edit department' : 'New department'} />
                <Form {...action} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            {Object.entries(flags).map(([key, value]) => (
                                <input key={key} type="hidden" name={key} value={value ? '1' : '0'} />
                            ))}
                            {members.map((id) => (
                                <input key={id} type="hidden" name="staff[]" value={id} />
                            ))}
                            {errors.department && <p className="text-sm text-destructive">{errors.department}</p>}
                            <SectionCard title="Details">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field htmlFor="name" label="Name" error={errors.name}>
                                        <Input id="name" name="name" defaultValue={department?.name} required aria-invalid={!!errors.name} />
                                    </Field>
                                    <Field htmlFor="email" label="Email address" hint="Sender of ticket mail and inbound routing address." error={errors.email}>
                                        <Input id="email" name="email" type="email" defaultValue={department?.email} required aria-invalid={!!errors.email} aria-describedby="email-description" />
                                    </Field>
                                    <Field htmlFor="slug" label="Slug" optional hint="Used in direct links." error={errors.slug}>
                                        <Input id="slug" name="slug" defaultValue={department?.slug} aria-describedby="slug-description" />
                                    </Field>
                                    <Field htmlFor="sort_order" label="Sort order" optional error={errors.sort_order}>
                                        <Input id="sort_order" name="sort_order" type="number" min={0} defaultValue={department?.sort_order ?? 0} />
                                    </Field>
                                    <Field htmlFor="description" label="Description" optional className="sm:col-span-2" error={errors.description}>
                                        <Textarea id="description" name="description" rows={2} defaultValue={department?.description ?? ''} />
                                    </Field>
                                </div>
                            </SectionCard>
                            <SectionCard title="Behaviour">
                                <div className="grid gap-3">
                                    {TOGGLES.map((toggle) => (
                                        <div key={toggle.key} className="flex items-center justify-between gap-4 rounded-lg border p-3">
                                            <div className="space-y-0.5">
                                                <label htmlFor={`flag-${toggle.key}`} className="text-sm font-medium">
                                                    {toggle.label}
                                                </label>
                                                <p className="text-sm text-muted-foreground">{toggle.description}</p>
                                            </div>
                                            <Switch
                                                id={`flag-${toggle.key}`}
                                                checked={flags[toggle.key]}
                                                onCheckedChange={(checked) => setFlags((current) => ({ ...current, [toggle.key]: checked }))}
                                            />
                                        </div>
                                    ))}
                                    <Field htmlFor="autoresponse_message" label="Auto-response message" optional hint="Markdown. Shown in the thread and emailed when a ticket is opened." error={errors.autoresponse_message}>
                                        <Textarea id="autoresponse_message" name="autoresponse_message" rows={4} defaultValue={department?.autoresponse_message ?? ''} aria-describedby="autoresponse_message-description" />
                                    </Field>
                                </div>
                            </SectionCard>
                            <SectionCard title="Staff" description="Assigned staff see this department's tickets and can be assigned to them.">
                                {staff.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No staff accounts yet.</p>
                                ) : (
                                    <ul className="grid gap-2 sm:grid-cols-2">
                                        {staff.map((member) => (
                                            <li key={member.value} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`staff-${member.value}`}
                                                    checked={members.includes(member.value)}
                                                    onCheckedChange={(checked) =>
                                                        setMembers((current) => (checked ? [...current, member.value] : current.filter((id) => id !== member.value)))
                                                    }
                                                />
                                                <Label htmlFor={`staff-${member.value}`} className="font-normal">
                                                    {member.label}
                                                </Label>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </SectionCard>
                            <FormActions sticky status={department && (
                                <ConfirmDialog
                                    trigger={<Button type="button" variant="ghost" className="text-destructive">Delete department</Button>}
                                    title={`Delete ${department.name}?`}
                                    description="Departments with tickets cannot be deleted; move their tickets first."
                                    confirmLabel="Delete department"
                                    action={DepartmentController.destroy(department.id)}
                                />
                            )}>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {department ? 'Save department' : 'Create department'}
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
