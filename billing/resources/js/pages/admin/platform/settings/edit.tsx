import { Link, router, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import { useState } from 'react';
import SettingsController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/SettingsController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { SwitchField } from '@/components/app/switch-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type SettingField = {
    key: string;
    label: string;
    type: 'string' | 'text' | 'integer' | 'boolean' | 'select' | 'email';
    help: string | null;
    options: { value: string; label: string }[];
    secret: boolean;
    value: unknown;
    is_set: boolean;
};

type Props = {
    sections: { key: string; title: string }[];
    section: {
        key: string;
        title: string;
        editable: boolean;
        fields: SettingField[];
    };
};

type Value = string | boolean;

function initial(field: SettingField): Value {
    if (field.type === 'boolean') {
        return Boolean(field.value);
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

export default function SettingsEdit({ sections, section }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [values, setValues] = useState<Record<string, Value>>(() =>
        Object.fromEntries(
            section.fields.map((field) => [field.key, initial(field)]),
        ),
    );
    const [processing, setProcessing] = useState(false);
    const set = (key: string, value: Value) =>
        setValues((current) => ({ ...current, [key]: value }));
    const disabled = !section.editable;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.visit(SettingsController.update.url(section.key), {
            method: 'put',
            data: nest(values) as Record<string, string>,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () =>
                setValues((current) =>
                    Object.fromEntries(
                        section.fields.map((field) => [
                            field.key,
                            field.secret ? '' : current[field.key],
                        ]),
                    ),
                ),
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
                        className="flex gap-1 overflow-x-auto md:flex-col"
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
                                    const id = `setting-${field.key.replace(/\./g, '-')}`;
                                    const error = errors[field.key];
                                    const value = values[field.key];

                                    if (field.type === 'boolean') {
                                        return (
                                            <SwitchField
                                                key={field.key}
                                                id={id}
                                                label={field.label}
                                                description={
                                                    error ??
                                                    field.help ??
                                                    undefined
                                                }
                                                checked={value === true}
                                                disabled={disabled}
                                                onCheckedChange={(checked) =>
                                                    set(field.key, checked)
                                                }
                                            />
                                        );
                                    }

                                    return (
                                        <Field
                                            key={field.key}
                                            htmlFor={id}
                                            label={field.label}
                                            error={error}
                                            hint={
                                                field.secret
                                                    ? field.is_set
                                                        ? 'A value is saved. Leave blank to keep it.'
                                                        : (field.help ??
                                                          'Not set.')
                                                    : (field.help ?? undefined)
                                            }
                                        >
                                            {field.type === 'select' ? (
                                                <SelectField
                                                    id={id}
                                                    value={String(value)}
                                                    onValueChange={(next) =>
                                                        set(field.key, next)
                                                    }
                                                    options={field.options}
                                                    disabled={disabled}
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
                                                    disabled={disabled}
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
                                                    disabled={disabled}
                                                    aria-invalid={Boolean(
                                                        error,
                                                    )}
                                                />
                                            ) : (
                                                <Input
                                                    id={id}
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
                                                    disabled={disabled}
                                                    aria-invalid={Boolean(
                                                        error,
                                                    )}
                                                    aria-describedby={`${id}-description`}
                                                    className={
                                                        field.type === 'integer'
                                                            ? 'max-w-40'
                                                            : undefined
                                                    }
                                                />
                                            )}
                                        </Field>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    </form>
                </div>
            </Page>
        </>
    );
}

SettingsEdit.layout = {
    breadcrumbs: [{ title: 'Settings', href: SettingsController.edit() }],
};
