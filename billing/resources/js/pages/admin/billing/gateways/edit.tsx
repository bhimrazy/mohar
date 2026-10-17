import { Head, Link, useForm } from '@inertiajs/react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/billing/select-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    index,
    update,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/GatewayController';
import type { SelectOption } from '@/types/app';

type SettingField = {
    key: string;
    label: string;
    type: 'string' | 'text' | 'secret' | 'secret_text' | 'boolean' | 'select';
    help?: string;
    options?: Record<string, string>;
};

const isSecret = (field: SettingField) =>
    field.type === 'secret' || field.type === 'secret_text';

function SettingControl({
    id,
    field,
    value,
    onChange,
}: {
    id: string;
    field: SettingField;
    value: string;
    onChange: (value: string) => void;
}) {
    switch (field.type) {
        case 'boolean':
            return (
                <Switch
                    id={id}
                    checked={value === '1' || value === 'true'}
                    onCheckedChange={(checked) => onChange(checked ? '1' : '0')}
                />
            );
        case 'select':
            return (
                <SelectField
                    id={id}
                    value={value}
                    onChange={onChange}
                    options={Object.entries(field.options ?? {}).map(
                        ([option, label]) => ({ value: option, label }),
                    )}
                />
            );
        case 'text':
        case 'secret_text':
            return (
                <Textarea
                    id={id}
                    rows={4}
                    autoComplete="off"
                    spellCheck={false}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        default:
            return (
                <Input
                    id={id}
                    type={field.type === 'secret' ? 'password' : 'text'}
                    autoComplete="off"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
    }
}

type Props = {
    gateway: {
        key: string;
        driver_name: string;
        display_name: string;
        is_active: boolean;
        show_on_order_form: boolean;
        sort_order: number;
        convert_to_currency: string | null;
        settings: Record<string, string>;
        secrets_set: Record<string, boolean>;
    };
    fields: SettingField[];
    currencies: string[];
    replacements: SelectOption[];
    webhook_url: string | null;
};

const NO_CONVERSION = 'none';

function ToggleRow({
    id,
    title,
    description,
    checked,
    onChange,
}: {
    id: string;
    title: string;
    description: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
            <div className="space-y-0.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {title}
                </label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

export default function GatewayEdit({
    gateway,
    fields,
    currencies,
    replacements,
    webhook_url,
}: Props) {
    const form = useForm({
        display_name: gateway.display_name,
        is_active: gateway.is_active,
        show_on_order_form: gateway.show_on_order_form,
        sort_order: String(gateway.sort_order ?? 0),
        convert_to_currency: gateway.convert_to_currency ?? NO_CONVERSION,
        settings: gateway.settings,
        replacement: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const deactivating = gateway.is_active && !form.data.is_active;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            sort_order: Number(data.sort_order) || 0,
            convert_to_currency:
                data.convert_to_currency === NO_CONVERSION
                    ? null
                    : data.convert_to_currency,
            replacement: data.replacement || null,
        }));
        form.submit(update(gateway.key));
    };

    return (
        <>
            <Head title={gateway.display_name} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: index(), label: 'Payment gateways' }}
                    title={gateway.display_name}
                    description={gateway.driver_name}
                />
                <form onSubmit={submit} className="grid gap-6">
                    <SectionCard title="General">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="display_name"
                                label="Name shown to clients"
                                error={errors.display_name}
                                className="content-start sm:col-span-2"
                            >
                                <Input
                                    id="display_name"
                                    value={form.data.display_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'display_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <ToggleRow
                                id="is_active"
                                title="Active"
                                description="Payments and callbacks are accepted."
                                checked={form.data.is_active}
                                onChange={(value) =>
                                    form.setData('is_active', value)
                                }
                            />
                            <ToggleRow
                                id="show_on_order_form"
                                title="Offer to clients"
                                description="Clients can choose it when paying."
                                checked={form.data.show_on_order_form}
                                onChange={(value) =>
                                    form.setData('show_on_order_form', value)
                                }
                            />
                            <Field
                                className="content-start"
                                htmlFor="sort_order"
                                label="Display order"
                                error={errors.sort_order}
                            >
                                <Input
                                    id="sort_order"
                                    inputMode="numeric"
                                    value={form.data.sort_order}
                                    onChange={(event) =>
                                        form.setData(
                                            'sort_order',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="convert_to_currency"
                                label="Charge in currency"
                                hint="Converts the balance at the configured exchange rate."
                                error={errors.convert_to_currency}
                            >
                                <SelectField
                                    id="convert_to_currency"
                                    value={form.data.convert_to_currency}
                                    onChange={(value) =>
                                        form.setData(
                                            'convert_to_currency',
                                            value,
                                        )
                                    }
                                    options={[
                                        {
                                            value: NO_CONVERSION,
                                            label: 'Invoice currency',
                                        },
                                        ...currencies.map((code) => ({
                                            value: code,
                                            label: code,
                                        })),
                                    ]}
                                />
                            </Field>
                            {deactivating && (
                                <Field
                                    htmlFor="replacement"
                                    label="Move unpaid invoices to"
                                    optional
                                    hint="Unpaid invoices using this gateway are reassigned."
                                    className="content-start sm:col-span-2"
                                >
                                    <SelectField
                                        id="replacement"
                                        value={form.data.replacement}
                                        onChange={(value) =>
                                            form.setData('replacement', value)
                                        }
                                        options={replacements}
                                        placeholder="Keep as is"
                                    />
                                </Field>
                            )}
                        </div>
                    </SectionCard>

                    {fields.length > 0 && (
                        <SectionCard title="Settings">
                            <div className="grid gap-6">
                                {fields.map((field) => {
                                    const id = `setting-${field.key}`;
                                    const value =
                                        form.data.settings[field.key] ?? '';
                                    const set = (next: string) =>
                                        form.setData('settings', {
                                            ...form.data.settings,
                                            [field.key]: next,
                                        });

                                    return (
                                        <Field
                                            className="content-start"
                                            key={field.key}
                                            htmlFor={id}
                                            label={field.label}
                                            hint={
                                                isSecret(field) &&
                                                gateway.secrets_set[field.key]
                                                    ? 'A value is stored. Leave empty to keep it.'
                                                    : field.help
                                            }
                                            error={
                                                errors[`settings.${field.key}`]
                                            }
                                        >
                                            <SettingControl
                                                id={id}
                                                field={field}
                                                value={value}
                                                onChange={set}
                                            />
                                        </Field>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    )}

                    {webhook_url && (
                        <SectionCard
                            title="Webhook"
                            description="Configure this URL at the gateway. Callbacks are verified by signature."
                        >
                            <code className="block rounded-md bg-muted px-3 py-2 text-sm break-all">
                                {webhook_url}
                            </code>
                        </SectionCard>
                    )}

                    <InputError message={errors.billing} />
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save gateway
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
