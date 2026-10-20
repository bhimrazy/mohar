import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SettingFieldInput } from '@/components/app/setting-field';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { SwitchField } from '@/components/app/switch-field';
import {
    ConnectionCheck,
    CopyableCode,
} from '@/components/services/connection-check';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { fromMinor, toMinor } from '@/lib/money';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import type { SelectOption } from '@/types/app';
import type { ServerRow } from '@/types/services';

type Props = {
    server?: ServerRow;
    drivers: SelectOption[];
    /** Each driver's server settings beyond hostname and credentials. */
    driverFields: Record<string, SettingFieldData[]>;
    /**
     * The shared connection fields each driver reads, with the driver settings each depends on (setting => value);
     * null: all of them.
     */
    driverConnectionFields: Record<
        string,
        Record<string, Record<string, string>> | null
    >;
    currencies: string[];
};

/** Non-secret defaults of a driver's server settings, so the values the form shows are the values it submits. */
/** The server setting that holds a trusted certificate; set only by "Trust this certificate", removed with Remove. */
const PIN_SETTING = 'spki_pin';

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
    driverConnectionFields,
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

    /** Whether the selected driver reads a shared connection field, given its current settings. */
    const shows = (key: string): boolean => {
        const fields = driverConnectionFields[data.driver];

        if (!fields) {
            return true;
        }

        const conditions = fields[key];

        return (
            conditions !== undefined &&
            Object.entries(conditions).every(
                ([setting, value]) => (data.settings[setting] ?? '') === value,
            )
        );
    };
    const errorsByKey = errors as Record<string, string>;
    /** Driver settings the connection fields depend on ("Sign in with"): shown in the Connection section. */
    const connectionSettingKeys = new Set(
        Object.values(driverConnectionFields[data.driver] ?? {}).flatMap(
            (conditions) => Object.keys(conditions),
        ),
    );
    /** Stored credentials the selected driver and settings no longer read; saving removes them. */
    const unusedStored = (
        [
            ['password', 'password', server?.has_password],
            ['api_token', 'API token', server?.has_api_token],
            ['access_hash', 'access hash', server?.has_access_hash],
        ] as const
    )
        .filter(
            ([key, , stored]) =>
                stored &&
                server?.driver === data.driver &&
                driverConnectionFields[data.driver] &&
                !shows(key),
        )
        .map(([, label]) => label);
    // Test connection and Trust redirect here with page errors (not this form's); the checklist, when flashed, already
    // shows a failed test.
    const page = usePage();
    const pageErrors = page.props.errors as Record<string, string>;
    const flashedCheck = Boolean(
        (page.flash as { connectionCheck?: unknown }).connectionCheck,
    );
    const pageError =
        pageErrors.fingerprint ??
        (flashedCheck ? undefined : pageErrors.server);
    const storedPin =
        server?.driver === data.driver
            ? server.settings.values[PIN_SETTING]
            : undefined;

    /** Stored credentials belong to the address they were saved for: a new destination needs them entered again. */
    const retargeted =
        server !== undefined &&
        server !== null &&
        server.driver === data.driver &&
        (data.hostname.trim().toLowerCase() !== server.hostname.toLowerCase() ||
            data.ip_address.trim() !== (server.ip_address ?? '') ||
            data.port.trim() !== (server.port?.toString() ?? '') ||
            data.secure !== server.secure);
    const storedCredentials = (
        [
            ['password', server?.has_password],
            ['API token', server?.has_api_token],
            ['access hash', server?.has_access_hash],
        ] as const
    )
        .filter(([, stored]) => stored)
        .map(([label]) => label);

    const secretHint = (has: boolean | undefined) =>
        has
            ? retargeted
                ? 'The address changed: enter it again.'
                : 'Stored encrypted. Leave blank to keep it.'
            : undefined;

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

                {pageError && (
                    <Alert variant="destructive">
                        <AlertDescription>{pageError}</AlertDescription>
                    </Alert>
                )}

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
                        {retargeted && (
                            <Alert className="mb-6 border-warning/40 bg-warning-subtle">
                                <AlertDescription className="text-warning-text">
                                    You changed where this server connects.
                                    Saving removes the stored{' '}
                                    {storedCredentials.length > 0
                                        ? `${storedCredentials.join(' and ')} and `
                                        : ''}
                                    trusted certificate, because they are never
                                    sent to a new address. Enter the credentials
                                    again to save. The change is recorded in the
                                    activity log.
                                </AlertDescription>
                            </Alert>
                        )}
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
                            {shows('port') && (
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
                            )}
                            {(driverFields[data.driver] ?? [])
                                .filter((field) =>
                                    connectionSettingKeys.has(field.key),
                                )
                                .map((field) => (
                                    <SettingFieldInput
                                        key={field.key}
                                        id={`settings-${field.key}`}
                                        field={field}
                                        value={data.settings[field.key] ?? ''}
                                        onChange={(value) =>
                                            setData('settings', {
                                                ...data.settings,
                                                [field.key]: value,
                                            })
                                        }
                                        error={
                                            errorsByKey[`settings.${field.key}`]
                                        }
                                    />
                                ))}
                            {shows('username') && (
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
                            )}
                            {shows('password') && (
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
                            )}
                            {shows('api_token') && (
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
                            )}
                            {shows('access_hash') && (
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
                            )}
                            {unusedStored.length > 0 && (
                                <p className="text-sm text-warning-text sm:col-span-2">
                                    Saving removes the stored{' '}
                                    {unusedStored.join(' and ')}, which this
                                    sign-in method does not use. The removal is
                                    recorded in the activity log.
                                </p>
                            )}
                            {shows('secure') && (
                                <SwitchField
                                    id="secure"
                                    className="sm:col-span-2"
                                    label="Use TLS"
                                    description="Connect over HTTPS / TLS."
                                    checked={data.secure}
                                    onCheckedChange={(checked) =>
                                        setData('secure', checked)
                                    }
                                />
                            )}
                            <SwitchField
                                id="is_active"
                                className="sm:col-span-2"
                                label="Active"
                                description="Inactive servers are never picked for new accounts."
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked)
                                }
                            />
                            <SwitchField
                                id="is_default"
                                className="sm:col-span-2"
                                label="Default for this driver"
                                description="Used by products without a server group."
                                checked={data.is_default}
                                onCheckedChange={(checked) =>
                                    setData('is_default', checked)
                                }
                            />
                        </div>
                    </SectionCard>

                    {(driverFields[data.driver] ?? []).length > 0 && (
                        <SectionCard
                            title="Driver settings"
                            description="Settings this driver needs for the server. Secret values are encrypted and never shown again."
                        >
                            <div className="grid items-start gap-6 sm:grid-cols-2">
                                {(driverFields[data.driver] ?? [])
                                    .filter(
                                        (field) =>
                                            !connectionSettingKeys.has(
                                                field.key,
                                            ),
                                    )
                                    .map((field) => {
                                        const setValue = (value: string) =>
                                            setData('settings', {
                                                ...data.settings,
                                                [field.key]: value,
                                            });

                                        if (field.key === PIN_SETTING) {
                                            return (
                                                <TrustedCertificate
                                                    key={field.key}
                                                    serverId={server?.id}
                                                    label={field.label}
                                                    pin={storedPin}
                                                />
                                            );
                                        }

                                        if (field.type === 'boolean') {
                                            return (
                                                <SwitchField
                                                    key={field.key}
                                                    id={`settings-${field.key}`}
                                                    className="sm:col-span-2"
                                                    label={field.label}
                                                    description={
                                                        field.help ?? undefined
                                                    }
                                                    checked={
                                                        (data.settings[
                                                            field.key
                                                        ] ?? '') === '1'
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setValue(
                                                            checked ? '1' : '0',
                                                        )
                                                    }
                                                />
                                            );
                                        }

                                        return (
                                            <SettingFieldInput
                                                key={field.key}
                                                id={`settings-${field.key}`}
                                                field={field}
                                                value={
                                                    data.settings[field.key] ??
                                                    ''
                                                }
                                                onChange={setValue}
                                                secretStored={
                                                    server?.driver ===
                                                        data.driver &&
                                                    server.settings.secrets_set[
                                                        field.key
                                                    ]
                                                }
                                                error={
                                                    errorsByKey[
                                                        `settings.${field.key}`
                                                    ]
                                                }
                                            />
                                        );
                                    })}
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

/** A trusted certificate is never typed: it is shown read-only, set by "Trust this certificate" and removed here. */
function TrustedCertificate({
    serverId,
    label,
    pin,
}: {
    serverId?: number;
    label: string;
    pin?: string;
}) {
    return (
        <div className="flex flex-col gap-2 sm:col-span-2">
            <span className="text-sm font-medium">{label}</span>
            {pin && serverId ? (
                <>
                    <CopyableCode value={pin} label="trusted fingerprint" />
                    <p className="text-sm text-muted-foreground">
                        Calls accept only this key instead of checking the
                        certificate authority and hostname.
                    </p>
                    <ConfirmDialog
                        trigger={
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="self-start"
                            >
                                Remove
                            </Button>
                        }
                        title="Remove the trusted certificate?"
                        description="Calls go back to checking the server's certificate against its authority and hostname. A self-signed server then fails until you trust its certificate again. The change is recorded in the activity log."
                        confirmLabel="Remove trusted certificate"
                        action={ServerController.forgetCertificate(serverId)}
                    />
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    None. The certificate is checked against its authority and
                    the hostname. A self-signed server can be trusted from Test
                    connection.
                </p>
            )}
        </div>
    );
}
