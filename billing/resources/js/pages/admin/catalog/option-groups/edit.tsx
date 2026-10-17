import { Head, useForm } from '@inertiajs/react';
import { MoreHorizontal, Plus, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import ConfigOptionController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionController';
import ConfigOptionGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionGroupController';
import ConfigOptionValueController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionValueController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { CheckboxList } from '@/components/catalog/checkbox-list';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type OptionValue = {
    id: number;
    label: string;
    module_value: string | null;
    is_hidden: boolean;
    sort_order: number;
    prices: PriceRow[];
};

type Option = {
    id: number;
    name: string;
    module_key: string | null;
    type: string;
    quantity_min: number | null;
    quantity_max: number | null;
    quantity_step: number;
    is_hidden: boolean;
    is_required: boolean;
    sort_order: number;
    values: OptionValue[];
};

type Props = {
    group: {
        id: number;
        name: string;
        description: string | null;
        product_ids: number[];
    };
    options: Option[];
    products: ProductChoice[];
    currencies: CurrencyOption[];
    cycles: CycleOption[];
    optionTypes: EnumOption[];
    canManage: boolean;
};

type Deleting =
    | { kind: 'group' }
    | { kind: 'option'; option: Option }
    | { kind: 'value'; value: OptionValue; option: Option };

export default function EditOptionGroup({
    group,
    options,
    products,
    currencies,
    cycles,
    optionTypes,
    canManage,
}: Props) {
    const [optionDialog, setOptionDialog] = useState<Option | 'new' | null>(
        null,
    );
    const [valueDialog, setValueDialog] = useState<{
        option: Option;
        value: OptionValue | null;
    } | null>(null);
    const [deleting, setDeleting] = useState<Deleting | null>(null);
    const typeLabel = (type: string) =>
        optionTypes.find((option) => option.value === type)?.label ?? type;

    return (
        <>
            <Head title={group.name} />
            <Page>
                <PageHeader
                    back={{
                        href: ConfigOptionGroupController.index(),
                        label: 'Configurable options',
                    }}
                    title={group.name}
                    meta={
                        <span>
                            {options.length}{' '}
                            {options.length === 1 ? 'option' : 'options'} · used
                            by {group.product_ids.length}{' '}
                            {group.product_ids.length === 1
                                ? 'product'
                                : 'products'}
                        </span>
                    }
                    actions={
                        canManage && (
                            <>
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
                                            onSelect={() =>
                                                setDeleting({ kind: 'group' })
                                            }
                                        >
                                            Delete option group
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <Button onClick={() => setOptionDialog('new')}>
                                    <Plus />
                                    Add option
                                </Button>
                            </>
                        )
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-6 lg:col-span-2">
                        {options.length === 0 && (
                            <EmptyState
                                variant="bordered"
                                icon={SlidersHorizontal}
                                title="No options yet"
                                description="Add an option such as “Memory” (dropdown) or “Extra IPs” (quantity)."
                            />
                        )}
                        {options.map((option) => (
                            <SectionCard
                                key={option.id}
                                flush
                                title={
                                    <span className="flex flex-wrap items-center gap-2">
                                        {option.name}
                                        <Badge variant="outline">
                                            {typeLabel(option.type)}
                                        </Badge>
                                        {option.is_required && (
                                            <Badge variant="outline">
                                                Required
                                            </Badge>
                                        )}
                                        {option.is_hidden && (
                                            <StatusBadge status="hidden" />
                                        )}
                                    </span>
                                }
                                description={
                                    option.type === 'quantity'
                                        ? `Price per unit · ${option.quantity_min === null ? 'any quantity' : `${option.quantity_min}–${option.quantity_max}`}${option.quantity_step > 1 ? `, steps of ${option.quantity_step}` : ''}`
                                        : option.module_key
                                          ? `Driver key: ${option.module_key}`
                                          : undefined
                                }
                                actions={
                                    canManage && (
                                        <>
                                            {!['yes_no', 'quantity'].includes(
                                                option.type,
                                            ) && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setValueDialog({
                                                            option,
                                                            value: null,
                                                        })
                                                    }
                                                >
                                                    <Plus />
                                                    Add value
                                                </Button>
                                            )}
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Actions for ${option.name}`}
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setOptionDialog(
                                                                option,
                                                            )
                                                        }
                                                    >
                                                        Edit option
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            setDeleting({
                                                                kind: 'option',
                                                                option,
                                                            })
                                                        }
                                                    >
                                                        Delete option
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </>
                                    )
                                }
                            >
                                <ValuesTable
                                    option={option}
                                    currency={
                                        currencies.find(
                                            (currency) => currency.is_default,
                                        )?.code ??
                                        currencies[0]?.code ??
                                        'USD'
                                    }
                                    canManage={canManage}
                                    onEdit={(value) =>
                                        setValueDialog({ option, value })
                                    }
                                    onDelete={(value) =>
                                        setDeleting({
                                            kind: 'value',
                                            value,
                                            option,
                                        })
                                    }
                                />
                            </SectionCard>
                        ))}
                    </div>

                    <GroupSettings
                        group={group}
                        products={products}
                        canManage={canManage}
                    />
                </div>
            </Page>

            {optionDialog && (
                <OptionDialog
                    groupId={group.id}
                    option={optionDialog === 'new' ? null : optionDialog}
                    types={optionTypes}
                    onClose={() => setOptionDialog(null)}
                />
            )}
            {valueDialog && (
                <ValueDialog
                    option={valueDialog.option}
                    value={valueDialog.value}
                    currencies={currencies}
                    cycles={cycles}
                    onClose={() => setValueDialog(null)}
                />
            )}
            {deleting && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={
                        deleting.kind === 'group'
                            ? `Delete ${group.name}?`
                            : deleting.kind === 'option'
                              ? `Delete ${deleting.option.name}?`
                              : `Delete ${deleting.value.label}?`
                    }
                    description="Values selected by existing services can't be deleted — hide them instead so those services keep their price."
                    confirmLabel={
                        deleting.kind === 'group'
                            ? 'Delete option group'
                            : deleting.kind === 'option'
                              ? 'Delete option'
                              : 'Delete value'
                    }
                    action={
                        deleting.kind === 'group'
                            ? ConfigOptionGroupController.destroy(group)
                            : deleting.kind === 'option'
                              ? ConfigOptionController.destroy(deleting.option)
                              : ConfigOptionValueController.destroy(
                                    deleting.value,
                                )
                    }
                    onSuccess={() => setDeleting(null)}
                />
            )}
        </>
    );
}

function ValuesTable({
    option,
    currency,
    canManage,
    onEdit,
    onDelete,
}: {
    option: Option;
    currency: string;
    canManage: boolean;
    onEdit: (value: OptionValue) => void;
    onDelete: (value: OptionValue) => void;
}) {
    const single = ['yes_no', 'quantity'].includes(option.type);

    if (option.values.length === 0) {
        return (
            <p className="px-4 py-6 text-sm text-muted-foreground sm:px-6">
                No values yet. Add the choices clients can pick.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {option.values.map((value) => {
                const monthly = value.prices.find(
                    (price) =>
                        price.currency === currency &&
                        price.cycle === 'monthly' &&
                        price.enabled,
                );
                const priced = value.prices.filter(
                    (price) => price.enabled,
                ).length;

                return (
                    <li
                        key={value.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 sm:px-6"
                    >
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {single
                                    ? option.type === 'quantity'
                                        ? 'Unit price'
                                        : 'Price when selected'
                                    : value.label}
                                {value.is_hidden && (
                                    <StatusBadge status="hidden" />
                                )}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {value.module_value ? (
                                    <span className="font-mono">
                                        {value.module_value}
                                    </span>
                                ) : (
                                    'No driver value'
                                )}{' '}
                                · {priced} {priced === 1 ? 'price' : 'prices'}{' '}
                                set
                            </div>
                        </div>
                        <div className="text-right text-sm">
                            {monthly ? (
                                <>
                                    <Money
                                        amount={monthly.amount}
                                        currency={currency}
                                    />
                                    <span className="text-muted-foreground">
                                        /mo
                                    </span>
                                </>
                            ) : (
                                <span className="text-muted-foreground">
                                    No monthly {currency} price
                                </span>
                            )}
                        </div>
                        {canManage && (
                            <div className="flex gap-1">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => onEdit(value)}
                                >
                                    Edit prices
                                </Button>
                                {!single && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() => onDelete(value)}
                                    >
                                        Delete
                                    </Button>
                                )}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function GroupSettings({
    group,
    products,
    canManage,
}: {
    group: Props['group'];
    products: ProductChoice[];
    canManage: boolean;
}) {
    const form = useForm({
        name: group.name,
        description: group.description ?? '',
        product_ids: group.product_ids,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ConfigOptionGroupController.update(group), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Group settings"
                footer={
                    canManage && (
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save group
                        </Button>
                    )
                }
            >
                <div className="grid gap-6">
                    <Field
                        htmlFor="group-name"
                        label="Name"
                        error={errors.name}
                    >
                        <Input
                            id="group-name"
                            value={form.data.name}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-describedby="group-name-description"
                        />
                    </Field>
                    <Field
                        htmlFor="group-description"
                        label="Notes"
                        optional
                        error={errors.description}
                    >
                        <Textarea
                            id="group-description"
                            rows={2}
                            value={form.data.description}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            aria-describedby="group-description-description"
                        />
                    </Field>
                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            Assigned products
                        </span>
                        <CheckboxList
                            label="Products"
                            idPrefix="group-product"
                            disabled={!canManage}
                            selected={form.data.product_ids}
                            onChange={(ids) => form.setData('product_ids', ids)}
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
                        <InputError message={errors.product_ids} />
                    </div>
                </div>
            </SectionCard>
        </form>
    );
}

function OptionDialog({
    groupId,
    option,
    types,
    onClose,
}: {
    groupId: number;
    option: Option | null;
    types: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: option?.name ?? '',
        module_key: option?.module_key ?? '',
        type: option?.type ?? 'dropdown',
        quantity_min: option?.quantity_min?.toString() ?? '',
        quantity_max: option?.quantity_max?.toString() ?? '',
        quantity_step: (option?.quantity_step ?? 1).toString(),
        is_hidden: option?.is_hidden ?? false,
        is_required: option?.is_required ?? false,
        sort_order: (option?.sort_order ?? 0).toString(),
    });
    const { data, setData, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            option
                ? ConfigOptionController.update(option)
                : ConfigOptionController.store(groupId),
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {option ? `Edit ${option.name}` : 'Add option'}
                        </DialogTitle>
                        <DialogDescription>
                            Yes/no and quantity options have a single price row
                            (the price when ticked, or per unit).
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            htmlFor="option-name"
                            label="Label"
                            hint="Shown to clients."
                            error={errors.name}
                        >
                            <Input
                                id="option-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                aria-invalid={!!errors.name}
                                aria-describedby="option-name-description"
                            />
                        </Field>
                        <Field
                            htmlFor="option-type"
                            label="Type"
                            error={errors.type}
                        >
                            <SelectInput
                                id="option-type"
                                value={data.type}
                                onChange={(value) => setData('type', value)}
                                options={types}
                                invalid={!!errors.type}
                            />
                        </Field>
                        <Field
                            htmlFor="option-module-key"
                            label="Driver key"
                            optional
                            hint="Passed to the provisioning driver."
                            error={errors.module_key}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="option-module-key"
                                value={data.module_key}
                                onChange={(event) =>
                                    setData('module_key', event.target.value)
                                }
                                className="font-mono"
                                aria-describedby="option-module-key-description"
                            />
                        </Field>
                        {data.type === 'quantity' && (
                            <div className="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                                <Field
                                    htmlFor="option-min"
                                    label="Minimum"
                                    optional
                                    error={errors.quantity_min}
                                >
                                    <Input
                                        id="option-min"
                                        type="number"
                                        min={0}
                                        value={data.quantity_min}
                                        onChange={(event) =>
                                            setData(
                                                'quantity_min',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!errors.quantity_min}
                                        aria-describedby="option-min-description"
                                    />
                                </Field>
                                <Field
                                    htmlFor="option-max"
                                    label="Maximum"
                                    optional
                                    error={errors.quantity_max}
                                >
                                    <Input
                                        id="option-max"
                                        type="number"
                                        min={1}
                                        value={data.quantity_max}
                                        onChange={(event) =>
                                            setData(
                                                'quantity_max',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!errors.quantity_max}
                                        aria-describedby="option-max-description"
                                    />
                                </Field>
                                <Field
                                    htmlFor="option-step"
                                    label="Step"
                                    error={errors.quantity_step}
                                >
                                    <Input
                                        id="option-step"
                                        type="number"
                                        min={1}
                                        value={data.quantity_step}
                                        onChange={(event) =>
                                            setData(
                                                'quantity_step',
                                                event.target.value,
                                            )
                                        }
                                        aria-describedby="option-step-description"
                                    />
                                </Field>
                            </div>
                        )}
                        <div className="grid gap-2 sm:col-span-2">
                            <SwitchRow
                                id="option-required"
                                title="Required"
                                description="Clients must choose a value."
                                checked={data.is_required}
                                onCheckedChange={(checked) =>
                                    setData('is_required', checked)
                                }
                            />
                            <SwitchRow
                                id="option-hidden"
                                title="Hidden"
                                description="Not offered for new orders; existing services keep their selection and price."
                                checked={data.is_hidden}
                                onCheckedChange={(checked) =>
                                    setData('is_hidden', checked)
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {option ? 'Save option' : 'Add option'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ValueDialog({
    option,
    value,
    currencies,
    cycles,
    onClose,
}: {
    option: Option;
    value: OptionValue | null;
    currencies: CurrencyOption[];
    cycles: CycleOption[];
    onClose: () => void;
}) {
    const initial = useMemo(
        () => matrixFromRows(value?.prices ?? [], currencies, cycles),
        [value, currencies, cycles],
    );
    const [matrix, setMatrix] = useState(initial);
    const [submitted, setSubmitted] = useState(rowsFromMatrix(initial));
    const single = ['yes_no', 'quantity'].includes(option.type);
    const form = useForm({
        label: value?.label ?? '',
        module_value: value?.module_value ?? '',
        is_hidden: value?.is_hidden ?? false,
        sort_order: (value?.sort_order ?? 0).toString(),
        prices: rowsFromMatrix(initial),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const prices = rowsFromMatrix(matrix);
        setSubmitted(prices);
        form.transform((data) => ({ ...data, prices }));
        form.submit(
            value
                ? ConfigOptionValueController.update(value)
                : ConfigOptionValueController.store(option),
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <form onSubmit={submit} className="grid gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {option.name}
                            {value && !single ? ` › ${value.label}` : ''}
                        </DialogTitle>
                        <DialogDescription>
                            {option.type === 'quantity'
                                ? 'Price of one unit; it is multiplied by the quantity ordered.'
                                : 'Price for each cycle the product sells. Missing prices block the cycle on products using this option.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            htmlFor="value-label"
                            label="Label"
                            hint="Shown to clients and on invoices."
                            error={errors.label}
                        >
                            <Input
                                id="value-label"
                                value={form.data.label}
                                onChange={(event) =>
                                    form.setData('label', event.target.value)
                                }
                                aria-invalid={!!errors.label}
                                aria-describedby="value-label-description"
                            />
                        </Field>
                        <Field
                            htmlFor="value-module"
                            label="Driver value"
                            optional
                            hint="Machine value passed to the driver, e.g. 4096."
                            error={errors.module_value}
                        >
                            <Input
                                id="value-module"
                                value={form.data.module_value}
                                onChange={(event) =>
                                    form.setData(
                                        'module_value',
                                        event.target.value,
                                    )
                                }
                                className="font-mono"
                                aria-describedby="value-module-description"
                            />
                        </Field>
                        {!single && (
                            <SwitchRow
                                id="value-hidden"
                                className="sm:col-span-2"
                                title="Hidden"
                                description="Not offered for new orders; services that chose it keep renewing at its price."
                                checked={form.data.is_hidden}
                                onCheckedChange={(checked) =>
                                    form.setData('is_hidden', checked)
                                }
                            />
                        )}
                    </div>
                    <div className="overflow-hidden rounded-lg border">
                        <PricingMatrix
                            value={matrix}
                            onChange={setMatrix}
                            currencies={currencies}
                            cycles={cycles}
                            cellErrors={matrixErrors(errors, submitted)}
                            idPrefix="value-price"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {value ? 'Save value' : 'Add value'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
