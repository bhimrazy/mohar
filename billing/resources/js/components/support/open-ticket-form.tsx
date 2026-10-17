import { Form, Link } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { index as kbIndex } from '@/routes/support/kb';
import type { SelectOption } from '@/types/app';
import type { DepartmentOption, UploadLimits } from '@/types/support';
import type { RouteFormDefinition } from '@/wayfinder';

type OpenTicketFormProps = {
    form: RouteFormDefinition<'post'>;
    departments: DepartmentOption[];
    selected: string | null;
    priorities: SelectOption[];
    limits: UploadLimits;
    /** Guests also give their name and email. */
    guest?: boolean;
    /** Signed-in clients may reference one of their services. */
    showService?: boolean;
};

/** Department picker → ticket form, shared by the client area and the guest form. */
export function OpenTicketForm({
    form,
    departments,
    selected,
    priorities,
    limits,
    guest = false,
    showService = false,
}: OpenTicketFormProps) {
    const [department, setDepartment] = useState<string>(
        selected ?? (departments.length === 1 ? departments[0].value : ''),
    );

    return (
        <Form {...form} className="flex flex-col gap-6">
            {({ errors, processing }) => {
                const attachmentError = Object.entries(errors).find(([key]) =>
                    key.startsWith('attachments'),
                )?.[1];

                return (
                    <>
                        <input
                            type="hidden"
                            name="department_id"
                            value={department}
                        />
                        <SectionCard
                            title="1. Choose a department"
                            description="Pick the team that fits your question best."
                        >
                            <div
                                role="radiogroup"
                                aria-label="Department"
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {departments.map((option) => {
                                    const active = option.value === department;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={active}
                                            onClick={() =>
                                                setDepartment(option.value)
                                            }
                                            className={cn(
                                                'flex h-full flex-col items-start justify-start rounded-xl border p-4 text-left transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                                active
                                                    ? 'border-foreground bg-muted/40'
                                                    : 'hover:bg-muted/40',
                                            )}
                                        >
                                            <span className="block text-sm font-medium">
                                                {option.label}
                                            </span>
                                            {option.description && (
                                                <span className="mt-1 block text-sm text-muted-foreground">
                                                    {option.description}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                            {errors.department_id && (
                                <p className="mt-3 text-sm text-destructive">
                                    {errors.department_id}
                                </p>
                            )}
                        </SectionCard>

                        <SectionCard
                            title="2. Tell us what's going on"
                            actions={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={kbIndex()}>
                                        <BookOpen />
                                        Search help articles
                                    </Link>
                                </Button>
                            }
                        >
                            <div className="grid items-start gap-6 sm:grid-cols-2">
                                {guest && (
                                    <>
                                        <Field
                                            htmlFor="name"
                                            label="Your name"
                                            error={errors.name}
                                        >
                                            <Input
                                                id="name"
                                                name="name"
                                                autoComplete="name"
                                                required
                                                aria-invalid={!!errors.name}
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="email"
                                            label="Email address"
                                            hint="We'll send replies here."
                                            error={errors.email}
                                        >
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                autoComplete="email"
                                                required
                                                aria-invalid={!!errors.email}
                                                aria-describedby="email-description"
                                            />
                                        </Field>
                                        <div
                                            className="hidden"
                                            aria-hidden="true"
                                        >
                                            <Label htmlFor="website">
                                                Website
                                            </Label>
                                            <Input
                                                id="website"
                                                name="website"
                                                tabIndex={-1}
                                                autoComplete="off"
                                            />
                                        </div>
                                    </>
                                )}
                                <Field
                                    htmlFor="subject"
                                    label="Subject"
                                    error={errors.subject}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="subject"
                                        name="subject"
                                        maxLength={255}
                                        required
                                        placeholder="e.g. My website shows an error 500"
                                        aria-invalid={!!errors.subject}
                                    />
                                </Field>
                                <Field
                                    htmlFor="priority"
                                    label="Urgency"
                                    error={errors.priority}
                                >
                                    <Select
                                        name="priority"
                                        defaultValue="medium"
                                    >
                                        <SelectTrigger
                                            id="priority"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {priorities.map((priority) => (
                                                <SelectItem
                                                    key={priority.value}
                                                    value={priority.value}
                                                >
                                                    {priority.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                {showService && (
                                    <Field
                                        htmlFor="service_id"
                                        label="Related service number"
                                        optional
                                        hint="Shown on your services page."
                                        error={errors.service_id}
                                    >
                                        <Input
                                            id="service_id"
                                            name="service_id"
                                            inputMode="numeric"
                                            aria-describedby="service_id-description"
                                        />
                                    </Field>
                                )}
                                <Field
                                    htmlFor="body"
                                    label="Message"
                                    hint="Include error messages and what you already tried. Markdown is supported."
                                    error={errors.body}
                                    className="sm:col-span-2"
                                >
                                    <Textarea
                                        id="body"
                                        name="body"
                                        rows={8}
                                        required
                                        aria-invalid={!!errors.body}
                                        aria-describedby="body-description"
                                    />
                                </Field>
                                <Field
                                    htmlFor="attachments"
                                    label="Attachments"
                                    optional
                                    hint={`Up to ${limits.max_files} files, ${limits.max_mb} MB each: ${limits.extensions.join(', ')}.`}
                                    error={attachmentError}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="attachments"
                                        name="attachments[]"
                                        type="file"
                                        multiple
                                        accept={limits.extensions
                                            .map((extension) => `.${extension}`)
                                            .join(',')}
                                        className="h-auto py-1.5"
                                        aria-describedby="attachments-description"
                                    />
                                </Field>
                            </div>
                        </SectionCard>

                        <FormActions>
                            <Button
                                type="submit"
                                disabled={processing || department === ''}
                            >
                                {processing && <Spinner />}
                                Send request
                            </Button>
                        </FormActions>
                    </>
                );
            }}
        </Form>
    );
}
