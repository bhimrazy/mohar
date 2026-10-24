import { Link, useForm, useHttp } from '@inertiajs/react';
import {
    TldAttributeFields,
    tldFieldsFor,
    tldMatch,
    type TldAttributeFieldMap,
} from '@/components/orders/tld-attribute-fields';
import { Head } from '@/components/app/head';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import CartController from '@/actions/App/Modules/Orders/Http/Controllers/Public/CartController';
import StoreController from '@/actions/App/Modules/Orders/Http/Controllers/Public/StoreController';
import { Field } from '@/components/app/field';
import { CurrencyUnavailable } from '@/components/orders/currency-unavailable';
import { Money } from '@/components/app/money';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cycleLabel, cycleMonths, cycleSuffix } from '@/lib/billing-cycles';
import { cn } from '@/lib/utils';
import store from '@/routes/store';
import type { PriceRow, PricedItem } from '@/types/orders';

type ConfigOption = {
    id: number;
    name: string;
    type: string;
    values: { id: number; name: string; pricing: PriceRow[] }[];
};

type Addon = {
    id: number;
    name: string;
    description: string | null;
    pricing: PriceRow[];
};

type CustomField = {
    id: number;
    name: string;
    type: string;
    required: boolean;
    options: string[];
};

type Props = {
    product: {
        id: number;
        name: string;
        group: string | null;
        requires_domain: boolean;
        in_stock: boolean;
    };
    currency: string;
    cycles: PriceRow[];
    /** Other currencies this product can be bought in; only filled when it has no price in `currency`. */
    sold_in: string[];
    /** The signed-in client's billing currency; null for guests. */
    account_currency: string | null;
    support_url: string | null;
    config_options: ConfigOption[];
    addons: Addon[];
    custom_fields: CustomField[];
    editing: {
        key: string;
        cycle: string | null;
        config_options: Record<string, number>;
        custom_fields: Record<string, string>;
        domain_mode: string | null;
        domain_name: string | null;
        addon_ids: number[];
        tld_attributes: Record<string, string>;
    } | null;
    /** A domain the visitor already owns (from the domain search), preselected as "Use a domain I own". */
    existing_domain: string | null;
    tld_attribute_fields: TldAttributeFieldMap;
};

type DomainMode = '' | 'register' | 'transfer' | 'existing';

type FormData = {
    cycle: string;
    config_options: Record<string, string>;
    custom_fields: Record<string, string>;
    addon_ids: number[];
    domain_mode: DomainMode;
    domain_name: string;
    epp_code: string;
    tld_attributes: Record<string, string>;
};

type Quote = { item: PricedItem; currency: string; subtotal: number };

type PreviewData = {
    cycle: string;
    config_options: Record<string, string>;
    addon_ids: number[];
    domain_mode: string | null;
    domain_name: string | null;
};

const DOMAIN_MODES: {
    value: Exclude<DomainMode, ''>;
    label: string;
    hint: string;
}[] = [
    {
        value: 'register',
        label: 'Register a new domain',
        hint: 'We register it for you.',
    },
    {
        value: 'transfer',
        label: 'Transfer my domain',
        hint: 'Move it from your current registrar.',
    },
    {
        value: 'existing',
        label: 'Use a domain I own',
        hint: "You'll update its nameservers.",
    },
];

function ChoiceCard({
    name,
    value,
    checked,
    onChange,
    children,
}: {
    name: string;
    value: string;
    checked: boolean;
    onChange: (value: string) => void;
    children: ReactNode;
}) {
    return (
        <label
            className={cn(
                'relative flex cursor-pointer flex-col gap-1 rounded-lg border bg-background p-4 transition-colors hover:bg-muted/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                checked && 'border-primary ring-1 ring-primary',
            )}
        >
            <input
                type="radio"
                name={name}
                value={value}
                checked={checked}
                onChange={() => onChange(value)}
                className="sr-only"
            />
            {children}
        </label>
    );
}

function priceFor(rows: PriceRow[], cycle: string): PriceRow | undefined {
    return rows.find((row) => row.cycle === cycle);
}

