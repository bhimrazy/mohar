import { useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { PlugZap, Server } from 'lucide-react';
import type { FormEvent } from 'react';
import RegistrarController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/RegistrarController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Badge } from '@/components/ui/badge';
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
import { capabilityLabel } from '@/components/domains/capabilities';
import type { SettingFieldData } from '@/components/app/setting-field';

type Registrar = {
    key: string;
    name: string;
    capabilities: string[];
    configured: boolean;
    is_active: boolean;
    test_mode: boolean;
    schema: SettingFieldData[];
    settings: Record<string, string | number | boolean | null>;
    secrets_set: Record<string, boolean>;
    domains_count: number;
    tlds_count: number;
};

function RegistrarCard({ registrar }: { registrar: Registrar }) {
    const form = useForm({
        is_active: registrar.is_active,
        test_mode: registrar.test_mode,
        settings: registrar.settings,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setSetting = (key: string, value: string | boolean) =>
        form.setData('settings', { ...form.data.settings, [key]: value });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(RegistrarController.update(registrar.key), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        {registrar.name}
                        <StatusBadge
                            status={registrar.is_active ? 'active' : 'inactive'}
                            label={registrar.is_active ? 'Enabled' : 'Disabled'}
                        />
                        {registrar.test_mode && (
                            <Badge variant="outline">Test mode</Badge>
                        )}
                    </span>
                }
                description={`${registrar.tlds_count} TLDs · ${registrar.domains_count} domains`}
                actions={
                    registrar.capabilities.includes('test_connection') ? (
                        <ConfirmDialog
                            trigger={
                                <Button type="button" variant="ghost" size="sm">
                                    <PlugZap />
                                    Test connection
                                </Button>
                            }
                            title={`Test the connection to ${registrar.name}?`}
                            description="Uses the saved settings. Save first if you changed anything."
                            confirmLabel="Test connection"
                            destructive={false}
                            action={RegistrarController.test(registrar.key)}
                        />
                    ) : (
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled
                                aria-describedby={`${registrar.key}-no-test`}
                            >
                                <PlugZap />
                                Test connection
                            </Button>
                            <span
                                id={`${registrar.key}-no-test`}
                                className="text-xs text-muted-foreground"
                            >
                                This registrar has no connection test.
                            </span>
                        </span>
                    )
                }
                footer={
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Save settings
                    </Button>
                }
            >
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    {registrar.schema.map((field) => {
                        const id = `${registrar.key}-${field.key}`;
                        const error = errors[`settings.${field.key}`];
                        const value = form.data.settings[field.key];

                        return (
                            <Field
                                key={field.key}
                                htmlFor={id}
                                label={field.label}
                                error={error}
                                hint={
                                    field.secret &&
                                    registrar.secrets_set[field.key]
                                        ? 'Saved. Leave empty to keep it.'
                                        : (field.help ?? undefined)
                                }
                            >
                                {field.type === 'select' ? (
                                    <Select
                                        value={String(value ?? '')}
                                        onValueChange={(next) =>
                                            setSetting(field.key, next)
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full"
                                            id={id}
                                            aria-describedby={`${id}-description`}
                                        >
                                            <SelectValue placeholder="Choose…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                field.options ?? {},
                                            ).map(([option, label]) => (
                                                <SelectItem
                                                    key={option}
                                                    value={option}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : field.type === 'boolean' ? (
                                    <Switch
                                        id={id}
                                        checked={value === true}
                                        onCheckedChange={(checked) =>
                                            setSetting(field.key, checked)
                                        }
                                    />
                                ) : (
                                    <Input
                                        id={id}
                                        type={
                                            field.secret
                                                ? 'password'
                                                : field.type === 'number'
                                                  ? 'number'
                                                  : 'text'
                                        }
                                        autoComplete="off"
                                        value={String(value ?? '')}
                                        onChange={(event) =>
                                            setSetting(
                                                field.key,
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!error}
                                        aria-describedby={`${id}-description`}
                                    />
                                )}
                            </Field>
                        );
                    })}
                    <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm font-medium">
                        Enabled
                        <Switch
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                    </label>
                    <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm font-medium">
                        Test mode (registrar sandbox)
                        <Switch
                            checked={form.data.test_mode}
                            onCheckedChange={(checked) =>
                                form.setData('test_mode', checked)
                            }
                        />
                    </label>
                </div>
                <div className="mt-4 flex flex-wrap gap-1.5">
                    {registrar.capabilities.map((capability) => (
                        <Badge
                            key={capability}
                            variant="secondary"
                            className="text-xs"
                        >
                            {capabilityLabel(capability)}
                        </Badge>
                    ))}
                </div>
            </SectionCard>
        </form>
    );
}

export default function RegistrarsIndex({
    registrars,
}: {
    registrars: Registrar[];
}) {
    return (
        <>
            <Head title="Registrars" />
            <Page size="narrow">
                <PageHeader
                    title="Registrars"
                    description="Connect the registrars that register, renew and sync your domains. Credentials are stored encrypted."
                />
                {registrars.length === 0 ? (
                    <EmptyState
                        variant="bordered"
                        icon={Server}
                        title="No registrar drivers installed"
                        description="Registrar modules add their drivers here."
                    />
                ) : (
                    registrars.map((registrar) => (
                        <RegistrarCard
                            key={registrar.key}
                            registrar={registrar}
                        />
                    ))
                )}
            </Page>
        </>
    );
}
