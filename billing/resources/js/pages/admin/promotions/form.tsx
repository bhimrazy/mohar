import { Head, Link, router, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/OrderController';
import PromotionController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/PromotionController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { Textarea } from '@/components/ui/textarea';
import { fromMinor } from '@/lib/money';
import type { SelectOption } from '@/types/app';
import type { Promotion } from '@/types/orders';

type Redemption = {
    id: number;
    client: string;
    order_id: number;
    order_number: string;
    item_type: string | null;
    discount_amount: number;
    currency: string;
    created_at: string | null;
    released_at: string | null;
};

type Props = {
    promotion?: Promotion;
    redemptions?: Redemption[];
    types: SelectOption[];
    cycles: SelectOption[];
    products: SelectOption[];
    addons: SelectOption[];
    currency: string;
};

type PromotionForm = {
    code: string;
    type: string;
    value: string;
    recurring: boolean;
    recur_count: string;
    applies_to_products: string[];
    applies_to_addons: string[];
    applies_to_tlds: string;
    requires_products: string[];
    requires_existing_ok: boolean;
    cycles: string[];
    starts_on: string;
    expires_on: string;
    max_uses: string;
    apply_once: boolean;
    once_per_client: boolean;
    new_clients_only: boolean;
    existing_clients_only: boolean;
    lifetime: boolean;
    allow_upgrades: boolean;
    min_cart_amount: string;
    discount_setup_fee: boolean;
    admin_notes: string;
};

type BooleanKey = {
    [K in keyof PromotionForm]: PromotionForm[K] extends boolean ? K : never;
}[keyof PromotionForm];
type ListKey =
    | 'applies_to_products'
    | 'applies_to_addons'
    | 'requires_products'
    | 'cycles';

function CheckboxList({
    id,
    options,
    value,
    onChange,
    empty,
}: {
    id: string;
    options: SelectOption[];
    value: string[];
    onChange: (value: string[]) => void;
    empty: string;
}) {
    if (options.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="grid max-h-56 gap-2 overflow-y-auto rounded-lg border p-3 sm:grid-cols-2">
            {options.map((option) => {
                const checkboxId = `${id}-${option.value}`;

                return (
                    <div key={option.value} className="flex items-center gap-2">
                        <Checkbox
                            id={checkboxId}
                            checked={value.includes(option.value)}
                            onCheckedChange={(checked) =>
                                onChange(
                                    checked
                                        ? [...value, option.value]
                                        : value.filter(
                                              (item) => item !== option.value,
                                          ),
                                )
                            }
                        />
                        <label htmlFor={checkboxId} className="text-sm">
                            {option.label}
                        </label>
                    </div>
                );
            })}
        </div>
    );
}

export default function PromotionFormPage({
    promotion,
    redemptions = [],
    types,
    cycles,
    products,
    addons,
    currency,
}: Props) {
    const editing = promotion !== undefined;
    const [confirm, setConfirm] = useState<'delete' | 'expire' | null>(null);
    const form = useForm<PromotionForm>({
        code: promotion?.code ?? '',
        type: promotion?.type ?? 'percent',
        value: promotion?.value ?? '',
        recurring: promotion?.recurring ?? false,
        recur_count: String(promotion?.recur_count ?? 0),
        applies_to_products: (promotion?.applies_to_products ?? []).map(String),
        applies_to_addons: (promotion?.applies_to_addons ?? []).map(String),
        applies_to_tlds: promotion?.applies_to_tlds ?? '',
        requires_products: (promotion?.requires_products ?? []).map(String),
        requires_existing_ok: promotion?.requires_existing_ok ?? false,
        cycles: promotion?.cycles ?? [],
        starts_on: promotion?.starts_on ?? '',
        expires_on: promotion?.expires_on ?? '',
        max_uses: String(promotion?.max_uses ?? 0),
        apply_once: promotion?.apply_once ?? false,
        once_per_client: promotion?.once_per_client ?? false,
        new_clients_only: promotion?.new_clients_only ?? false,
        existing_clients_only: promotion?.existing_clients_only ?? false,
        lifetime: promotion?.lifetime ?? false,
        allow_upgrades: promotion?.allow_upgrades ?? false,
        min_cart_amount: promotion?.min_cart_amount
            ? String(fromMinor(promotion.min_cart_amount))
            : '',
        discount_setup_fee: promotion?.discount_setup_fee ?? false,
        admin_notes: promotion?.admin_notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.submit(
            editing
                ? PromotionController.update(promotion.id)
                : PromotionController.store(),
            { preserveScroll: true },
        );
    };

    const toggle = (key: BooleanKey, title: string, description: string) => (
        <div className="flex items-start justify-between gap-4 rounded-lg border p-4">
            <div className="space-y-0.5">
                <label htmlFor={key} className="text-sm font-medium">
                    {title}
                </label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch
                id={key}
                checked={form.data[key]}
                onCheckedChange={(checked) => form.setData(key, checked)}
            />
        </div>
    );

    const list = (key: ListKey) => ({
        value: form.data[key],
        onChange: (value: string[]) => form.setData(key, value),
    });

    const input = (
        key: keyof PromotionForm,
        props: React.ComponentProps<typeof Input> = {},
    ) => (
        <Input
            id={key}
            value={String(form.data[key])}
            onChange={(event) => form.setData(key, event.target.value as never)}
            aria-invalid={!!errors[key]}
            aria-describedby={`${key}-description`}
            {...props}
        />
    );

    const redemptionColumns: DataTableColumn<Redemption>[] = [
        {
            key: 'order',
            header: 'Order',
            primary: true,
            cell: (row) => (
                <span className="font-mono">#{row.order_number}</span>
            ),
        },
        { key: 'client', header: 'Client', cell: (row) => row.client },
        {
            key: 'discount',
            header: 'Discount',
            align: 'right',
            cell: (row) => (
                <Money amount={row.discount_amount} currency={row.currency} />
            ),
        },
        {
            key: 'date',
            header: 'Redeemed',
            cell: (row) => <DateTime value={row.created_at} />,
        },
        {
            key: 'released',
            header: 'Released',
            hideOnMobile: true,
            cell: (row) => <DateTime value={row.released_at} fallback="—" />,
        },
    ];

    const amountType =
        form.data.type === 'fixed' || form.data.type === 'price_override';

    return (
        <>
            <Head title={editing ? promotion.code : 'New promotion'} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: PromotionController.index.url(),
                        label: 'Promotions',
                    }}
                    title={editing ? promotion.code : 'New promotion'}
                    status={promotion?.status}
                    meta={
                        editing
                            ? `${promotion.summary} · used ${promotion.uses}${promotion.max_uses ? ` of ${promotion.max_uses}` : ''} times`
                            : undefined
                    }
                    actions={
                        editing && (
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
                                        onSelect={() =>
                                            router.post(
                                                PromotionController.duplicate.url(
                                                    promotion.id,
                                                ),
                                            )
                                        }
                                    >
                                        Duplicate
                                    </DropdownMenuItem>
                                    {!promotion.expired_at && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setConfirm('expire')
                                            }
                                        >
                                            Expire now
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setConfirm('delete')}
                                    >
                                        Delete promotion
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    }
                />

                {editing && confirm === 'delete' && (
                    <ConfirmDialog
                        open
                        onOpenChange={(open) => !open && setConfirm(null)}
                        title={`Delete promotion ${promotion.code}?`}
                        description="Only promotions that were never redeemed can be deleted. This can't be undone."
                        confirmLabel="Delete promotion"
                        action={PromotionController.destroy(promotion.id)}
                    />
                )}
                {editing && confirm === 'expire' && (
                    <ConfirmDialog
                        open
                        onOpenChange={(open) => !open && setConfirm(null)}
                        title={`Expire ${promotion.code} now?`}
                        description="Customers can no longer apply the code. Services that already use it keep their renewal discount."
                        confirmLabel="Expire promotion"
                        method="post"
                        action={PromotionController.expire(promotion.id)}
                    />
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <InputError message={errors.promotion} />

                    <SectionCard title="Discount">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="code"
                                label="Code"
                                error={errors.code}
                                hint="Letters, numbers, dashes. Customers can type it in any case."
                            >
                                {input('code', {
                                    className: 'font-mono uppercase',
                                    autoComplete: 'off',
                                })}
                            </Field>
                            <Field
                                htmlFor="type"
                                label="Type"
                                error={errors.type}
                            >
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) =>
                                        form.setData('type', value)
                                    }
                                >
                                    <SelectTrigger id="type" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            {form.data.type !== 'free_setup' && (
                                <Field
                                    htmlFor="value"
                                    label={
                                        form.data.type === 'percent'
                                            ? 'Percentage'
                                            : form.data.type ===
                                                'price_override'
                                              ? `New price (${currency})`
                                              : `Amount off (${currency})`
                                    }
                                    error={errors.value}
                                    hint={
                                        amountType
                                            ? 'Converted to the cart currency at the current exchange rate.'
                                            : undefined
                                    }
                                >
                                    {input('value', {
                                        inputMode: 'decimal',
                                        placeholder:
                                            form.data.type === 'percent'
                                                ? '25'
                                                : '10.00',
                                    })}
                                </Field>
                            )}
                            {form.data.recurring && (
                                <Field
                                    htmlFor="recur_count"
                                    label="Renewals discounted"
                                    error={errors.recur_count}
                                    hint="0 = every renewal"
                                >
                                    {input('recur_count', {
                                        type: 'number',
                                        min: 0,
                                    })}
                                </Field>
                            )}
                            <div className="grid gap-3 sm:col-span-2">
                                {toggle(
                                    'recurring',
                                    'Recurring',
                                    'Keep discounting renewals, not just the first period.',
                                )}
                                {form.data.type !== 'free_setup' &&
                                    toggle(
                                        'discount_setup_fee',
                                        'Discount setup fees',
                                        'Apply the discount to setup fees too.',
                                    )}
                            </div>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Applies to"
                        description="Leave products, addons and extensions empty to discount every product."
                    >
                        <div className="grid gap-6">
                            <Field
                                htmlFor="applies_to_products"
                                label="Products"
                                error={errors.applies_to_products}
                            >
                                <CheckboxList
                                    id="applies_to_products"
                                    options={products}
                                    empty="No products in the catalog."
                                    {...list('applies_to_products')}
                                />
                            </Field>
                            <Field
                                htmlFor="applies_to_addons"
                                label="Addons"
                                optional
                            >
                                <CheckboxList
                                    id="applies_to_addons"
                                    options={addons}
                                    empty="No addons in the catalog."
                                    {...list('applies_to_addons')}
                                />
                            </Field>
                            <Field
                                htmlFor="applies_to_tlds"
                                label="Domain extensions"
                                optional
                                error={errors.applies_to_tlds}
                                hint="Comma separated, e.g. .com, .net"
                            >
                                {input('applies_to_tlds', {
                                    placeholder: '.com, .net',
                                })}
                            </Field>
                            <Field
                                htmlFor="cycles"
                                label="Billing cycles"
                                optional
                                hint="Leave empty for any cycle."
                            >
                                <CheckboxList
                                    id="cycles"
                                    options={cycles}
                                    empty=""
                                    {...list('cycles')}
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard title="Requirements and limits">
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="starts_on"
                                label="Starts on"
                                optional
                                error={errors.starts_on}
                            >
                                {input('starts_on', { type: 'date' })}
                            </Field>
                            <Field
                                htmlFor="expires_on"
                                label="Expires on"
                                optional
                                error={errors.expires_on}
                                hint="Valid until the end of this day (company timezone)."
                            >
                                {input('expires_on', { type: 'date' })}
                            </Field>
                            <Field
                                htmlFor="max_uses"
                                label="Maximum uses"
                                error={errors.max_uses}
                                hint="0 = unlimited"
                            >
                                {input('max_uses', { type: 'number', min: 0 })}
                            </Field>
                            <Field
                                htmlFor="min_cart_amount"
                                label={`Minimum cart (${currency})`}
                                optional
                                error={errors.min_cart_amount}
                            >
                                {input('min_cart_amount', {
                                    inputMode: 'decimal',
                                    placeholder: '0.00',
                                })}
                            </Field>
                            <Field
                                htmlFor="requires_products"
                                label="Requires one of these products in the cart"
                                optional
                                className="sm:col-span-2"
                            >
                                <CheckboxList
                                    id="requires_products"
                                    options={products}
                                    empty="No products in the catalog."
                                    {...list('requires_products')}
                                />
                            </Field>
                            <div className="grid gap-3 sm:col-span-2">
                                {toggle(
                                    'requires_existing_ok',
                                    'Owned products count',
                                    'A required product the client already has satisfies the requirement.',
                                )}
                                {toggle(
                                    'apply_once',
                                    'Apply once per order',
                                    'Only the most expensive matching item is discounted.',
                                )}
                                {toggle(
                                    'once_per_client',
                                    'Once per client',
                                    'Each client can redeem the code one time.',
                                )}
                                {toggle(
                                    'new_clients_only',
                                    'New clients only',
                                    'Clients with an earlier order cannot use it.',
                                )}
                                {toggle(
                                    'existing_clients_only',
                                    'Existing clients only',
                                    'Requires at least one active service.',
                                )}
                                {toggle(
                                    'allow_upgrades',
                                    'Allow on upgrades',
                                    'Discount prorated upgrade charges.',
                                )}
                                {toggle(
                                    'lifetime',
                                    'Lifetime',
                                    'Carry the discount over when the service is upgraded.',
                                )}
                            </div>
                        </div>
                    </SectionCard>

                    <SectionCard title="Admin notes">
                        <Textarea
                            id="admin_notes"
                            aria-label="Admin notes"
                            rows={3}
                            value={form.data.admin_notes}
                            onChange={(event) =>
                                form.setData('admin_notes', event.target.value)
                            }
                        />
                    </SectionCard>

                    <FormActions sticky>
                        <Button variant="outline" asChild>
                            <Link href={PromotionController.index.url()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {editing ? 'Save changes' : 'Create promotion'}
                        </Button>
                    </FormActions>
                </form>

                {editing && (
                    <SectionCard
                        title="Redemptions"
                        description="Latest 50 uses of this code."
                        flush
                    >
                        <DataTable
                            caption="Redemptions"
                            columns={redemptionColumns}
                            rows={redemptions}
                            getRowKey={(row) => row.id}
                            rowHref={(row) =>
                                OrderController.show.url(row.order_id)
                            }
                            className="rounded-none border-0 shadow-none"
                            empty={
                                <EmptyState
                                    title="Not redeemed yet"
                                    description="Orders that use this code appear here."
                                />
                            }
                        />
                    </SectionCard>
                )}
            </Page>
        </>
    );
}
