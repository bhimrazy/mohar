import { Link, router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import { useState } from 'react';
import SettingsController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/SettingsController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { CountrySelect } from '@/components/app/country-select';
import { SelectField } from '@/components/app/select-field';
import { SwitchField } from '@/components/app/switch-field';
import { CheckboxGroup } from '@/components/app/checkbox-group';
import {
    MailTestCard,
    type MailTestProps,
} from '@/components/platform/mail-test-card';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { fromMinor, labelWithCurrency, parseMoneyInput } from '@/lib/money';
import { cn } from '@/lib/utils';

/** Settings whose option list is long enough to need a search box. */
const SEARCHABLE = new Set(['system.timezone']);

type SettingField = {
    key: string;
    label: string;
    type:
        | 'string'
        | 'text'
        | 'integer'
        | 'boolean'
        | 'select'
        | 'multiselect'
        | 'email'
        | 'country'
        | 'money';
    help: string | null;
    options: { value: string; label: string }[];
    secret: boolean;
    required: boolean;
    value: unknown;
    is_set: boolean;
    /** Stored in the database (not only supplied by .env). */
    saved: boolean;
    locked_reason: string | null;
    /** Default currency code, for money fields. */
    currency: string | null;
};

type Props = {
    sections: { key: string; title: string }[];
    section: {
        key: string;
        title: string;
        editable: boolean;
        fields: SettingField[];
    };
    /** The mail server section's test send, for staff who can change it. */
    mailTest: MailTestProps | null;
};

type Value = string | boolean | string[];

/** Mail server fields that belong to one mailer, shown only while that mailer is chosen. */
function hidden(key: string, values: Record<string, Value>): boolean {
    const mailer = values['mail.default'];

    if (mailer === undefined) {
        return false;
    }

    if (key.startsWith('mail.mailers.smtp.')) {
        return mailer !== 'smtp';
    }

    return key.startsWith('services.ses.') && mailer !== 'ses';
}

function initial(field: SettingField): Value {
    if (field.type === 'boolean') {
        return Boolean(field.value);
    }

    if (field.type === 'multiselect') {
        return Array.isArray(field.value) ? field.value.map(String) : [];
    }

    if (field.secret) {
        return '';
    }

    return typeof field.value === 'string' || typeof field.value === 'number'
        ? String(field.value)
        : '';
}

/** "core.company_name" → { core: { company_name: v } } (matches the server's dot-notation rules). */
function nest(values: Record<string, Value>): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(values)) {
        const parts = key.split('.');
        let node = result;

        parts.slice(0, -1).forEach((part) => {
            node[part] =
                (node[part] as Record<string, unknown> | undefined) ?? {};
            node = node[part] as Record<string, unknown>;
        });
        node[parts[parts.length - 1]] = value;
    }

    return result;
}

