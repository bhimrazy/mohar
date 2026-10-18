import { Head, router, useForm, useHttp } from '@inertiajs/react';
import { AlertTriangle, RotateCcw, Send, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import EmailTemplateController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailTemplateController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { SwitchField } from '@/components/app/switch-field';
import { EmailFrame } from '@/components/notifications/email-frame';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { SelectOption } from '@/types/app';
import type { FieldGroups } from '@/types/notifications';

type Template = {
    id: number;
    key: string;
    name: string;
    type: string;
    audience: string;
    is_system: boolean;
    is_enabled: boolean;
    from_name: string | null;
    from_email: string | null;
    cc: string;
    bcc: string;
    plain_text_only: boolean;
    fixed_subject: boolean;
    locales: string[];
};

type Translation = {
    locale: string;
    exists: boolean;
    subject: string;
    heading: string;
    body: string;
    action_label: string;
    updated_at: string | null;
};

type Preview = {
    error: string | null;
    warnings: string[];
    subject: string | null;
    html: string | null;
    text: string | null;
};

type PreviewInput = {
    subject: string;
    heading: string;
    body: string;
    action_label: string;
    locale: string;
};

export default function TemplateEditor({
    template,
    translation,
    defaultLocale,
    fields,
    types,
    canManage,
    canManageDelivery,
    secretAction,
    systemAction,
}: {
    template: Template | null;
    translation: Translation;
    defaultLocale: string;
    fields: FieldGroups;
    types: SelectOption[];
    canManage: boolean;
    canManageDelivery: boolean;
    /** Secret-bearing email: the merge field its single action link must use. */
    secretAction: string | null;
    /** Client ticket email: the system adds the ticket button; only its label is editable. */
    systemAction: boolean;
}) {
    const form = useForm({
        key: 'custom.',
        type: types[0]?.value ?? 'general',
        name: template?.name ?? '',
        is_enabled: template?.is_enabled ?? true,
        plain_text_only: template?.plain_text_only ?? false,
        from_name: template?.from_name ?? '',
        from_email: template?.from_email ?? '',
        cc: template?.cc ?? '',
        bcc: template?.bcc ?? '',
        locale: translation.locale,
        subject: translation.subject,
        heading: translation.heading,
        body: translation.body,
        action_label: translation.action_label,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const body = useRef<HTMLTextAreaElement>(null);
    const readOnly = !canManage;
    // Sender and copies can redirect mail (incl. account links): a separate, sensitive permission.
    const deliveryLocked = readOnly || !canManageDelivery;

    const previewRequest = useHttp<PreviewInput, Preview>({
        subject: '',
        heading: '',
        body: '',
        action_label: '',
        locale: '',
    });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [scheme, setScheme] = useState<'light' | 'dark'>('light');

    useEffect(() => {
        if (!template) {
            return;
        }

        const timer = setTimeout(() => {
            previewRequest.transform(() => ({
                subject: form.data.subject || ' ',
                heading: form.data.heading,
                body: form.data.body || ' ',
                action_label: form.data.action_label,
                locale: form.data.locale,
            }));
            previewRequest
                .post(EmailTemplateController.preview.url(template.id))
                .then((response) => setPreview(response))
                .catch(() => setPreview(null));
        }, 400);

        return () => clearTimeout(timer);
    }, [
        form.data.subject,
        form.data.heading,
        form.data.body,
        form.data.action_label,
        form.data.locale,
        template?.id,
    ]);

    const insertField = (path: string) => {
        const token =
            path === 'signature' ? '{{ signature }}' : `{{ ${path} }}`;
        const element = body.current;
        const start = element?.selectionStart ?? form.data.body.length;
        const end = element?.selectionEnd ?? start;
        form.setData(
            'body',
            form.data.body.slice(0, start) + token + form.data.body.slice(end),
        );
        requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(
                start + token.length,
                start + token.length,
            );
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (template) {
            form.transform(({ key, type, ...data }) => {
                void key;
                void type;

                return data;
            });
            form.submit(EmailTemplateController.update(template.id), {
                preserveScroll: true,
            });
        } else {
            form.submit(EmailTemplateController.store());
        }
    };

    const switchLocale = (locale: string) => {
        if (template && locale) {
            router.get(
                EmailTemplateController.edit.url(template.id, {
                    query: { locale },
                }),
            );
        }
    };

    const localeOptions: SelectOption[] = Array.from(
        new Set([...(template?.locales ?? []), translation.locale]),
    ).map((locale) => ({
        value: locale,
        label:
            locale.toUpperCase() +
            (locale === defaultLocale ? ' (default)' : ''),
    }));

    return (
        <>
            <Head title={template ? template.name : 'New email template'} />
            <Page>
                <PageHeader
                    back={{
                        href: EmailTemplateController.index(),
                        label: 'Email templates',
                    }}
                    title={template ? template.name : 'New email template'}
                    description={
                        template ? (
                            <span className="font-mono text-xs">
                                {template.key}
                            </span>
                        ) : (
                            'Custom templates can be sent manually to clients or used as a product welcome email.'
                        )
                    }
                    badges={
                        template && (
                            <>
                                <Badge variant="outline">
                                    {template.is_system ? 'System' : 'Custom'}
                                </Badge>
                                {!template.is_enabled && (
                                    <Badge variant="secondary">Disabled</Badge>
                                )}
                            </>
                        )
                    }
                    actions={
                        template && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        EmailTemplateController.test.url(
                                            template.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Send />
                                Send test to me
                            </Button>
                        )
                    }
                />
                <form
                    onSubmit={submit}
                    className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16rem]"
                >
                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard
                            title="Message"
                            description={
                                template
                                    ? 'Edit the text for one language at a time.'
                                    : undefined
                            }
                            actions={
                                template && (
                                    <SelectField
                                        id="locale-switch"
                                        options={localeOptions}
                                        value={translation.locale}
                                        onValueChange={switchLocale}
                                        className="w-40"
                                    />
                                )
                            }
                        >
                            <div className="grid gap-4">
                                {secretAction && (
                                    <Alert>
                                        <ShieldCheck />
                                        <AlertTitle>Plain text only</AlertTitle>
                                        <AlertDescription>
                                            This email carries a one-time link.
                                            Edit the heading, text and button
                                            label as plain text: no links,
                                            images, HTML or web addresses. The
                                            system adds the button for the link
                                            itself; it only points to this app's
                                            address, and the email is never
                                            copied to anyone.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {systemAction && (
                                    <Alert>
                                        <ShieldCheck />
                                        <AlertTitle>
                                            The system adds the ticket button
                                        </AlertTitle>
                                        <AlertDescription>
                                            A guest's ticket link carries the
                                            ticket's access key, so it can't be
                                            used in the text. The email ends
                                            with a button to the ticket; you can
                                            change its label below.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {!translation.exists && template && (
                                    <p className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                                        No {translation.locale.toUpperCase()}{' '}
                                        text yet — showing the default language.
                                        Saving creates the{' '}
                                        {translation.locale.toUpperCase()}{' '}
                                        version.
                                    </p>
                                )}
                                {!template && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            htmlFor="key"
                                            label="Key"
                                            hint="Starts with custom."
                                            error={errors.key}
                                        >
                                            <Input
                                                id="key"
                                                value={form.data.key}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'key',
                                                        event.target.value,
                                                    )
                                                }
                                                className="font-mono"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="type"
                                            label="Type"
                                            error={errors.type}
                                        >
                                            <SelectField
                                                id="type"
                                                options={types}
                                                value={form.data.type}
                                                onValueChange={(value) =>
                                                    form.setData('type', value)
                                                }
                                            />
                                        </Field>
                                    </div>
                                )}
                                <Field
                                    htmlFor="name"
                                    label="Name"
                                    hint="Shown to staff only."
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        disabled={readOnly}
                                        required
                                    />
                                </Field>
                                {!template && (
                                    <Field
                                        htmlFor="new-locale"
                                        label="Language"
                                        error={errors.locale}
                                    >
                                        <Input
                                            id="new-locale"
                                            value={form.data.locale}
                                            onChange={(event) =>
                                                form.setData(
                                                    'locale',
                                                    event.target.value,
                                                )
                                            }
                                            className="w-24 font-mono"
                                        />
                                    </Field>
                                )}
                                <Field
                                    htmlFor="subject"
                                    label="Subject"
                                    hint={
                                        template?.fixed_subject
                                            ? 'Support subjects keep the ticket format so replies thread correctly.'
                                            : undefined
                                    }
                                    error={errors.subject}
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
                                        disabled={
                                            readOnly || template?.fixed_subject
                                        }
                                        className="font-mono text-sm"
                                        required
                                    />
                                </Field>
                                {secretAction && (
                                    <Field
                                        htmlFor="heading"
                                        label="Heading"
                                        error={errors.heading}
                                    >
                                        <Input
                                            id="heading"
                                            value={form.data.heading}
                                            onChange={(event) =>
                                                form.setData(
                                                    'heading',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={readOnly}
                                            required
                                        />
                                    </Field>
                                )}
                                <Field
                                    htmlFor="body"
                                    label="Body"
                                    hint={
                                        secretAction
                                            ? 'Plain text. Separate paragraphs with a blank line; the button goes after the first one.'
                                            : 'Markdown. {% if path %}…{% endif %} and {% for item in list %}…{% endfor %} are supported.'
                                    }
                                    error={errors.body}
                                >
                                    <Textarea
                                        id="body"
                                        ref={body}
                                        rows={20}
                                        value={form.data.body}
                                        onChange={(event) =>
                                            form.setData(
                                                'body',
                                                event.target.value,
                                            )
                                        }
                                        disabled={readOnly}
                                        className="font-mono text-sm"
                                        aria-invalid={!!errors.body}
                                        required
                                    />
                                </Field>
                                {(secretAction || systemAction) && (
                                    <Field
                                        htmlFor="action_label"
                                        label="Button label"
                                        hint={
                                            systemAction
                                                ? 'Plain text. Leave empty for "View your ticket".'
                                                : undefined
                                        }
                                        error={errors.action_label}
                                    >
                                        <Input
                                            id="action_label"
                                            value={form.data.action_label}
                                            onChange={(event) =>
                                                form.setData(
                                                    'action_label',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={readOnly}
                                            required={!!secretAction}
                                        />
                                    </Field>
                                )}
                            </div>
                        </SectionCard>
                        <SectionCard
                            title="Sending"
                            description={
                                canManageDelivery
                                    ? 'Leave sender fields empty to use the system defaults. Senders must be on your authorised sending domain (SPF/DKIM) so DMARC passes. Copies are never added to mail carrying reset, verification or invitation links.'
                                    : 'Sender, CC and BCC need the email delivery permission.'
                            }
                        >
                            <div className="grid gap-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        htmlFor="from_name"
                                        label="From name"
                                        optional
                                        error={errors.from_name}
                                    >
                                        <Input
                                            id="from_name"
                                            value={form.data.from_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'from_name',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={deliveryLocked}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="from_email"
                                        label="From address"
                                        optional
                                        error={errors.from_email}
                                    >
                                        <Input
                                            id="from_email"
                                            type="email"
                                            value={form.data.from_email}
                                            onChange={(event) =>
                                                form.setData(
                                                    'from_email',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={deliveryLocked}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="cc"
                                        label="CC"
                                        optional
                                        hint="Comma-separated."
                                        error={errors.cc}
                                    >
                                        <Input
                                            id="cc"
                                            value={form.data.cc}
                                            onChange={(event) =>
                                                form.setData(
                                                    'cc',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={deliveryLocked}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="bcc"
                                        label="BCC"
                                        optional
                                        hint="Comma-separated."
                                        error={errors.bcc}
                                    >
                                        <Input
                                            id="bcc"
                                            value={form.data.bcc}
                                            onChange={(event) =>
                                                form.setData(
                                                    'bcc',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={deliveryLocked}
                                        />
                                    </Field>
                                </div>
                                <SwitchField
                                    id="is_enabled"
                                    label="Enabled"
                                    description="Disabled templates are logged as suppressed instead of sent."
                                    checked={form.data.is_enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_enabled', checked)
                                    }
                                    disabled={readOnly}
                                />
                                <SwitchField
                                    id="plain_text_only"
                                    label="Plain text only"
                                    description="Send without the HTML layout."
                                    checked={form.data.plain_text_only}
                                    onCheckedChange={(checked) =>
                                        form.setData('plain_text_only', checked)
                                    }
                                    disabled={readOnly}
                                />
                            </div>
                        </SectionCard>
                        {canManage && (
                            <FormActions
                                sticky
                                status={
                                    template && (
                                        <div className="flex flex-wrap gap-1">
                                            {template.is_system ? (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                        >
                                                            <RotateCcw />
                                                            Restore default
                                                        </Button>
                                                    }
                                                    title="Restore the default text?"
                                                    description={`The ${defaultLocale.toUpperCase()} subject and body go back to the shipped wording. Other languages are not changed.`}
                                                    confirmLabel="Restore default"
                                                    action={EmailTemplateController.restore(
                                                        template.id,
                                                    )}
                                                />
                                            ) : (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            className="text-destructive"
                                                        >
                                                            Delete
                                                        </Button>
                                                    }
                                                    title={`Delete "${template.name}"?`}
                                                    description="Products using it as their welcome email fall back to the default welcome template."
                                                    confirmLabel="Delete template"
                                                    destructive
                                                    action={EmailTemplateController.destroy(
                                                        template.id,
                                                    )}
                                                />
                                            )}
                                        </div>
                                    )
                                }
                            >
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing && <Spinner />}
                                    {template
                                        ? 'Save template'
                                        : 'Create template'}
                                </Button>
                            </FormActions>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4 xl:sticky xl:top-4">
                        <SectionCard
                            title="Preview"
                            description="Rendered with sample data as you type."
                        >
                            {!template ? (
                                <p className="text-sm text-muted-foreground">
                                    Create the template to preview it.
                                </p>
                            ) : preview?.error ? (
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertTitle>
                                        This template can't be saved yet
                                    </AlertTitle>
                                    <AlertDescription>
                                        {preview.error}
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <div className="grid gap-3">
                                    {preview && preview.warnings.length > 0 && (
                                        <Alert>
                                            <AlertTriangle />
                                            <AlertTitle>
                                                {preview.warnings.length}{' '}
                                                warning
                                                {preview.warnings.length > 1
                                                    ? 's'
                                                    : ''}
                                            </AlertTitle>
                                            <AlertDescription>
                                                <ul className="list-disc pl-4">
                                                    {preview.warnings.map(
                                                        (warning) => (
                                                            <li key={warning}>
                                                                {warning}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <div className="rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Subject:{' '}
                                        </span>
                                        <span className="font-medium">
                                            {preview?.subject ?? '…'}
                                        </span>
                                    </div>
                                    <Tabs defaultValue="html">
                                        <div className="flex items-center justify-between gap-2">
                                            <TabsList>
                                                <TabsTrigger value="html">
                                                    HTML
                                                </TabsTrigger>
                                                <TabsTrigger value="text">
                                                    Text
                                                </TabsTrigger>
                                            </TabsList>
                                            <ToggleGroup
                                                type="single"
                                                size="sm"
                                                variant="outline"
                                                value={scheme}
                                                onValueChange={(value) =>
                                                    value &&
                                                    setScheme(
                                                        value as
                                                            | 'light'
                                                            | 'dark',
                                                    )
                                                }
                                                aria-label="Preview colour scheme"
                                            >
                                                <ToggleGroupItem value="light">
                                                    Light
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="dark">
                                                    Dark
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                        </div>
                                        <TabsContent
                                            value="html"
                                            className="pt-3"
                                        >
                                            <EmailFrame
                                                title="Email preview"
                                                html={preview?.html ?? null}
                                                text={preview?.text}
                                                scheme={scheme}
                                            />
                                        </TabsContent>
                                        <TabsContent
                                            value="text"
                                            className="pt-3"
                                        >
                                            <EmailFrame
                                                title="Text preview"
                                                html={null}
                                                text={preview?.text}
                                            />
                                        </TabsContent>
                                    </Tabs>
                                </div>
                            )}
                        </SectionCard>
                    </div>

                    <SectionCard
                        title="Merge fields"
                        description={canManage ? 'Click to insert.' : undefined}
                        className="xl:sticky xl:top-4"
                        contentClassName="max-h-[70vh] overflow-y-auto"
                    >
                        <div className="grid gap-4">
                            {Object.entries(fields).map(([group, paths]) => (
                                <div key={group} className="grid gap-1">
                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {group}
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {paths.map((path) => (
                                            <button
                                                key={path}
                                                type="button"
                                                disabled={readOnly}
                                                onClick={() =>
                                                    insertField(path)
                                                }
                                                className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground hover:bg-accent disabled:cursor-default disabled:hover:bg-muted"
                                            >
                                                {path.replace(`${group}.`, '')}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </SectionCard>
                </form>
            </Page>
        </>
    );
}