function initialOptions(
    options: ConfigOption[],
    editing: Props['editing'],
): Record<string, string> {
    const values: Record<string, string> = {};

    for (const option of options) {
        const saved = editing?.config_options[option.id];

        if (saved !== undefined) {
            values[option.id] = String(saved);
        } else if (option.type === 'quantity') {
            values[option.id] = '0';
        } else if (option.type !== 'yes_no' && option.values[0]) {
            values[option.id] = String(option.values[0].id);
        } else {
            values[option.id] = '';
        }
    }

    return values;
}

export default function Configure({
    product,
    currency,
    cycles,
    sold_in: soldIn,
    account_currency: accountCurrency,
    support_url: supportUrl,
    config_options: configOptions,
    addons,
    custom_fields: customFields,
    editing,
    existing_domain: existingDomain,
    tld_attribute_fields: tldAttributeFields,
}: Props) {
    const form = useForm<FormData>({
        cycle: editing?.cycle ?? cycles[0]?.cycle ?? 'monthly',
        config_options: initialOptions(configOptions, editing),
        custom_fields: Object.fromEntries(
            customFields.map((field) => [
                field.id,
                editing?.custom_fields[field.id] ?? '',
            ]),
        ),
        addon_ids: editing?.addon_ids ?? [],
        domain_mode:
            (editing?.domain_mode as DomainMode) ??
            (existingDomain
                ? 'existing'
                : product.requires_domain
                  ? 'register'
                  : ''),
        domain_name: editing?.domain_name ?? existingDomain ?? '',
        epp_code: '',
        tld_attributes: editing?.tld_attributes ?? {},
    });
    const tldFields =
        form.data.domain_mode === 'register'
            ? tldFieldsFor(tldAttributeFields, form.data.domain_name)
            : [];
    const errors = form.errors as Record<string, string | undefined>;

    const preview = useHttp<PreviewData, Quote>({
        cycle: '',
        config_options: {},
        addon_ids: [],
        domain_mode: null,
        domain_name: null,
    });
    const [quote, setQuote] = useState<Quote | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const previewPayload = useMemo<PreviewData>(
        () => ({
            cycle: form.data.cycle,
            config_options: form.data.config_options,
            addon_ids: form.data.addon_ids,
            domain_mode: form.data.domain_mode || null,
            domain_name: form.data.domain_name || null,
        }),
        [
            form.data.cycle,
            form.data.config_options,
            form.data.addon_ids,
            form.data.domain_mode,
            form.data.domain_name,
        ],
    );

    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            preview.transform(() => previewPayload);
            preview
                .post(StoreController.quote.url(product.id))
                .then((response) => setQuote(response))
                .catch(() => setQuote(null));
        }, 250);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [previewPayload, product.id]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            domain_mode: data.domain_mode || null,
            domain_name: data.domain_mode ? data.domain_name : null,
            epp_code: data.domain_mode === 'transfer' ? data.epp_code : null,
            tld_attributes: tldFields.length > 0 ? data.tld_attributes : null,
        }));

        if (editing) {
            form.submit(CartController.updateProduct(editing.key));
        } else {
            form.submit(CartController.storeProduct(product.id));
        }
    };

    const setOption = (id: number, value: string) =>
        form.setData('config_options', {
            ...form.data.config_options,
            [id]: value,
        });

    const toggleAddon = (id: number, checked: boolean) =>
        form.setData(
            'addon_ids',
            checked
                ? [...form.data.addon_ids, id]
                : form.data.addon_ids.filter((addon) => addon !== id),
        );

    const monthlyRow = priceFor(cycles, 'monthly');

    // No price in this currency: say so and offer the way out, with no totals or add-on prices that read as orderable.
    if (cycles.length === 0) {
        return (
            <>
                <Head title={product.name} />

                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8 md:px-6 lg:py-12">
                    <Link
                        href={store.index.url()}
                        className="-ml-1 inline-flex items-center gap-1 self-start rounded-md px-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ChevronLeft className="size-4" aria-hidden="true" />
                        Back to store
                    </Link>
                    <div className="space-y-2">
                        {product.group && (
                            <p className="text-sm font-medium text-muted-foreground">
                                {product.group}
                            </p>
                        )}
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {product.name}
                        </h1>
                    </div>
                    <CurrencyUnavailable
                        title={`${product.name} is not sold in ${currency}`}
                        soldIn={soldIn}
                        accountCurrency={accountCurrency}
                        supportUrl={supportUrl}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Configure ${product.name}`} />

            <form
                onSubmit={submit}
                className="mx-auto grid w-full max-w-6xl gap-8 px-4 py-8 md:px-6 lg:grid-cols-[1fr_22rem] lg:py-12"
            >
                <div className="flex min-w-0 flex-col gap-6">
                    <header className="space-y-2">
                        <Link
                            href={store.index.url()}
                            className="-ml-1 inline-flex items-center gap-1 rounded-md px-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ChevronLeft
                                className="size-4"
                                aria-hidden="true"
                            />
                            Back to store
                        </Link>
                        {product.group && (
                            <p className="text-sm font-medium text-muted-foreground">
                                {product.group}
                            </p>
                        )}
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {product.name}
                        </h1>
                        <p className="text-muted-foreground">
                            {editing
                                ? 'Change your configuration and update the cart.'
                                : 'Choose how you want to pay and set up your plan.'}
                        </p>
                    </header>

                    <InputError message={errors.product_id} />

                    <SectionCard
                        title="Billing cycle"
                        description="Longer terms cost less per month."
                    >
                        <div
                            role="radiogroup"
                            aria-label="Billing cycle"
                            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            {cycles.map((row) => {
                                const months = cycleMonths(row.cycle);
                                const saving =
                                    monthlyRow && months && months > 1
                                        ? Math.round(
                                              (1 -
                                                  row.recurring /
                                                      (monthlyRow.recurring *
                                                          months)) *
                                                  100,
                                          )
                                        : 0;

                                return (
                                    <ChoiceCard
                                        key={row.cycle}
                                        name="cycle"
                                        value={row.cycle}
                                        checked={form.data.cycle === row.cycle}
                                        onChange={(value) =>
                                            form.setData('cycle', value)
                                        }
                                    >
                                        <span className="flex items-center justify-between gap-2 text-sm font-medium">
                                            {cycleLabel(row.cycle)}
                                            {saving > 0 && (
                                                <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                    Save {saving}%
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-lg font-semibold tabular-nums">
                                            <Money
                                                amount={row.recurring}
                                                currency={currency}
                                            />
                                            <span className="text-sm font-normal text-muted-foreground">
                                                {cycleSuffix(row.cycle)}
                                            </span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {row.setup > 0 ? (
                                                <>
                                                    +{' '}
                                                    <Money
                                                        amount={row.setup}
                                                        currency={currency}
                                                    />{' '}
                                                    setup
                                                </>
                                            ) : (
                                                'No setup fee'
                                            )}
                                        </span>
                                    </ChoiceCard>
                                );
                            })}
                        </div>
                        <InputError message={errors.cycle} className="mt-2" />
                    </SectionCard>

                    <SectionCard
                        title="Domain"
                        description={
                            product.requires_domain
                                ? 'This plan needs a domain.'
                                : 'Optional: attach a domain to this plan.'
                        }
                    >
                        <div
                            role="radiogroup"
                            aria-label="Domain option"
                            className="grid gap-3 sm:grid-cols-3"
                        >
                            {DOMAIN_MODES.map((mode) => (
                                <ChoiceCard
                                    key={mode.value}
                                    name="domain_mode"
                                    value={mode.value}
                                    checked={
                                        form.data.domain_mode === mode.value
                                    }
                                    onChange={(value) =>
                                        form.setData(
                                            'domain_mode',
                                            value as DomainMode,
                                        )
                                    }
                                >
                                    <span className="text-sm font-medium">
                                        {mode.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {mode.hint}
                                    </span>
                                </ChoiceCard>
                            ))}
                        </div>
                        {!product.requires_domain &&
                            form.data.domain_mode !== '' && (
                                <Button
                                    type="button"
                                    variant="link"
                                    className="mt-2 h-auto px-0"
                                    onClick={() =>
                                        form.setData('domain_mode', '')
                                    }
                                >
                                    I don't need a domain
                                </Button>
                            )}
                        {form.data.domain_mode !== '' && (
                            <div className="mt-5 grid gap-5 sm:grid-cols-2">
                                <Field
                                    htmlFor="domain_name"
                                    label="Domain name"
                                    error={errors.domain_name}
                                    hint="For example mybusiness.com"
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
                                        placeholder="mybusiness.com"
                                        autoComplete="off"
                                        spellCheck={false}
                                        aria-invalid={!!errors.domain_name}
                                        aria-describedby="domain_name-description"
                                    />
                                </Field>
                                {form.data.domain_mode === 'transfer' && (
                                    <Field
                                        htmlFor="epp_code"
                                        label="Authorization (EPP) code"
                                        error={errors.epp_code}
                                        hint="Ask your current registrar for this code."
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
                                            aria-invalid={!!errors.epp_code}
                                            aria-describedby="epp_code-description"
                                        />
                                    </Field>
                                )}
                                {tldFields.length > 0 && (
                                    <TldAttributeFields
                                        extension={
                                            tldMatch(
                                                tldAttributeFields,
                                                form.data.domain_name,
                                            ) ?? ''
                                        }
                                        onClearError={(name) =>
                                            form.clearErrors(name as never)
                                        }
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
                            </div>
                        )}
                        {form.data.domain_mode === '' && (
                            <InputError
                                message={errors.domain_name}
                                className="mt-2"
                            />
                        )}
                    </SectionCard>

                    {configOptions.length > 0 && (
                        <SectionCard title="Configuration">
                            <div className="grid gap-6 sm:grid-cols-2">
                                {configOptions.map((option) => {
                                    const id = `option-${option.id}`;
                                    const error =
                                        errors[`config_options.${option.id}`];
                                    const value =
                                        form.data.config_options[option.id] ??
                                        '';

                                    if (option.type === 'quantity') {
                                        const unit =
                                            option.values[0] &&
                                            priceFor(
                                                option.values[0].pricing,
                                                form.data.cycle,
                                            );

                                        return (
                                            <Field
                                                key={option.id}
                                                htmlFor={id}
                                                label={option.name}
                                                error={error}
                                                hint={
                                                    unit ? (
                                                        <>
                                                            <Money
                                                                amount={
                                                                    unit.recurring
                                                                }
                                                                currency={
                                                                    currency
                                                                }
                                                            />{' '}
                                                            each
                                                            {cycleSuffix(
                                                                form.data.cycle,
                                                            )}
                                                        </>
                                                    ) : undefined
                                                }
                                            >
                                                <Input
                                                    id={id}
                                                    type="number"
                                                    min={0}
                                                    max={100}
                                                    value={value}
                                                    onChange={(event) =>
                                                        setOption(
                                                            option.id,
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={!!error}
                                                    aria-describedby={`${id}-description`}
                                                />
                                            </Field>
                                        );
                                    }

                                    if (option.type === 'yes_no') {
                                        const yes = option.values[0];

                                        return (
                                            <div
                                                key={option.id}
                                                className="flex items-start gap-3 rounded-lg border p-4"
                                            >
                                                <Checkbox
                                                    id={id}
                                                    checked={value !== ''}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setOption(
                                                            option.id,
                                                            checked && yes
                                                                ? String(yes.id)
                                                                : '',
                                                        )
                                                    }
                                                />
                                                <label
                                                    htmlFor={id}
                                                    className="text-sm font-medium"
                                                >
                                                    {option.name}
                                                </label>
                                            </div>
                                        );
                                    }

                                    return (
                                        <Field
                                            key={option.id}
                                            htmlFor={id}
                                            label={option.name}
                                            error={error}
                                        >
                                            <Select
                                                value={value}
                                                onValueChange={(next) =>
                                                    setOption(option.id, next)
                                                }
                                            >
                                                <SelectTrigger
                                                    id={id}
                                                    className="w-full"
                                                    aria-invalid={!!error}
                                                >
                                                    <SelectValue
                                                        placeholder={`Choose ${option.name.toLowerCase()}`}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {option.values.map(
                                                        (choice) => {
                                                            const price =
                                                                priceFor(
                                                                    choice.pricing,
                                                                    form.data
                                                                        .cycle,
                                                                );

                                                            return (
                                                                <SelectItem
                                                                    key={
                                                                        choice.id
                                                                    }
                                                                    value={String(
                                                                        choice.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        choice.name
                                                                    }
                                                                    {price &&
                                                                        price.recurring >
                                                                            0 && (
                                                                            <span className="text-muted-foreground">
                                                                                {' '}
                                                                                (+
                                                                                <Money
                                                                                    amount={
                                                                                        price.recurring
                                                                                    }
                                                                                    currency={
                                                                                        currency
                                                                                    }
                                                                                />
                                                                                )
                                                                            </span>
                                                                        )}
                                                                </SelectItem>
                                                            );
                                                        },
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    )}

                    {addons.length > 0 && (
                        <SectionCard
                            title="Add-ons"
                            description="Optional extras billed with your plan."
                        >
                            <div className="grid gap-3">
                                {addons.map((addon) => {
                                    const price = priceFor(
                                        addon.pricing,
                                        form.data.cycle,
                                    );
                                    const id = `addon-${addon.id}`;

                                    return (
                                        <label
                                            key={addon.id}
                                            htmlFor={id}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/40',
                                                !price &&
                                                    'cursor-not-allowed opacity-60',
                                            )}
                                        >
                                            <Checkbox
                                                id={id}
                                                disabled={!price}
                                                checked={form.data.addon_ids.includes(
                                                    addon.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleAddon(
                                                        addon.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <span className="grid flex-1 gap-0.5">
                                                <span className="text-sm font-medium">
                                                    {addon.name}
                                                </span>
                                                {addon.description && (
                                                    <span className="text-sm text-muted-foreground">
                                                        {addon.description}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-sm tabular-nums">
                                                {price ? (
                                                    <>
                                                        <Money
                                                            amount={
                                                                price.recurring
                                                            }
                                                            currency={currency}
                                                        />
                                                        <span className="text-muted-foreground">
                                                            {cycleSuffix(
                                                                form.data.cycle,
                                                            )}
                                                        </span>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Not available for this
                                                        cycle
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <InputError
                                message={errors.addon_ids}
                                className="mt-2"
                            />
                        </SectionCard>
                    )}

                    {customFields.length > 0 && (
                        <SectionCard title="Additional information">
                            <div className="grid gap-6 sm:grid-cols-2">
                                {customFields.map((field) => {
                                    const id = `field-${field.id}`;
                                    const error =
                                        errors[`custom_fields.${field.id}`];
                                    const value =
                                        form.data.custom_fields[field.id] ?? '';
                                    const set = (next: string) =>
                                        form.setData('custom_fields', {
                                            ...form.data.custom_fields,
                                            [field.id]: next,
                                        });

                                    return (
                                        <Field
                                            key={field.id}
                                            htmlFor={id}
                                            label={field.name}
                                            error={error}
                                            optional={!field.required}
                                        >
                                            {field.type === 'dropdown' ? (
                                                <Select
                                                    value={value}
                                                    onValueChange={set}
                                                >
                                                    <SelectTrigger
                                                        id={id}
                                                        className="w-full"
                                                        aria-invalid={!!error}
                                                    >
                                                        <SelectValue placeholder="Choose…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {field.options.map(
                                                            (choice) => (
                                                                <SelectItem
                                                                    key={choice}
                                                                    value={
                                                                        choice
                                                                    }
                                                                >
                                                                    {choice}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            ) : field.type === 'textarea' ? (
                                                <Textarea
                                                    id={id}
                                                    value={value}
                                                    onChange={(event) =>
                                                        set(event.target.value)
                                                    }
                                                    aria-invalid={!!error}
                                                />
                                            ) : (
                                                <Input
                                                    id={id}
                                                    type={
                                                        field.type ===
                                                        'password'
                                                            ? 'password'
                                                            : field.type ===
                                                                'link'
                                                              ? 'url'
                                                              : 'text'
                                                    }
                                                    value={value}
                                                    onChange={(event) =>
                                                        set(event.target.value)
                                                    }
                                                    aria-invalid={!!error}
                                                    aria-describedby={`${id}-description`}
                                                />
                                            )}
                                        </Field>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    )}
                </div>

                <aside className="lg:sticky lg:top-24 lg:self-start">
                    <section
                        aria-labelledby="configure-summary"
                        aria-busy={preview.processing || undefined}
                        className="rounded-xl border bg-card text-card-foreground shadow-xs"
                    >
                        <div className="px-5 pt-5">
                            <h2
                                id="configure-summary"
                                className="text-base font-semibold"
                            >
                                Summary
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Billed{' '}
                                {cycleLabel(form.data.cycle).toLowerCase()}
                            </p>
                        </div>
                        <ul
                            className={cn(
                                'grid gap-2 px-5 py-4 text-sm transition-opacity',
                                preview.processing && 'opacity-60',
                            )}
                        >
                            {(quote?.item.lines ?? []).map((line) => (
                                <li key={line.key} className="grid gap-1">
                                    <div className="flex justify-between gap-4">
                                        <span className="min-w-0">
                                            {line.kind === 'product'
                                                ? product.name
                                                : line.description}
                                        </span>
                                        <Money
                                            amount={line.recurring}
                                            currency={currency}
                                        />
                                    </div>
                                    {line.breakdown.slice(1).map((part) => (
                                        <div
                                            key={part.label}
                                            className="flex justify-between gap-4 pl-3 text-xs text-muted-foreground"
                                        >
                                            <span>{part.label}</span>
                                            <Money
                                                amount={part.recurring}
                                                currency={currency}
                                            />
                                        </div>
                                    ))}
                                    {line.setup > 0 && (
                                        <div className="flex justify-between gap-4 pl-3 text-xs text-muted-foreground">
                                            <span>Setup fee</span>
                                            <Money
                                                amount={line.setup}
                                                currency={currency}
                                            />
                                        </div>
                                    )}
                                </li>
                            ))}
                            {!quote && (
                                <li className="text-muted-foreground">
                                    Calculating…
                                </li>
                            )}
                        </ul>
                        {quote &&
                            quote.item.errors.length > 0 &&
                            (form.data.domain_name !== '' ||
                                !product.requires_domain) && (
                                <div className="px-5 pb-3">
                                    {quote.item.errors.map((error) => (
                                        <p
                                            key={error}
                                            className="text-sm text-destructive"
                                        >
                                            {error}
                                        </p>
                                    ))}
                                </div>
                            )}
                        <Separator />
                        <div className="flex items-baseline justify-between gap-4 px-5 py-4">
                            <span className="font-medium">Due today</span>
                            <span className="text-right">
                                <Money
                                    amount={quote?.item.due_today}
                                    currency={currency}
                                    className="text-xl font-semibold"
                                />
                                <span className="block text-xs text-muted-foreground">
                                    excl. tax
                                </span>
                            </span>
                        </div>
                        <div className="border-t px-5 py-4">
                            <Button
                                type="submit"
                                className="w-full"
                                size="lg"
                                disabled={form.processing || !product.in_stock}
                            >
                                {form.processing && <Spinner />}
                                {editing ? 'Update cart' : 'Add to cart'}
                            </Button>
                            {!product.in_stock && (
                                <p className="mt-2 text-center text-sm text-muted-foreground">
                                    This product is out of stock.
                                </p>
                            )}
                        </div>
                    </section>
                </aside>
            </form>
        </>
    );
}