export default function SettingsEdit({ sections, section, mailTest }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [saved, setSaved] = useState<Record<string, Value>>(() =>
        Object.fromEntries(
            section.fields.map((field) => [field.key, initial(field)]),
        ),
    );
    const [values, setValues] = useState(saved);
    // Saved secrets the form will remove on save (a blank secret field alone keeps the stored value).
    const [cleared, setCleared] = useState<string[]>([]);
    const unsaved =
        cleared.length > 0 ||
        section.fields.some(
            (field) =>
                JSON.stringify(values[field.key]) !==
                JSON.stringify(saved[field.key]),
        );
    const [processing, setProcessing] = useState(false);
    const set = (key: string, value: Value) =>
        setValues((current) => ({ ...current, [key]: value }));
    const disabled = !section.editable;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // "1,000.50" (US or lakh grouping) is sent as "1000.50"; anything else goes as typed for the server to refuse.
        const data = Object.fromEntries(
            section.fields.map((field) => {
                const value = values[field.key];
                const minor =
                    field.type === 'money' && typeof value === 'string'
                        ? parseMoneyInput(value)
                        : null;

                return [
                    field.key,
                    minor === null ? value : fromMinor(minor).toFixed(2),
                ];
            }),
        );
        router.visit(SettingsController.update.url(section.key), {
            method: 'put',
            data: {
                ...nest(data),
                ...(cleared.length > 0 ? { clear: cleared } : {}),
            } as Record<string, string>,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                const next = Object.fromEntries(
                    section.fields.map((field) => [
                        field.key,
                        field.secret ? '' : values[field.key],
                    ]),
                );
                setValues(next);
                setSaved(next);
                setCleared([]);
            },
        });
    };

    return (
        <>
            <Head title={`${section.title} settings`} />
            <Page>
                <PageHeader
                    title="Settings"
                    description="System-wide configuration. Each section is saved on its own."
                />

                <div className="grid gap-6 md:grid-cols-[200px_1fr]">
                    <nav
                        aria-label="Settings sections"
                        className="flex gap-1 overflow-x-auto md:row-span-2 md:flex-col"
                    >
                        {sections.map((item) => (
                            <Link
                                key={item.key}
                                href={SettingsController.edit(item.key)}
                                aria-current={
                                    item.key === section.key
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'rounded-md px-3 py-2 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                                    item.key === section.key &&
                                        'bg-muted font-medium text-foreground',
                                )}
                            >
                                {item.title}
                            </Link>
                        ))}
                    </nav>

                    <form onSubmit={submit} className="min-w-0">
                        {mailTest?.env_url && (
                            <Alert className="mb-6">
                                <Info />
                                <AlertDescription>
                                    {mailTest.env_url === 'overridden'
                                        ? 'MAIL_URL is set in the server environment (.env). The SMTP settings saved here replace it.'
                                        : 'MAIL_URL is set in the server environment (.env) and is used until you save SMTP settings here. Saved settings replace it.'}
                                </AlertDescription>
                            </Alert>
                        )}
                        <SectionCard
                            title={section.title}
                            description={
                                disabled
                                    ? 'You can view these settings but not change them.'
                                    : undefined
                            }
                            footer={
                                section.editable ? (
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Save {section.title.toLowerCase()}{' '}
                                        settings
                                    </Button>
                                ) : undefined
                            }
                        >
                            <div className="grid gap-6">
                                {section.fields.map((field) => {
                                    if (hidden(field.key, values)) {
                                        return null;
                                    }

                                    const id = `setting-${field.key.replace(/\./g, '-')}`;
                                    // A multiselect's refusals arrive per item ("key.0").
                                    const error =
                                        errors[field.key] ??
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith(`${field.key}.`),
                                        )?.[1];
                                    const value = values[field.key];
                                    const locked = field.locked_reason !== null;
                                    const off = disabled || locked;
                                    const help = locked
                                        ? [field.locked_reason, field.help]
                                              .filter(Boolean)
                                              .join(' ')
                                        : field.help;

                                    if (field.type === 'boolean') {
                                        return (
                                            <SwitchField
                                                key={field.key}
                                                id={id}
                                                label={field.label}
                                                description={
                                                    error ?? help ?? undefined
                                                }
                                                checked={value === true}
                                                disabled={off}
                                                onCheckedChange={(checked) =>
                                                    set(field.key, checked)
                                                }
                                            />
                                        );
                                    }

                                    if (field.type === 'multiselect') {
                                        return (
                                            <CheckboxGroup
                                                key={field.key}
                                                id={id}
                                                label={field.label}
                                                hint={help ?? undefined}
                                                error={error}
                                                options={field.options}
                                                value={
                                                    Array.isArray(value)
                                                        ? value
                                                        : []
                                                }
                                                disabled={off}
                                                onValueChange={(next) =>
                                                    set(field.key, next)
                                                }
                                            />
                                        );
                                    }

                                    return (
                                        <Field
                                            key={field.key}
                                            htmlFor={id}
                                            label={
                                                field.type === 'money' &&
                                                field.currency
                                                    ? labelWithCurrency(
                                                          field.label,
                                                          field.currency,
                                                      )
                                                    : field.label
                                            }
                                            error={error}
                                            hint={
                                                field.secret
                                                    ? field.is_set
                                                        ? 'A value is saved. Leave blank to keep it.'
                                                        : (help ?? 'Not set.')
                                                    : (help ?? undefined)
                                            }
                                        >
                                            {field.type === 'country' ? (
                                                <CountrySelect
                                                    id={id}
                                                    value={String(value)}
                                                    onValueChange={(next) =>
                                                        set(field.key, next)
                                                    }
                                                    emptyLabel={
                                                        field.required
                                                            ? undefined
                                                            : 'Not set'
                                                    }
                                                    disabled={disabled}
                                                    invalid={Boolean(error)}
                                                />
                                            ) : field.type === 'select' ? (
                                                <SelectField
                                                    id={id}
                                                    value={String(value)}
                                                    onValueChange={(next) =>
                                                        set(field.key, next)
                                                    }
                                                    options={field.options}
                                                    searchable={SEARCHABLE.has(
                                                        field.key,
                                                    )}
                                                    disabled={off}
                                                    invalid={Boolean(error)}
                                                />
                                            ) : field.type === 'text' ? (
                                                <Textarea
                                                    id={id}
                                                    rows={4}
                                                    value={String(value)}
                                                    onChange={(event) =>
                                                        set(
                                                            field.key,
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={off}
                                                    aria-invalid={Boolean(
                                                        error,
                                                    )}
                                                />
                                            ) : field.secret ? (
                                                <PasswordInput
                                                    id={id}
                                                    value={String(value)}
                                                    autoComplete="off"
                                                    placeholder={
                                                        field.is_set
                                                            ? '••••••••'
                                                            : ''
                                                    }
                                                    onChange={(event) =>
                                                        set(
                                                            field.key,
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={off}
                                                    aria-invalid={Boolean(
                                                        error,
                                                    )}
                                                />
                                            ) : (
                                                <Input
                                                    id={id}
                                                    inputMode={
                                                        field.type === 'money'
                                                            ? 'decimal'
                                                            : undefined
                                                    }
                                                    type={
                                                        field.type === 'integer'
                                                            ? 'number'
                                                            : field.type ===
                                                                'email'
                                                              ? 'email'
                                                              : 'text'
                                                    }
                                                    value={String(value)}
                                                    onChange={(event) =>
                                                        set(
                                                            field.key,
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={off}
                                                    aria-invalid={Boolean(
                                                        error,
                                                    )}
                                                    aria-describedby={`${id}-description`}
                                                    className={
                                                        field.type ===
                                                            'integer' ||
                                                        field.type === 'money'
                                                            ? 'max-w-40'
                                                            : undefined
                                                    }
                                                />
                                            )}
                                            {field.secret &&
                                                field.saved &&
                                                !off && (
                                                    <div className="mt-2 flex items-center gap-2">
                                                        <Checkbox
                                                            id={`${id}-clear`}
                                                            checked={cleared.includes(
                                                                field.key,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setCleared(
                                                                    (list) =>
                                                                        checked
                                                                            ? [
                                                                                  ...list,
                                                                                  field.key,
                                                                              ]
                                                                            : list.filter(
                                                                                  (
                                                                                      key,
                                                                                  ) =>
                                                                                      key !==
                                                                                      field.key,
                                                                              ),
                                                                )
                                                            }
                                                        />
                                                        <Label
                                                            htmlFor={`${id}-clear`}
                                                            className="text-sm font-normal"
                                                        >
                                                            Remove the saved
                                                            value on save
                                                        </Label>
                                                    </div>
                                                )}
                                        </Field>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    </form>
                    {mailTest && (
                        <div className="min-w-0 md:col-start-2">
                            <MailTestCard test={mailTest} unsaved={unsaved} />
                        </div>
                    )}
                </div>
            </Page>
        </>
    );
}

SettingsEdit.layout = {
    breadcrumbs: [{ title: 'Settings', href: SettingsController.edit() }],
};
