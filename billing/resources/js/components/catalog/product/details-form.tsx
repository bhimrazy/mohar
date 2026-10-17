import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { SectionCard } from '@/components/app/section-card';
import { SelectInput } from '@/components/catalog/select-input';
import { SwitchRow } from '@/components/catalog/switch-row';
import type { CycleOption, EnumOption } from '@/components/catalog/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { RouteAction } from '@/types/app';

export type ProductEnums = {
    types: EnumOption[];
    paymentTypes: EnumOption[];
    quantityModes: EnumOption[];
    autoSetups: EnumOption[];
    freeDomains: EnumOption[];
    customFieldTypes: EnumOption[];
    recurringCycles: CycleOption[];
};

export type ProductDetails = {
    product_group_id: number | null;
    type: string;
    name: string;
    slug: string;
    tagline: string | null;
    short_description: string | null;
    description: string | null;
    payment_type: string;
    is_hidden: boolean;
    is_retired: boolean;
    is_featured: boolean;
    is_taxable: boolean;
    requires_domain: boolean;
    stock_enabled: boolean;
    stock_quantity: number;
    quantity_mode: string;
    recurring_cycles_limit: number;
    fixed_term_days: number;
    fixed_term_email_key: string | null;
    welcome_email_key: string | null;
    upgrade_email_key: string | null;
    auto_setup: string;
    prorata_enabled: boolean;
    prorata_day: number;
    prorata_charge_next_month_day: number;
    free_domain: string;
    free_domain_cycles: string[];
    free_domain_tlds: string[];
    suspend_days_override: number | null;
    terminate_days_override: number | null;
};

type DetailsFormProps = {
    initial: ProductDetails;
    action: RouteAction;
    groups: { id: number; name: string }[];
    enums: ProductEnums;
    submitLabel: string;
    disabled?: boolean;
    cancel?: ReactNode;
};

const str = (value: string | number | null | undefined) =>
    value === null || value === undefined ? '' : String(value);

