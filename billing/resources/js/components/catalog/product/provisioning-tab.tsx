import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { DriverSettings } from '@/components/catalog/driver-settings';
import type { ProvisioningDriver } from '@/components/catalog/driver-settings';
import { pairsFromObject } from '@/components/catalog/key-value-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type ProvisioningTabProps = {
    productId: number;
    driver: string;
    serverGroupId: number | null;
    driverConfig: Record<string, unknown> | unknown[];
    secretKeys: string[];
    drivers: ProvisioningDriver[];
    canManage: boolean;
};

/** Driver binding. Catalog only stores it; the Services module runs the driver at provisioning time. */
export function ProvisioningTab({
    productId,
    driver,
    serverGroupId,
    driverConfig,
    secretKeys,
    drivers,
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
        <form onSubmit={submit}>
            <SectionCard
                title="Provisioning"
                description="The driver that creates, suspends and terminates accounts for this product, and the settings it receives."
                footer={
                    canManage && (
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save provisioning
                        </Button>
                    )
                }
            >
                <div className="grid gap-6">
                    <DriverSettings
                        idPrefix="product"
                        drivers={drivers}
                        driver={form.data.provisioning_driver}
                        onDriverChange={(value) =>
                            form.setData('provisioning_driver', value)
                        }
                        config={form.data.driver_config}
                        onConfigChange={(pairs) =>
                            form.setData('driver_config', pairs)
                        }
                        errors={errors}
                        disabled={!canManage}
                        secretKeys={secretKeys}
                    />
                    <Field
                        htmlFor="server_group_id"
                        label="Server group ID"
                        optional
                        hint="Empty: the driver's default server is used."
                        error={errors.server_group_id}
                        className="sm:max-w-xs"
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
        </form>
    );
}
