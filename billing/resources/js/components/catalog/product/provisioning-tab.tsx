import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import {
    KeyValueEditor,
    pairsFromObject,
} from '@/components/catalog/key-value-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type ProvisioningTabProps = {
    productId: number;
    driver: string;
    serverGroupId: number | null;
    driverConfig: Record<string, unknown> | unknown[];
    suggestions: string[];
    canManage: boolean;
};

/** Driver binding. Catalog only stores it; the Services module interprets it at provisioning time. */
export function ProvisioningTab({
    productId,
    driver,
    serverGroupId,
    driverConfig,
    suggestions,
    canManage,
}: ProvisioningTabProps) {
    const form = useForm({
        provisioning_driver: driver,
        server_group_id: serverGroupId === null ? '' : String(serverGroupId),
        driver_config: pairsFromObject(driverConfig),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProductSettingsController.provisioning(productId), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <SectionCard
                title="Provisioning"
                description="Which module creates, suspends and terminates accounts for this product. Use “none” for products handled manually."
            >
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    <Field
                        htmlFor="provisioning_driver"
                        label="Driver key"
                        hint="e.g. none, cpanel, proxmox"
                        error={errors.provisioning_driver}
                    >
                        <Input
                            id="provisioning_driver"
                            list="provisioning-driver-suggestions"
                            value={form.data.provisioning_driver}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData(
                                    'provisioning_driver',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!errors.provisioning_driver}
                            aria-describedby="provisioning_driver-description"
                            className="font-mono"
                        />
                        <datalist id="provisioning-driver-suggestions">
                            {suggestions.map((suggestion) => (
                                <option key={suggestion} value={suggestion} />
                            ))}
                        </datalist>
                    </Field>
                    <Field
                        htmlFor="server_group_id"
                        label="Server group ID"
                        optional
                        hint="Empty: the driver's default server is used."
                        error={errors.server_group_id}
                    >
                        <Input
                            id="server_group_id"
                            type="number"
                            min={1}
                            value={form.data.server_group_id}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData(
                                    'server_group_id',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!errors.server_group_id}
                            aria-describedby="server_group_id-description"
                        />
                    </Field>
                </div>
            </SectionCard>

            <SectionCard
                title="Driver settings"
                description="Passed to the driver as-is, e.g. package = starter, disk_quota = 10240."
                footer={
                    canManage && (
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save provisioning
                        </Button>
                    )
                }
            >
                <KeyValueEditor
                    value={form.data.driver_config}
                    onChange={(pairs) => form.setData('driver_config', pairs)}
                    errors={errors}
                    disabled={!canManage}
                />
            </SectionCard>
        </form>
    );
}
