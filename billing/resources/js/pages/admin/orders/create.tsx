import {
    TldAttributeFields,
    tldFieldsFor,
    type TldAttributeFieldMap,
} from '@/components/orders/tld-attribute-fields';
import { Link, router, useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import OrderController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/OrderController';
import { decimalAmount } from '@/components/orders/decimal-amount';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import { cycleLabel } from '@/lib/billing-cycles';
import type { SelectOption } from '@/types/app';
import type { StoreGroup } from '@/types/orders';

type Props = {
    client: {
        id: number;
        display_name: string;
        email: string;
        currency: string;
    } | null;
    clients: SelectOption[];
    groups: StoreGroup[];
    gateways: SelectOption[];
    tld_attribute_fields: TldAttributeFieldMap;
};

type StaffOrderForm = {
    client_id: string;
    product_id: string;
    cycle: string;
    domain_mode: string;
    domain_name: string;
    epp_code: string;
    tld_attributes: Record<string, string>;
    price_override: string;
    promotion_code: string;
    payment_gateway: string;
    skip_fraud: boolean;
    send_invoice: boolean;
    admin_notes: string;
};

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
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-lg border p-4">
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

export default function CreateOrder({
    client,
    clients,
    groups,
    gateways,
    tld_attribute_fields: tldAttributeFields,
}: Props) {
    const form = useForm<StaffOrderForm>({
        client_id: client ? String(client.id) : '',
        product_id: '',
        cycle: '',
        domain_mode: '',
        domain_name: '',
        epp_code: '',
        tld_attributes: {},
        price_override: '',
        promotion_code: '',
        payment_gateway: gateways[0]?.value ?? '',
        skip_fraud: true,
        send_invoice: true,
        admin_notes: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const tldFields =
        form.data.domain_mode === 'register'
            ? tldFieldsFor(tldAttributeFields, form.data.domain_name)
            : [];
    const products = groups.flatMap((group) =>
        group.products.map((product) => ({ ...product, group: group.name })),
    );
    const product = products.find(
        (candidate) => String(candidate.id) === form.data.product_id,
    );
    const price = product?.pricing.find((row) => row.cycle === form.data.cycle);

    const chooseClient = (value: string) =>
        router.get(
            OrderController.create.url(),
            { client: value },
            { preserveState: false, replace: true },
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.clearErrors('price_override');

        const override =
            form.data.price_override.trim() === ''
                ? ''
                : decimalAmount(form.data.price_override);

        if (override === null) {
            form.setError('price_override', 'Enter a price like 10 or 12.50.');

            return;
        }

        form.transform((data) => ({ ...data, price_override: override }));
        form.submit(OrderController.store());
    };

    return (
        <>
            <Head title="New order" />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: OrderController.index.url(),
                        label: 'Orders',
                    }}
                    title="New order"
                    description="Place an order for a client. It goes through the same checkout pipeline as the store."
                />

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <InputError
                        message={errors.cart ?? errors.promotion_code}
                    />

                    <SectionCard title="Client">
                        <Field
                            htmlFor="client_id"
                            label="Client"
                            error={errors.client_id}
                            hint={
                                client
                                    ? `${client.email} · billed in ${client.currency}`
                                    : 'Products and prices load in the client’s currency.'
                            }
                        >
                            <Select
                                value={form.data.client_id}
                                onValueChange={chooseClient}
                            >
                                <SelectTrigger
                                    id="client_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a client" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </SectionCard>

                    {client && (
                        <>
                            <SectionCard title="Product">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="product_id"
                                        label="Product"
                                        error={errors.product_id}
                                        className="sm:col-span-2"
                                    >
                                        <Select
                                            value={form.data.product_id}
                                            onValueChange={(value) =>
                                                form.setData((data) => ({
                                                    ...data,
                                                    product_id: value,
                                                    cycle: '',
                                                }))
                                            }
                                        >
                                            <SelectTrigger
                                                id="product_id"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose a product" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products.map((option) => (
                                                    <SelectItem
                                                        key={option.id}
                                                        value={String(
                                                            option.id,
                                                        )}
                                                        disabled={
                                                            !option.in_stock
                                                        }
                                                    >
                                                        {option.group}:{' '}
                                                        {option.name}
                                                        {!option.in_stock &&
                                                            ' (out of stock)'}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field
                                        htmlFor="cycle"
                                        label="Billing cycle"
                                        error={errors.cycle}
                                    >
                                        <Select
                                            value={form.data.cycle}
                                            onValueChange={(value) =>
                                                form.setData('cycle', value)
                                            }
                                            disabled={!product}
                                        >
                                            <SelectTrigger
                                                id="cycle"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose a cycle" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(product?.pricing ?? []).map(
                                                    (row) => (
                                                        <SelectItem
                                                            key={row.cycle}
                                                            value={row.cycle}
                                                        >
                                                            {cycleLabel(
                                                                row.cycle,
                                                            )}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field
                                        htmlFor="price_override"
                                        label="Price override"
                                        optional
                                        error={errors.price_override}
                                        hint={
                                            price ? (
                                                <>
                                                    Catalog price{' '}
                                                    <Money
                                                        amount={price.recurring}
                                                        currency={
                                                            client.currency
                                                        }
                                                    />
                                                </>
                                            ) : (
                                                'Leave empty to use the catalog price.'
                                            )
                                        }
                                    >
                                        <Input
                                            id="price_override"
                                            inputMode="decimal"
                                            value={form.data.price_override}
                                            onChange={(event) =>
                                                form.setData(
                                                    'price_override',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="0.00"
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="domain_mode"
                                        label="Domain"
                                        optional
                                        error={errors.domain_mode}
                                    >
                                        <Select
                                            value={
                                                form.data.domain_mode || 'none'
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'domain_mode',
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="domain_mode"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    No domain
                                                </SelectItem>
                                                <SelectItem value="register">
                                                    Register
                                                </SelectItem>
                                                <SelectItem value="transfer">
                                                    Transfer
                                                </SelectItem>
                                                <SelectItem value="existing">
                                                    Client's own domain
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    {form.data.domain_mode && (
                                        <Field
                                            htmlFor="domain_name"
                                            label="Domain name"
                                            error={errors.domain_name}
                                        >
                                            <Input
                                                id="domain_name"
                                                value={form.data.domain_name}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'domain_name',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="example.com"
                                            />
                                        </Field>
                                    )}
                                    {tldFields.length > 0 && (
                                        <TldAttributeFields
                                            fields={tldFields}
                                            values={form.data.tld_attributes}
                                            onChange={(values) =>
                                                form.setData(
                                                    'tld_attributes',
                                                    values,
                                                )
                                            }
                                            errors={errors}
                                        />
                                    )}
                                    {form.data.domain_mode === 'transfer' && (
                                        <Field
                                            htmlFor="epp_code"
                                            label="EPP code"
                                            error={errors.epp_code}
                                        >
                                            <Input
                                                id="epp_code"
                                                value={form.data.epp_code}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'epp_code',
                                                        event.target.value,
                                                    )
                                                }
                                                autoComplete="off"
                                            />
                                        </Field>
                                    )}
                                </div>
                            </SectionCard>

                            <SectionCard title="Billing">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="payment_gateway"
                                        label="Payment method"
                                        error={errors.payment_gateway}
                                    >
                                        <Select
                                            value={form.data.payment_gateway}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'payment_gateway',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="payment_gateway"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {gateways.map((gateway) => (
                                                    <SelectItem
                                                        key={gateway.value}
                                                        value={gateway.value}
                                                    >
                                                        {gateway.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field
                                        htmlFor="promotion_code"
                                        label="Promotion code"
                                        optional
                                        error={errors.promotion_code}
                                    >
                                        <Input
                                            id="promotion_code"
                                            value={form.data.promotion_code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'promotion_code',
                                                    event.target.value,
                                                )
                                            }
                                            className="uppercase"
                                        />
                                    </Field>
                                    <div className="grid gap-3 sm:col-span-2">
                                        <ToggleRow
                                            id="skip_fraud"
                                            title="Skip fraud check"
                                            description="Recorded as skipped on the order."
                                            checked={form.data.skip_fraud}
                                            onChange={(checked) =>
                                                form.setData(
                                                    'skip_fraud',
                                                    checked,
                                                )
                                            }
                                        />
                                        <ToggleRow
                                            id="send_invoice"
                                            title="Email the invoice"
                                            description="Send the new invoice to the client."
                                            checked={form.data.send_invoice}
                                            onChange={(checked) =>
                                                form.setData(
                                                    'send_invoice',
                                                    checked,
                                                )
                                            }
                                        />
                                    </div>
                                    <Field
                                        htmlFor="admin_notes"
                                        label="Admin notes"
                                        optional
                                        className="sm:col-span-2"
                                    >
                                        <Textarea
                                            id="admin_notes"
                                            value={form.data.admin_notes}
                                            onChange={(event) =>
                                                form.setData(
                                                    'admin_notes',
                                                    event.target.value,
                                                )
                                            }
                                            rows={3}
                                        />
                                    </Field>
                                </div>
                            </SectionCard>
                        </>
                    )}

                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={OrderController.index.url()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                !client ||
                                !form.data.product_id ||
                                !form.data.cycle
                            }
                        >
                            {form.processing && <Spinner />}
                            Place order
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