/** Product details form shared by "New product" and the edit page's Details tab. */
export function ProductDetailsForm({
    initial,
    action,
    groups,
    enums,
    submitLabel,
    disabled = false,
    cancel,
}: DetailsFormProps) {
    const form = useForm({
        product_group_id: str(initial.product_group_id),
        type: initial.type,
        name: initial.name,
        slug: initial.slug,
        tagline: str(initial.tagline),
        short_description: str(initial.short_description),
        description: str(initial.description),
        payment_type: initial.payment_type,
        is_hidden: initial.is_hidden,
        is_retired: initial.is_retired,
        is_featured: initial.is_featured,
        is_taxable: initial.is_taxable,
        requires_domain: initial.requires_domain,
        stock_enabled: initial.stock_enabled,
        stock_quantity: str(initial.stock_quantity),
        quantity_mode: initial.quantity_mode,
        recurring_cycles_limit: str(initial.recurring_cycles_limit),
        fixed_term_days: str(initial.fixed_term_days),
        fixed_term_email_key: str(initial.fixed_term_email_key),
        welcome_email_key: str(initial.welcome_email_key),
        upgrade_email_key: str(initial.upgrade_email_key),
        auto_setup: initial.auto_setup,
        prorata_enabled: initial.prorata_enabled,
        prorata_day: str(initial.prorata_day),
        prorata_charge_next_month_day: str(
            initial.prorata_charge_next_month_day,
        ),
        free_domain: initial.free_domain,
        free_domain_cycles: initial.free_domain_cycles,
        free_domain_tlds: initial.free_domain_tlds.join(', '),
        suspend_days_override: str(initial.suspend_days_override),
        terminate_days_override: str(initial.terminate_days_override),
    });
    const { data, setData, errors } = form;
    const recurring = data.payment_type === 'recurring';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(action, { preserveScroll: true });
    };

    const text = (
        name: keyof typeof data,
        label: string,
        options: {
            optional?: boolean;
            hint?: string;
            type?: string;
            className?: string;
            mono?: boolean;
        } = {},
    ) => (
        <Field
            htmlFor={name}
            label={label}
            optional={options.optional}
            hint={options.hint}
            error={errors[name]}
            className={options.className}
        >
            <Input
                id={name}
                type={options.type ?? 'text'}
                value={data[name] as string}
                disabled={disabled}
                onChange={(event) => setData(name, event.target.value as never)}
                aria-invalid={!!errors[name]}
                aria-describedby={`${name}-description`}
                className={options.mono ? 'font-mono' : undefined}
            />
        </Field>
    );

    const select = (
        name: keyof typeof data,
        label: string,
        options: EnumOption[],
        hint?: string,
    ) => (
        <Field htmlFor={name} label={label} hint={hint} error={errors[name]}>
            <SelectInput
                id={name}
                value={data[name] as string}
                onChange={(value) => setData(name, value as never)}
                options={options}
                disabled={disabled}
                invalid={!!errors[name]}
            />
        </Field>
    );

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
            disabled={disabled}
            onCheckedChange={(checked) => setData(name, checked as never)}
        />
    );

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <SectionCard title="Product">
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    {select(
                        'product_group_id',
                        'Group',
                        groups.map((group) => ({
                            value: String(group.id),
                            label: group.name,
                        })),
                    )}
                    {select(
                        'type',
                        'Type',
                        enums.types,
                        data.type === 'server'
                            ? 'The cart asks for hostname, root password and nameserver prefixes.'
                            : undefined,
                    )}
                    {text('name', 'Name')}
                    {text('slug', 'Slug', {
                        optional: true,
                        hint: 'Used in the direct cart link. Generated from the name when empty.',
                        mono: true,
                    })}
                    {text('tagline', 'Tagline', {
                        optional: true,
                        className: 'sm:col-span-2',
                    })}
                    <Field
                        htmlFor="short_description"
                        label="Short description"
                        optional
                        error={errors.short_description}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="short_description"
                            value={data.short_description}
                            disabled={disabled}
                            onChange={(event) =>
                                setData('short_description', event.target.value)
                            }
                            aria-describedby="short_description-description"
                        />
                    </Field>
                    <Field
                        htmlFor="description"
                        label="Description"
                        optional
                        hint="Shown on the store's product page."
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="description"
                            rows={4}
                            value={data.description}
                            disabled={disabled}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                            aria-describedby="description-description"
                        />
                    </Field>
                </div>
            </SectionCard>

            <SectionCard
                title="Billing"
                description="Prices are set per currency and cycle on the Pricing tab."
            >
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    {select(
                        'payment_type',
                        'Payment type',
                        enums.paymentTypes,
                        data.payment_type === 'free'
                            ? 'Free services never invoice and have no due date.'
                            : undefined,
                    )}
                    {select(
                        'quantity_mode',
                        'Quantity per order line',
                        enums.quantityModes,
                    )}
                    {recurring &&
                        text('recurring_cycles_limit', 'Stop invoicing after', {
                            type: 'number',
                            hint: 'Number of renewal invoices; 0 = unlimited.',
                        })}
                    {text('fixed_term_days', 'Fixed term (days)', {
                        type: 'number',
                        hint: 'Terminate this many days after sign-up (trials); 0 = off.',
                    })}
                    <div className="grid gap-3 sm:col-span-2">
                        {toggle(
                            'is_taxable',
                            'Taxable',
                            'Apply tax rules to invoice lines for this product.',
                        )}
                        {toggle(
                            'requires_domain',
                            'Requires a domain',
                            'The client must register, transfer or bring a domain when ordering.',
                        )}
                    </div>
                    {recurring && (
                        <div className="grid gap-4 sm:col-span-2">
                            {toggle(
                                'prorata_enabled',
                                'Prorata billing',
                                'Align the first period to a fixed day of the month.',
                            )}
                            {data.prorata_enabled && (
                                <div className="grid items-start gap-6 sm:grid-cols-2">
                                    {text('prorata_day', 'Prorata day', {
                                        type: 'number',
                                        hint: 'Day of the month (1–31).',
                                    })}
                                    {text(
                                        'prorata_charge_next_month_day',
                                        'Charge next month after day',
                                        {
                                            type: 'number',
                                            hint: 'Orders after this day also pay the next full month. 0 = always, 32 = never.',
                                        },
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </SectionCard>

            <SectionCard title="Availability">
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    <div className="grid gap-3 sm:col-span-2">
                        {toggle(
                            'is_hidden',
                            'Hidden',
                            'Not listed in the store; still orderable by direct link.',
                        )}
                        {toggle(
                            'is_retired',
                            'Retired',
                            'Not orderable anywhere and hidden from staff order pickers. Existing services are unaffected.',
                        )}
                        {toggle(
                            'is_featured',
                            'Featured',
                            'Highlighted in the store.',
                        )}
                        {toggle(
                            'stock_enabled',
                            'Limit stock',
                            'Shows “out of stock” and blocks orders at 0.',
                        )}
                    </div>
                    {data.stock_enabled &&
                        text('stock_quantity', 'Quantity in stock', {
                            type: 'number',
                        })}
                </div>
            </SectionCard>

            <SectionCard
                title="Automation"
                description="When services are provisioned and which emails they send."
            >
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    {select('auto_setup', 'Auto-setup', enums.autoSetups)}
                    {text('welcome_email_key', 'Welcome email template', {
                        optional: true,
                        mono: true,
                        hint: 'Template key sent when the service first becomes active.',
                    })}
                    {text('upgrade_email_key', 'Upgrade email template', {
                        optional: true,
                        mono: true,
                    })}
                    {text('fixed_term_email_key', 'Fixed-term email template', {
                        optional: true,
                        mono: true,
                    })}
                    {text(
                        'suspend_days_override',
                        'Suspend after (days overdue)',
                        {
                            optional: true,
                            type: 'number',
                            hint: 'Leave empty to use the global setting.',
                        },
                    )}
                    {text(
                        'terminate_days_override',
                        'Terminate after (days overdue)',
                        {
                            optional: true,
                            type: 'number',
                            hint: 'Leave empty to use the global setting.',
                        },
                    )}
                </div>
            </SectionCard>

            {recurring && (
                <SectionCard
                    title="Free domain"
                    description="Offer a free domain registration with this product."
                >
                    <div className="grid items-start gap-6 sm:grid-cols-2">
                        {select(
                            'free_domain',
                            'Free domain',
                            enums.freeDomains,
                        )}
                        {data.free_domain !== 'none' &&
                            text('free_domain_tlds', 'Eligible TLDs', {
                                hint: 'Comma-separated, e.g. .com, .net',
                                mono: true,
                            })}
                        {data.free_domain !== 'none' && (
                            <fieldset className="grid gap-2 sm:col-span-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Eligible billing cycles
                                </legend>
                                <div className="flex flex-wrap gap-x-6 gap-y-2">
                                    {enums.recurringCycles.map((cycle) => (
                                        <label
                                            key={cycle.value}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={data.free_domain_cycles.includes(
                                                    cycle.value,
                                                )}
                                                disabled={disabled}
                                                onCheckedChange={(checked) =>
                                                    setData(
                                                        'free_domain_cycles',
                                                        checked === true
                                                            ? [
                                                                  ...data.free_domain_cycles,
                                                                  cycle.value,
                                                              ]
                                                            : data.free_domain_cycles.filter(
                                                                  (value) =>
                                                                      value !==
                                                                      cycle.value,
                                                              ),
                                                    )
                                                }
                                            />
                                            {cycle.label}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                    </div>
                </SectionCard>
            )}

            {!disabled && (
                <FormActions sticky>
                    {cancel}
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {submitLabel}
                    </Button>
                </FormActions>
            )}
        </form>
    );
}
