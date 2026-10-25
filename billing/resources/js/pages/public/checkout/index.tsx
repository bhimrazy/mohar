import { Link, useForm, usePage } from '@inertiajs/react';
import { StoreTitle } from '@/components/orders/storefront-hero';
import { Head } from '@/components/app/head';
import {
    AlertTriangle,
    CreditCard,
    Lock,
    LogIn,
    Mail,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import CheckoutController from '@/actions/App/Modules/Orders/Http/Controllers/Public/CheckoutController';
import { CountrySelect } from '@/components/app/country-select';
import { Field } from '@/components/app/field';
import { GatewayLogo } from '@/components/billing/gateway-logo';
import { Money } from '@/components/app/money';
import { SectionCard } from '@/components/app/section-card';
import InputError from '@/components/input-error';
import { ItemDetails } from '@/components/orders/item-details';
import { OrderSummary } from '@/components/orders/order-summary';
import type { GroupSaving } from '@/components/orders/order-summary';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import store from '@/routes/store';
import type { SelectOption } from '@/types/app';
import type { PricedCart } from '@/types/orders';
import type { PaymentMark } from '@/types/storefront';

type Props = {
    cart: PricedCart;
    group_saving: GroupSaving;
    gateways: SelectOption[];
    account: { name: string; email: string; currency: string } | null;
    blocked_reason: string | null;
    require_tos: boolean;
    tos_url: string | null;
    allow_notes: boolean;
    idempotency_key: string;
    default_country: string | null;
    support_email: string | null;
    login_url: string;
};

type CheckoutForm = {
    payment_gateway: string;
    accept_tos: boolean;
    notes: string;
    idempotency_key: string;
    expected_net_total: number;
    first_name: string;
    last_name: string;
    company_name: string;
    email: string;
    country: string;
    password: string;
    password_confirmation: string;
};

export default function Checkout({
    cart,
    group_saving: groupSaving,
    gateways,
    account,
    blocked_reason: blockedReason,
    require_tos: requireTos,
    tos_url: tosUrl,
    allow_notes: allowNotes,
    idempotency_key: idempotencyKey,
    default_country: defaultCountry,
    support_email: supportEmail,
    login_url: loginUrl,
}: Props) {
    // Official logos come from the public layout's accepted-payment marks, matched by gateway key.
    const { site } = usePage<{ site?: { payments?: PaymentMark[] } }>().props;
    const logoFor = (key: string) =>
        site?.payments?.find((mark) => mark.key === key)?.logo ?? null;
    const [mode, setMode] = useState<'register' | 'login'>('register');
    const form = useForm<CheckoutForm>({
        payment_gateway: gateways[0]?.value ?? '',
        accept_tos: false,
        notes: '',
        idempotency_key: idempotencyKey,
        expected_net_total: cart.net_total,
        first_name: '',
        last_name: '',
        company_name: '',
        email: '',
        country: defaultCountry ?? '',
        password: '',
        password_confirmation: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    // Errors about the cart, promotion, domains or a replayed submit have no field on this page.
    const generalError = Object.entries(errors).find(
        ([key, message]) =>
            message &&
            (!(key in form.data) ||
                key === 'idempotency_key' ||
                key === 'expected_net_total'),
    )?.[1];
    const guest = account === null && blockedReason === null;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.submit(CheckoutController.store(), { preserveScroll: true });
    };

    const text = (
        name: keyof CheckoutForm,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
        optional = false,
    ) => (
        <Field
            htmlFor={name}
            label={label}
            error={errors[name]}
            optional={optional}
        >
            <Input
                id={name}
                value={String(form.data[name])}
                onChange={(event) =>
                    form.setData(name, event.target.value as never)
                }
                aria-invalid={!!errors[name]}
                aria-describedby={`${name}-description`}
                {...props}
            />
        </Field>
    );

    return (
        <>
            <Head title="Checkout" />

            <form
                onSubmit={submit}
                className="mx-auto grid w-full max-w-6xl grid-cols-1 gap-8 px-4 py-8 md:px-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:py-12"
            >
                <div className="flex min-w-0 flex-col gap-6">
                    <header>
                        <StoreTitle>Checkout</StoreTitle>
                        <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                            <Lock className="size-3.5" aria-hidden="true" />{' '}
                            Your order is placed securely. You can pay right
                            after.
                        </p>
                    </header>

                    {generalError && (
                        <div
                            role="alert"
                            className="flex gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                        >
                            <AlertTriangle
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="space-y-1">
                                <p>{generalError}</p>
                                <Link
                                    href={store.cart.url()}
                                    className="font-medium underline underline-offset-4"
                                >
                                    Review your cart
                                </Link>
                            </div>
                        </div>
                    )}

                    <SectionCard title="Your account">
                        {account && (
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                                    <UserRound
                                        className="size-5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="min-w-0">
                                    <p className="font-medium [overflow-wrap:anywhere]">
                                        {account.name}
                                    </p>
                                    <p className="text-sm [overflow-wrap:anywhere] text-muted-foreground">
                                        {account.email} · billed in{' '}
                                        {account.currency}
                                    </p>
                                </div>
                            </div>
                        )}

                        {blockedReason && (
                            <p className="text-sm text-muted-foreground">
                                {blockedReason}
                            </p>
                        )}

                        {guest && (
                            <div className="grid gap-6">
                                <div
                                    role="tablist"
                                    aria-label="Account"
                                    className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
                                >
                                    {(['register', 'login'] as const).map(
                                        (tab) => (
                                            <button
                                                key={tab}
                                                type="button"
                                                role="tab"
                                                aria-selected={mode === tab}
                                                onClick={() => setMode(tab)}
                                                className={cn(
                                                    'rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                                    mode === tab &&
                                                        'bg-background text-foreground shadow-xs',
                                                )}
                                            >
                                                {tab === 'register'
                                                    ? 'New customer'
                                                    : 'I have an account'}
                                            </button>
                                        ),
                                    )}
                                </div>

                                {mode === 'register' ? (
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        {text('first_name', 'First name', {
                                            autoComplete: 'given-name',
                                        })}
                                        {text('last_name', 'Last name', {
                                            autoComplete: 'family-name',
                                        })}
                                        {text('email', 'Email address', {
                                            type: 'email',
                                            autoComplete: 'email',
                                        })}
                                        {text(
                                            'company_name',
                                            'Company',
                                            { autoComplete: 'organization' },
                                            true,
                                        )}
                                        <Field
                                            htmlFor="country"
                                            label="Country"
                                            error={errors.country}
                                            optional
                                        >
                                            <CountrySelect
                                                id="country"
                                                value={form.data.country}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'country',
                                                        value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <div className="hidden sm:block" />
                                        {text('password', 'Password', {
                                            type: 'password',
                                            autoComplete: 'new-password',
                                        })}
                                        {text(
                                            'password_confirmation',
                                            'Confirm password',
                                            {
                                                type: 'password',
                                                autoComplete: 'new-password',
                                            },
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-5">
                                        <p className="text-sm text-muted-foreground">
                                            Log in and we'll bring you straight
                                            back here. Your cart is kept.
                                        </p>
                                        <Button asChild variant="outline">
                                            <a href={loginUrl}>
                                                <LogIn /> Log in to continue
                                            </a>
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Payment method"
                        description="You'll pay the invoice after placing the order."
                    >
                        {gateways.length === 0 ? (
                            <p className="text-sm text-destructive">
                                The products in your cart cannot be paid with
                                the same payment method. Order them separately.
                            </p>
                        ) : (
                            <div
                                role="radiogroup"
                                aria-label="Payment method"
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {gateways.map((gateway) => (
                                    <label
                                        key={gateway.value}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-3 rounded-lg border p-4 text-sm font-medium transition-colors hover:bg-muted/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                            form.data.payment_gateway ===
                                                gateway.value &&
                                                'border-primary ring-1 ring-primary',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="payment_gateway"
                                            value={gateway.value}
                                            checked={
                                                form.data.payment_gateway ===
                                                gateway.value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'payment_gateway',
                                                    gateway.value,
                                                )
                                            }
                                            className="size-4 accent-primary"
                                        />
                                        <GatewayLogo
                                            name={gateway.label}
                                            logo={logoFor(gateway.value)}
                                            className="h-7 max-w-24"
                                        />
                                    </label>
                                ))}
                            </div>
                        )}
                        <InputError
                            message={errors.payment_gateway}
                            className="mt-2"
                        />
                    </SectionCard>

                    {allowNotes && (
                        <SectionCard title="Notes">
                            <Field
                                htmlFor="notes"
                                label="Anything we should know?"
                                error={errors.notes}
                                optional
                            >
                                <Textarea
                                    id="notes"
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                        </SectionCard>
                    )}
                </div>

                <aside className="flex min-w-0 flex-col gap-4 lg:sticky lg:top-24 lg:self-start">
                    <section
                        aria-label="Items"
                        className="rounded-xl border bg-card p-5 shadow-xs"
                    >
                        <ul className="grid grid-cols-1 gap-2 text-sm">
                            {cart.items.map((item) => (
                                <li key={item.key}>
                                    <div className="flex justify-between gap-4">
                                        <span className="min-w-0">
                                            <span className="block">
                                                {item.title}
                                            </span>
                                            {(item.domain ?? item.subtitle) && (
                                                <span className="block [overflow-wrap:anywhere] text-muted-foreground">
                                                    {item.domain ??
                                                        item.subtitle}
                                                </span>
                                            )}
                                        </span>
                                        <Money
                                            amount={item.due_today}
                                            currency={cart.currency}
                                            className="shrink-0"
                                        />
                                    </div>
                                    <ItemDetails details={item.details} />
                                </li>
                            ))}
                        </ul>
                        <Link
                            href={store.cart.url()}
                            className="mt-3 inline-block text-sm font-medium underline-offset-4 hover:underline"
                        >
                            Edit cart
                        </Link>
                    </section>

                    <OrderSummary
                        cart={cart}
                        groupSaving={groupSaving}
                        footer={
                            <div className="grid gap-4">
                                {requireTos && (
                                    <div className="grid gap-1.5">
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="accept_tos"
                                                checked={form.data.accept_tos}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'accept_tos',
                                                        checked === true,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.accept_tos
                                                }
                                            />
                                            <label
                                                htmlFor="accept_tos"
                                                className="text-sm leading-snug"
                                            >
                                                I accept the{' '}
                                                {tosUrl ? (
                                                    <a
                                                        href={tosUrl}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="font-medium underline underline-offset-4"
                                                    >
                                                        terms of service
                                                    </a>
                                                ) : (
                                                    'terms of service'
                                                )}
                                            </label>
                                        </div>
                                        <InputError
                                            message={errors.accept_tos}
                                        />
                                    </div>
                                )}
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="w-full"
                                    disabled={
                                        form.processing ||
                                        gateways.length === 0 ||
                                        blockedReason !== null ||
                                        (guest && mode === 'login')
                                    }
                                >
                                    {form.processing && <Spinner />}
                                    Place order
                                </Button>
                                <ul className="grid gap-1.5 text-xs text-muted-foreground">
                                    <li className="flex items-center gap-2">
                                        <ShieldCheck
                                            className="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        Secure, encrypted checkout. You pay the
                                        invoice after ordering.
                                    </li>
                                    {gateways.length > 0 && (
                                        <li className="flex items-center gap-2">
                                            <CreditCard
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Accepted:{' '}
                                            {gateways
                                                .map((gateway) => gateway.label)
                                                .join(', ')}
                                        </li>
                                    )}
                                    {supportEmail && (
                                        <li className="flex items-center gap-2">
                                            <Mail
                                                className="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span>
                                                Questions?{' '}
                                                <a
                                                    href={`mailto:${supportEmail}`}
                                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                                >
                                                    {supportEmail}
                                                </a>
                                            </span>
                                        </li>
                                    )}
                                </ul>
                            </div>
                        }
                    />
                </aside>
            </form>
        </>
    );
}
