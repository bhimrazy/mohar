import { Link, useForm } from '@inertiajs/react';
import { Puzzle, SlidersHorizontal } from 'lucide-react';
import type { FormEvent } from 'react';
import AddonController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/AddonController';
import ConfigOptionGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionGroupController';
import ProductSettingsController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductSettingsController';
import { EmptyState } from '@/components/app/empty-state';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { CheckboxList } from '@/components/catalog/checkbox-list';
import { SwitchRow } from '@/components/catalog/switch-row';
import type { ProductChoice } from '@/components/catalog/types';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type SaveButtonProps = { processing: boolean; label: string };

function SaveButton({ processing, label }: SaveButtonProps) {
    return (
        <Button type="submit" disabled={processing}>
            {processing && <Spinner />}
            {label}
        </Button>
    );
}

type OptionGroup = {
    id: number;
    name: string;
    description: string | null;
    options_count: number;
};

export function ConfigOptionsTab({
    productId,
    groups,
    assigned,
    canManage,
}: {
    productId: number;
    groups: OptionGroup[];
    assigned: number[];
    canManage: boolean;
}) {
    const form = useForm({ group_ids: assigned });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProductSettingsController.configOptions(productId), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Configurable options"
                description="Option groups offered when this product is ordered (e.g. RAM, CPU cores). Every option value needs a price for each cycle the product sells."
                footer={
                    canManage &&
                    groups.length > 0 && (
                        <SaveButton
                            processing={form.processing}
                            label="Save options"
                        />
                    )
                }
            >
                <CheckboxList
                    label="Option groups"
                    idPrefix="option-group"
                    disabled={!canManage}
                    selected={form.data.group_ids}
                    onChange={(ids) => form.setData('group_ids', ids)}
                    items={groups.map((group) => ({
                        id: group.id,
                        label: group.name,
                        hint: `${group.options_count} ${group.options_count === 1 ? 'option' : 'options'}`,
                    }))}
                    empty={
                        <EmptyState
                            icon={SlidersHorizontal}
                            title="No configurable option groups yet"
                            description="Create an option group, then assign it here."
                            action={
                                <Button size="sm" variant="outline" asChild>
                                    <Link
                                        href={ConfigOptionGroupController.create()}
                                    >
                                        New option group
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
                <InputError className="mt-3" message={errors.group_ids} />
            </SectionCard>
        </form>
    );
}

type AddonChoice = {
    id: number;
    name: string;
    status: string;
    payment_type: string;
    show_on_order: boolean;
};

export function AddonsTab({
    productId,
    addons,
    assigned,
    canManage,
}: {
    productId: number;
    addons: AddonChoice[];
    assigned: number[];
    canManage: boolean;
}) {
    const form = useForm({ addon_ids: assigned });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProductSettingsController.addons(productId), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Addons"
                description="Addons clients can order with this product or add to an existing service."
                footer={
                    canManage &&
                    addons.length > 0 && (
                        <SaveButton
                            processing={form.processing}
                            label="Save addons"
                        />
                    )
                }
            >
                <CheckboxList
                    label="Addons"
                    idPrefix="addon"
                    disabled={!canManage}
                    selected={form.data.addon_ids}
                    onChange={(ids) => form.setData('addon_ids', ids)}
                    items={addons.map((addon) => ({
                        id: addon.id,
                        label: addon.name,
                        hint: addon.show_on_order
                            ? undefined
                            : 'Existing services only',
                        meta:
                            addon.status !== 'active' ? (
                                <StatusBadge status={addon.status} />
                            ) : undefined,
                    }))}
                    empty={
                        <EmptyState
                            icon={Puzzle}
                            title="No addons yet"
                            description="Create an addon such as daily backups, then make it available here."
                            action={
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={AddonController.create()}>
                                        New addon
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />
            </SectionCard>
        </form>
    );
}

export function UpgradesTab({
    productId,
    candidates,
    targets,
    allowConfigOptionUpgrades,
    canManage,
}: {
    productId: number;
    candidates: ProductChoice[];
    targets: number[];
    allowConfigOptionUpgrades: boolean;
    canManage: boolean;
}) {
    const form = useForm({
        target_ids: targets,
        allow_config_option_upgrades: allowConfigOptionUpgrades,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ProductSettingsController.upgrades(productId), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Upgrades and downgrades"
                description="Products a service of this product can move to. A path to a cheaper product is a downgrade. Retired targets are never offered."
                footer={
                    canManage && (
                        <SaveButton
                            processing={form.processing}
                            label="Save upgrade paths"
                        />
                    )
                }
            >
                <div className="grid gap-6">
                    <SwitchRow
                        id="allow_config_option_upgrades"
                        title="Allow configurable option changes"
                        description="Clients can change their configurable options without changing product."
                        checked={form.data.allow_config_option_upgrades}
                        disabled={!canManage}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'allow_config_option_upgrades',
                                checked,
                            )
                        }
                    />
                    <CheckboxList
                        label="Target products"
                        idPrefix="upgrade-target"
                        disabled={!canManage}
                        selected={form.data.target_ids}
                        onChange={(ids) => form.setData('target_ids', ids)}
                        items={candidates.map((product) => ({
                            id: product.id,
                            label: product.name,
                            hint: product.group,
                            meta:
                                product.status &&
                                product.status !== 'active' ? (
                                    <StatusBadge status={product.status} />
                                ) : undefined,
                        }))}
                        empty={
                            <p className="text-sm text-muted-foreground">
                                There are no other products yet.
                            </p>
                        }
                    />
                    <InputError
                        message={errors.target_ids ?? errors['target_ids.0']}
                    />
                </div>
            </SectionCard>
        </form>
    );
}
