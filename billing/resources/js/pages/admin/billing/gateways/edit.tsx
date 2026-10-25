import { Link, router, useForm } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Head } from '@/components/app/head';
import { Field } from '@/components/app/field';
import { StatusBadge } from '@/components/app/status-badge';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import {
    CertificateFieldInput,
    SettingFieldInput,
} from '@/components/app/setting-field';
import type {
    CertificateInfoData,
    SettingFieldData,
} from '@/components/app/setting-field';
import { GatewayLogo } from '@/components/billing/gateway-logo';
import type { GatewayLogoData } from '@/components/billing/gateway-logo';
import { SelectField } from '@/components/billing/select-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    index,
    test as testConnection,
    update,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/GatewayController';
import type { SelectOption } from '@/types/app';

type Props = {
    gateway: {
        key: string;
        driver_name: string;
        display_name: string;
        sandbox: boolean;
        logo: GatewayLogoData | null;
        capabilities: string[];
        is_active: boolean;
        show_on_order_form: boolean;
        sort_order: number;
        convert_to_currency: string | null;
        settings: Record<string, string>;
        secrets_set: Record<string, boolean>;
        /** Keys of stored secrets shorter than their minimum. */
        secrets_short: string[];
        /** Stored certificate details per certificate setting; null when none is stored. */
        certificates: Record<string, CertificateInfoData | null>;
    };
    fields: SettingFieldData[];
    currencies: string[];
    replacements: SelectOption[];
    webhook_url: string | null;
    /** Where the provider sends customers back (hosted gateways). */
    return_url: string | null;
    can_test_connection: boolean;
};

type CertificateUpload = { file: File | null; passphrase: string };

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
    return_url,
    can_test_connection,
}: Props) {
    const [testing, setTesting] = useState(false);
    const [copied, copy] = useClipboard();
    const form = useForm({
        display_name: gateway.display_name,
        is_active: gateway.is_active,
        show_on_order_form: gateway.show_on_order_form,
        sort_order: String(gateway.sort_order ?? 0),
        convert_to_currency: gateway.convert_to_currency ?? NO_CONVERSION,
        settings: gateway.settings,
        certificates: Object.fromEntries(
            Object.keys(gateway.certificates).map((key) => [
                key,
                { file: null, passphrase: '' },
            ]),
        ) as Record<string, CertificateUpload>,
        replacement: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const deactivating = gateway.is_active && !form.data.is_active;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // A file goes as multipart, which PHP reads only from a POST: spoof the PUT.
        const uploading = Object.values(form.data.certificates).some(
            (upload) => upload.file !== null,
        );
        form.transform((data) => ({
            ...data,
            ...(uploading ? { _method: 'put' } : {}),
            sort_order: Number(data.sort_order) || 0,
            convert_to_currency:
                data.convert_to_currency === NO_CONVERSION
                    ? null
                    : data.convert_to_currency,
            replacement: data.replacement || null,
        }));

        if (uploading) {
            form.post(update.url(gateway.key), { forceFormData: true });

            return;
        }

        form.submit(update(gateway.key));
    };

    return (
        <>
            <Head title={gateway.display_name} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: index(), label: 'Payment gateways' }}
                    title={gateway.display_name}
                    badges={
                        gateway.sandbox ? (
                            <StatusBadge
                                status="sandbox"
                                tone="warning"
                                label="Sandbox — no money moves"
                            />
                        ) : undefined
                    }
                    description={
                        gateway.logo ? (
                            <span className="flex items-center gap-2">
                                <GatewayLogo
                                    name={gateway.driver_name}
                                    logo={gateway.logo}
                                    decorative
                                />
                                {gateway.driver_name}
                            </span>
                        ) : (
                            gateway.driver_name
                        )
                    }
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

                                    if (field.type === 'certificate') {
                                        const upload = form.data
                                            .certificates[field.key] ?? {
                                            file: null,
                                            passphrase: '',
                                        };
                                        const setUpload = (
                                            next: Partial<CertificateUpload>,
                                        ) =>
                                            form.setData('certificates', {
                                                ...form.data.certificates,
                                                [field.key]: {
                                                    ...upload,
                                                    ...next,
                                                },
                                            });

                                        return (
                                            <CertificateFieldInput
                                                key={field.key}
                                                id={id}
                                                field={field}
                                                stored={
                                                    gateway.certificates[
                                                        field.key
                                                    ] ?? null
                                                }
                                                passphrase={upload.passphrase}
                                                onFileChange={(file) =>
                                                    setUpload({ file })
                                                }
                                                onPassphraseChange={(
                                                    passphrase,
                                                ) => setUpload({ passphrase })}
                                                fileError={
                                                    errors[
                                                        `certificates.${field.key}.file`
                                                    ]
                                                }
                                                passphraseError={
                                                    errors[
                                                        `certificates.${field.key}.passphrase`
                                                    ]
                                                }
                                            />
                                        );
                                    }

                                    return (
                                        <SettingFieldInput
                                            key={field.key}
                                            className="content-start"
                                            id={id}
                                            field={field}
                                            value={value}
                                            onChange={set}
                                            secretStored={
                                                gateway.secrets_set[field.key]
                                            }
                                            secretShort={gateway.secrets_short.includes(
                                                field.key,
                                            )}
                                            checkMinLength
                                            error={
                                                errors[`settings.${field.key}`]
                                            }
                                        />
                                    );
                                })}
                            </div>
                        </SectionCard>
                    )}

                    {can_test_connection && (
                        <SectionCard
                            title="Connection"
                            description="Checks the saved credentials with the provider. No money moves. Save your changes first."
                        >
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit"
                                disabled={testing || form.isDirty}
                                onClick={() =>
                                    router.post(
                                        testConnection.url(gateway.key),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setTesting(true),
                                            onFinish: () => setTesting(false),
                                        },
                                    )
                                }
                            >
                                {testing && <Spinner />}
                                Test connection
                            </Button>
                        </SectionCard>
                    )}

                    {gateway.capabilities.includes('redirect') && (
                        <SectionCard title="Refunds">
                            <p className="text-sm text-muted-foreground">
                                {!gateway.capabilities.includes('refund')
                                    ? `${gateway.display_name} has no refund API: refund in the ${gateway.driver_name} merchant portal first, then record the refund on the invoice.`
                                    : !gateway.capabilities.includes(
                                            'partial_refund',
                                        )
                                      ? `Full refunds go through ${gateway.driver_name}. Refund part of a payment in the ${gateway.driver_name} merchant portal, then record it on the invoice.`
                                      : `Full and partial refunds go through ${gateway.driver_name}.`}
                            </p>
                        </SectionCard>
                    )}

                    {return_url && (
                        <SectionCard
                            title="Return address"
                            description="Customers come back here after paying, whether or not the payment went through; we then ask the provider for the result. Providers that require it, such as connectIPS, must have this exact address registered."
                        >
                            <div className="flex items-start gap-2">
                                <code className="block min-w-0 flex-1 rounded-md bg-muted px-3 py-2 text-sm break-all">
                                    {return_url}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Copy return address"
                                    onClick={() => copy(return_url)}
                                >
                                    {copied === return_url ? <Check /> : <Copy />}
                                </Button>
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
