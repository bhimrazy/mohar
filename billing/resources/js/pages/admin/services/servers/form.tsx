import { Link, router, useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SettingFieldInput } from '@/components/app/setting-field';
import { ConnectionCheck } from '@/components/services/connection-check';
import type { SettingFieldData } from '@/components/app/setting-field';
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
import { Switch } from '@/components/ui/switch';
import { fromMinor, toMinor } from '@/lib/money';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import type { SelectOption } from '@/types/app';
import type { ServerRow } from '@/types/services';

type Props = {
    server?: ServerRow;
    drivers: SelectOption[];
    /** Each driver's server settings beyond hostname and credentials. */
    driverFields: Record<string, SettingFieldData[]>;
    currencies: string[];
};

/** Non-secret defaults of a driver's server settings, so the values the form shows are the values it submits. */
const defaultsFor = (fields: SettingFieldData[] = []): Record<string, string> =>
    Object.fromEntries(
        fields
            .filter((field) => !field.secret && field.default !== null)
            .map((field) => [
                field.key,
                typeof field.default === 'boolean'
                    ? field.default
                        ? '1'
                        : '0'
                    : String(field.default),
            ]),
    );

export default function ServerForm({
    server,
    drivers,
    driverFields,
    currencies,
}: Props) {
    const form = useForm({
        name: server?.name ?? '',
        hostname: server?.hostname ?? '',
        ip_address: server?.ip_address ?? '',
        assigned_ips_text: server?.assigned_ips.join('\n') ?? '',
        driver: server?.driver ?? drivers[0]?.value ?? 'none',
        username: server?.username ?? '',
        password: '',
        api_token: '',
        access_hash: '',
        secure: server?.secure ?? true,
        port: server?.port?.toString() ?? '',
        max_accounts: server?.max_accounts ?? 0,
        is_active: server?.is_active ?? true,
        is_default: server?.is_default ?? false,
        ns1: server?.nameservers[0] ?? '',
        ns2: server?.nameservers[1] ?? '',
        ns3: server?.nameservers[2] ?? '',
        monthly_cost: fromMinor(server?.monthly_cost ?? 0).toFixed(2),
        currency: server?.currency ?? currencies[0] ?? 'USD',
        datacenter: server?.datacenter ?? '',
        status_url: server?.status_url ?? '',
        settings: server
            ? server.settings.values
            : defaultsFor(driverFields[drivers[0]?.value ?? 'none']),
    });
    const { data, setData, errors, processing } = form;
    const errorFor = (prefix: string) =>
        Object.entries(errors as Record<string, string>).find(([key]) =>
            key.startsWith(prefix),
        )?.[1];
    const nameserverError = errorFor('nameservers');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            port: values.port === '' ? null : Number(values.port),
            monthly_cost: toMinor(values.monthly_cost),
            nameservers: [values.ns1, values.ns2, values.ns3].filter(Boolean),
        }));
        form.submit(
            server
                ? ServerController.update(server.id)
                : ServerController.store(),
        );
    };

    const text = (name: Exclude<keyof typeof data, 'settings'>) => ({
        id: name,
        value: String(data[name] ?? ''),
        onChange: (event: { target: { value: string } }) =>
            setData(name, event.target.value as never),
        'aria-invalid': !!errors[name as keyof typeof errors],
        'aria-describedby': `${name}-description`,
    });

    const secretHint = (has: boolean | undefined) =>
        has ? 'Stored encrypted. Leave blank to keep it.' : undefined;

    return (
        <>
            <Head title={server ? `Edit ${server.name}` : 'New server'} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: ServerController.index(), label: 'Servers' }}
                    title={server ? `Edit ${server.name}` : 'New server'}
                    actions={
                        server && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        ServerController.test(server.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Test connection
                            </Button>
                        )
                    }
                />

                {server && (
                    <ConnectionCheck
                        serverId={server.id}
                        serverName={server.name}
                    />
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Server">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input {...text('name')} />
                            </Field>
                            <Field
                                htmlFor="hostname"
                                label="Hostname"
                                hint="e.g. web1.example.net"
                                error={errors.hostname}
                            >
                                <Input {...text('hostname')} />
                            </Field>
                            <Field
                                htmlFor="ip_address"
                                label="IP address"
                                optional
                                error={errors.ip_address}
                            >
                                <Input {...text('ip_address')} />
                            </Field>
                            <Field
                                htmlFor="max_accounts"
                                label="Maximum accounts"
                                hint="0 = unlimited."
                                error={errors.max_accounts}
                            >
                                <Input
                                    {...text('max_accounts')}
                                    type="number"
                                    min={0}
                                />
                            </Field>
                            <Field
                                htmlFor="datacenter"
                                label="Datacenter"
                                optional
                                error={errors.datacenter}
                            >
                                <Input {...text('datacenter')} />
                            </Field>
                            <Field
                                htmlFor="status_url"
                                label="Status page URL"
                                optional
                                error={errors.status_url}
                            >
                                <Input {...text('status_url')} type="url" />
                            </Field>
                            <Field
                                htmlFor="assigned_ips_text"
                                label="Assignable IPs"
                                optional
                                hint="One per line, for dedicated IP products."
                                className="sm:col-span-2"
                                error={errorFor('assigned_ips')}
                            >
                                <textarea
                                    {...text('assigned_ips_text')}
                                    rows={3}
                                    className="w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Connection"
                        description="Credentials are encrypted and never shown again."
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="driver"
                                label="Driver"
                                error={errors.driver}
                            >
                                <Select
                                    value={data.driver}
                                    onValueChange={(value) =>
                                        setData((current) => ({
                                            ...current,
                                            driver: value,
                                            settings:
                                                value === server?.driver
                                                    ? server.settings.values
                                                    : defaultsFor(
                                                          driverFields[value],
                                                      ),
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id="driver"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {drivers.map((driver) => (
                                            <SelectItem
                                                key={driver.value}
                                                value={driver.value}
                                            >
                                                {driver.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                htmlFor="port"
                                label="Port"
                                optional
                                hint="Blank uses the driver default."
                                error={errors.port}
                            >
                                <Input
                                    {...text('port')}
                                    type="number"
                                    min={1}
                                    max={65535}
                                />
                            </Field>
                            <Field
                                htmlFor="username"
                                label="Username"
                                optional
                                error={errors.username}
                            >
                                <Input
                                    {...text('username')}
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                htmlFor="password"
                                label="Password"
                                optional
                                hint={secretHint(server?.has_password)}
                                error={errors.password}
                            >
                                <Input
                                    {...text('password')}
                                    type="password"
                                    autoComplete="new-password"
                                />
                            </Field>
                            <Field
                                htmlFor="api_token"
                                label="API token"
                                optional
                                hint={secretHint(server?.has_api_token)}
                                error={errors.api_token}
                            >
                                <Input
                                    {...text('api_token')}
                                    type="password"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                htmlFor="access_hash"
                                label="Access hash"
                                optional
                                hint={secretHint(server?.has_access_hash)}
                                error={errors.access_hash}
                            >
                                <Input
                                    {...text('access_hash')}
                                    type="password"
                                    autoComplete="off"
                                />
                            </Field>
                            {(
                                [
                                    [
                                        'secure',
                                        'Use TLS',
                                        'Connect over HTTPS / TLS.',
                                    ],
                                    [
                                        'is_active',
                                        'Active',
                                        'Inactive servers are never picked for new accounts.',
                                    ],
                                    [
                                        'is_default',
                                        'Default for this driver',
                                        'Used by products without a server group.',
                                    ],
                                ] as const
                            ).map(([key, title, description]) => (
                                <div
                                    key={key}
                                    className="flex items-center justify-between gap-4 rounded-lg border p-3 sm:col-span-2"
                                >
                                    <div className="space-y-0.5">
                                        <label
                                            htmlFor={key}
                                            className="text-sm font-medium"
                                        >
                                            {title}
                                        </label>
                                        <p className="text-sm text-muted-foreground">
                                            {description}
                                        </p>
                                    </div>
                                    <Switch
                                        id={key}
                                        checked={data[key]}
                                        onCheckedChange={(checked) =>
                                            setData(key, checked)
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </SectionCard>

                    {(driverFields[data.driver] ?? []).length > 0 && (
                        <SectionCard
                            title="Driver settings"
                            description="Settings this driver needs for the server. Secret values are encrypted and never shown again."
                        >
                            <div className="grid items-start gap-6 sm:grid-cols-2">
                                {(driverFields[data.driver] ?? []).map(
                                    (field) => (
                                        <SettingFieldInput
                                            key={field.key}
                                            id={`settings-${field.key}`}
                                            field={field}
                                            value={
                                                data.settings[field.key] ?? ''
                                            }
                                            onChange={(value) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    [field.key]: value,
                                                })
                                            }
                                            secretStored={
                                                server?.driver ===
                                                    data.driver &&
                                                server.settings.secrets_set[
                                                    field.key
                                                ]
                                            }
                                            error={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`settings.${field.key}`]
                                            }
                                        />
                                    ),
                                )}
                            </div>
                        </SectionCard>
                    )}

                    <SectionCard title="Nameservers and cost">
                        <div className="grid gap-6 sm:grid-cols-2">
                            {(['ns1', 'ns2', 'ns3'] as const).map(
                                (key, index) => (
                                    <Field
                                        key={key}
                                        htmlFor={key}
                                        label={`Nameserver ${index + 1}`}
                                        optional={index > 0}
                                        error={
                                            index === 0
                                                ? nameserverError
                                                : undefined
                                        }
                                    >
                                        <Input
                                            {...text(key)}
                                            placeholder={`ns${index + 1}.example.net`}
                                        />
                                    </Field>
                                ),
                            )}
                            <Field
                                htmlFor="monthly_cost"
                                label="Monthly cost"
                                error={errors.monthly_cost}
                            >
                                <div className="flex gap-2">
                                    <Input
                                        {...text('monthly_cost')}
                                        inputMode="decimal"
                                    />
                                    <Select
                                        value={data.currency}
                                        onValueChange={(value) =>
                                            setData('currency', value)
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-28"
                                            aria-label="Currency"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.map((currency) => (
                                                <SelectItem
                                                    key={currency}
                                                    value={currency}
                                                >
                                                    {currency}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </Field>
                        </div>
                    </SectionCard>

                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={ServerController.index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {server ? 'Save changes' : 'Create server'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
