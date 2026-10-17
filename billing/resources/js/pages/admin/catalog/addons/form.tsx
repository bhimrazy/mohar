import { Head, Link, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import AddonController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/AddonController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { CheckboxList } from '@/components/catalog/checkbox-list';
import {
    KeyValueEditor,
    pairsFromObject,
} from '@/components/catalog/key-value-editor';
import {
    matrixErrors,
    matrixFromRows,
    PricingMatrix,
    rowsFromMatrix,
} from '@/components/catalog/pricing-matrix';
import { SelectInput } from '@/components/catalog/select-input';
import { SwitchRow } from '@/components/catalog/switch-row';
import type {
    CurrencyOption,
    CycleOption,
    EnumOption,
    PriceRow,
    ProductChoice,
} from '@/components/catalog/types';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type Addon = {
    id: number;
    name: string;
    description: string | null;
    kind: string;
    payment_type: string;
    auto_setup: string;
    status: string;
    is_taxable: boolean;
    show_on_order: boolean;
    is_hidden: boolean;
    is_retired: boolean;
    suspend_parent_on_overdue: boolean;
    welcome_email_key: string | null;
    weight: number;
    allow_quantity: boolean;
    prorata_with_parent: boolean;
    provisioning_driver: string;
    driver_config: Record<string, unknown> | unknown[];
    product_ids: number[];
    prices: PriceRow[];
};

type Props = {
    addon: Addon | null;
    kinds: EnumOption[];
    paymentTypes: EnumOption[];
    autoSetups: EnumOption[];
    cyclesByPaymentType: Record<string, CycleOption[]>;
    currencies: CurrencyOption[];
    products: ProductChoice[];
    canManage: boolean;
};

export default function AddonForm({
    addon,
    kinds,
    paymentTypes,
    autoSetups,
    cyclesByPaymentType,
    currencies,
    products,
    canManage,
}: Props) {
    const allCycles = useMemo(
        () => Object.values(cyclesByPaymentType).flat(),
        [cyclesByPaymentType],
    );
    const initialMatrix = useMemo(
        () => matrixFromRows(addon?.prices ?? [], currencies, allCycles),
        [addon, currencies, allCycles],
    );
    const [matrix, setMatrix] = useState(initialMatrix);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const form = useForm({
        name: addon?.name ?? '',
        description: addon?.description ?? '',
        kind: addon?.kind ?? 'feature',
        payment_type: addon?.payment_type ?? 'recurring',
        auto_setup: addon?.auto_setup ?? 'on_payment',
        is_taxable: addon?.is_taxable ?? true,
        show_on_order: addon?.show_on_order ?? true,
        is_hidden: addon?.is_hidden ?? false,
        is_retired: addon?.is_retired ?? false,
        suspend_parent_on_overdue: addon?.suspend_parent_on_overdue ?? false,
        allow_quantity: addon?.allow_quantity ?? false,
        prorata_with_parent: addon?.prorata_with_parent ?? false,
        welcome_email_key: addon?.welcome_email_key ?? '',
        weight: String(addon?.weight ?? 0),
        provisioning_driver: addon?.provisioning_driver ?? 'none',
        driver_config: pairsFromObject(addon?.driver_config),
        product_ids: addon?.product_ids ?? [],
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const cycles = cyclesByPaymentType[data.payment_type] ?? [];
    const cycleValues = cycles.map((cycle) => cycle.value);
    const pricedRows = (value: typeof matrix) =>
        rowsFromMatrix(value).filter((row) => cycleValues.includes(row.cycle));
    const [submitted, setSubmitted] = useState(pricedRows(initialMatrix));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const prices = pricedRows(matrix);
        setSubmitted(prices);
        form.transform((current) => ({ ...current, prices }));
        form.submit(
            addon ? AddonController.update(addon) : AddonController.store(),
            { preserveScroll: true },
        );
    };

    const toggle = (
        name: keyof typeof data,
        title: string,
        description: string,
    ) => (
        <SwitchRow
            id={name}
            title={title}
            description={description}
            checked={data[name] as boolean}
            disabled={!canManage}
            onCheckedChange={(checked) => setData(name, checked as never)}
        />
    );

    const title = addon ? addon.name : 'New addon';

    return (
        <>
            <Head title={title} />
            <Page>
                <PageHeader
                    back={{ href: AddonController.index(), label: 'Addons' }}
                    title={title}
                    status={addon?.status}
                    actions={
                        addon &&
                        canManage && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setConfirmDelete(true)}
                                    >
                                        Delete addon
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    }
                />
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="grid gap-6 lg:grid-cols-3">
                        <div className="flex flex-col gap-6 lg:col-span-2">
                            <SectionCard title="Addon">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="name"
                                        label="Name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="name"
                                            value={data.name}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={!!errors.name}
                                            aria-describedby="name-description"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="kind"
                                        label="Kind"
                                        error={errors.kind}
                                    >
                                        <SelectInput
                                            id="kind"
                                            value={data.kind}
                                            onChange={(value) =>
                                                setData('kind', value)
                                            }
                                            options={kinds}
                                            disabled={!canManage}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="description"
                                        label="Description"
                                        optional
                                        error={errors.description}
                                        className="sm:col-span-2"
                                    >
                                        <Textarea
                                            id="description"
                                            rows={3}
                                            value={data.description}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            aria-describedby="description-description"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="payment_type"
                                        label="Payment type"
                                        error={errors.payment_type}
                                    >
                                        <SelectInput
                                            id="payment_type"
                                            value={data.payment_type}
                                            onChange={(value) =>
                                                setData('payment_type', value)
                                            }
                                            options={paymentTypes}
                                            disabled={!canManage}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="weight"
                                        label="Display order"
                                        hint="Lower numbers are listed first."
                                        error={errors.weight}
                                    >
                                        <Input
                                            id="weight"
                                            type="number"
                                            value={data.weight}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                setData(
                                                    'weight',
                                                    event.target.value,
                                                )
                                            }
                                            aria-describedby="weight-description"
                                        />
                                    </Field>
                                </div>
                            </SectionCard>

                            <SectionCard
                                title="Pricing"
                                description="On purchase the addon uses the parent's billing cycle when priced for it, otherwise its shortest offered cycle."
                                flush
                            >
                                {cycles.length === 0 ? (
                                    <p className="px-4 py-6 text-sm text-muted-foreground sm:px-6">
                                        Free addons have no prices.
                                    </p>
                                ) : (
                                    <PricingMatrix
                                        value={matrix}
                                        onChange={setMatrix}
                                        currencies={currencies}
                                        cycles={cycles}
                                        disabled={!canManage}
                                        cellErrors={matrixErrors(
                                            errors,
                                            submitted,
                                        )}
                                        idPrefix="addon-price"
                                    />
                                )}
                                {errors.prices && (
                                    <div className="border-t px-4 py-3 sm:px-6">
                                        <InputError message={errors.prices} />
                                    </div>
                                )}
                            </SectionCard>

                            <SectionCard
                                title="Provisioning"
                                description="Stored for the Services module, which runs the driver."
                            >
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="provisioning_driver"
                                        label="Driver key"
                                        error={errors.provisioning_driver}
                                    >
                                        <Input
                                            id="provisioning_driver"
                                            value={data.provisioning_driver}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                setData(
                                                    'provisioning_driver',
                                                    event.target.value,
                                                )
                                            }
                                            className="font-mono"
                                            aria-invalid={
                                                !!errors.provisioning_driver
                                            }
                                            aria-describedby="provisioning_driver-description"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="auto_setup"
                                        label="Auto-setup"
                                        error={errors.auto_setup}
                                    >
                                        <SelectInput
                                            id="auto_setup"
                                            value={data.auto_setup}
                                            onChange={(value) =>
                                                setData('auto_setup', value)
                                            }
                                            options={autoSetups}
                                            disabled={!canManage}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="welcome_email_key"
                                        label="Welcome email template"
                                        optional
                                        error={errors.welcome_email_key}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="welcome_email_key"
                                            value={data.welcome_email_key}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                setData(
                                                    'welcome_email_key',
                                                    event.target.value,
                                                )
                                            }
                                            className="font-mono"
                                            aria-describedby="welcome_email_key-description"
                                        />
                                    </Field>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <span className="text-sm font-medium">
                                            Driver settings
                                        </span>
                                        <KeyValueEditor
                                            value={data.driver_config}
                                            onChange={(pairs) =>
                                                setData('driver_config', pairs)
                                            }
                                            errors={errors}
                                            disabled={!canManage}
                                        />
                                    </div>
                                </div>
                            </SectionCard>
                        </div>

                        <div className="flex flex-col gap-6">
                            <SectionCard title="Visibility">
                                <div className="grid gap-3">
                                    {toggle(
                                        'show_on_order',
                                        'Offer on new orders',
                                        'Off: only offered to existing services.',
                                    )}
                                    {toggle(
                                        'is_hidden',
                                        'Hidden',
                                        'Hidden from clients except by direct link.',
                                    )}
                                    {toggle(
                                        'is_retired',
                                        'Retired',
                                        'Hidden everywhere, including staff pickers.',
                                    )}
                                </div>
                            </SectionCard>
                            <SectionCard title="Billing rules">
                                <div className="grid gap-3">
                                    {toggle(
                                        'is_taxable',
                                        'Taxable',
                                        'Apply tax rules to this addon.',
                                    )}
                                    {toggle(
                                        'prorata_with_parent',
                                        'Align with parent',
                                        'First period ends on the parent service due date when cycles match.',
                                    )}
                                    {toggle(
                                        'suspend_parent_on_overdue',
                                        'Suspend parent when overdue',
                                        'An overdue addon also suspends its service.',
                                    )}
                                    {toggle(
                                        'allow_quantity',
                                        'Allow quantity',
                                        'Clients can order more than one.',
                                    )}
                                </div>
                            </SectionCard>
                            <SectionCard title="Applicable products">
                                <CheckboxList
                                    label="Products"
                                    idPrefix="addon-product"
                                    disabled={!canManage}
                                    selected={data.product_ids}
                                    onChange={(ids) =>
                                        setData('product_ids', ids)
                                    }
                                    items={products.map((product) => ({
                                        id: product.id,
                                        label: product.name,
                                        hint: product.group,
                                    }))}
                                    empty={
                                        <p className="text-sm text-muted-foreground">
                                            There are no products yet.
                                        </p>
                                    }
                                />
                            </SectionCard>
                        </div>
                    </div>

                    {canManage && (
                        <FormActions sticky>
                            <Button variant="outline" asChild>
                                <Link href={AddonController.index()}>
                                    Cancel
                                </Link>
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {addon ? 'Save changes' : 'Create addon'}
                            </Button>
                        </FormActions>
                    )}
                </form>
            </Page>

            {addon && (
                <ConfirmDialog
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title={`Delete ${addon.name}?`}
                    description="Addons used by existing services can't be deleted; retire them instead."
                    confirmLabel="Delete addon"
                    action={AddonController.destroy(addon)}
                />
            )}
        </>
    );
}
