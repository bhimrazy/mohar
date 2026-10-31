import { router, usePage } from '@inertiajs/react';
import { CreditCard, Landmark, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/app/money';
import { StatusBadge } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { RouteAction } from '@/types/app';
import { CardFields, useCardForm, useTokenizeCard } from './card-form';
import { GatewayLogo } from './gateway-logo';
import type { PaymentOptions } from './types';

type PayPanelProps = {
    options: PaymentOptions;
    amount: number;
    currency: string;
    action: RouteAction;
};

const NEW_CARD = 'new';

function Choice({
    name,
    value,
    checked,
    onChange,
    children,
    disabledReason,
}: {
    name: string;
    value: string;
    checked: boolean;
    onChange: (value: string) => void;
    children: React.ReactNode;
    /** The option can't be chosen; the reason is shown under it. */
    disabledReason?: string | null;
}) {
    const id = `${name}-${value}`;
    const hintId = `${id}-hint`;
    const disabled = !!disabledReason;

    return (
        <label
            htmlFor={id}
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border p-3 text-sm transition-colors',
                disabled
                    ? 'cursor-not-allowed bg-muted/20'
                    : 'cursor-pointer hover:bg-muted/40',
                checked && 'border-foreground/40 bg-muted/40',
            )}
        >
            <input
                id={id}
                type="radio"
                name={name}
                value={value}
                checked={checked}
                disabled={disabled}
                aria-describedby={disabled ? hintId : undefined}
                onChange={() => onChange(value)}
                className="size-4 accent-primary"
            />
            <span
                className={cn(
                    'flex flex-1 items-center gap-3',
                    disabled && 'opacity-60',
                )}
            >
                {children}
            </span>
            {disabled && (
                <span
                    id={hintId}
                    className="basis-full pl-7 text-xs text-muted-foreground"
                >
                    {disabledReason}
                </span>
            )}
        </label>
    );
}

/**
 * Client payment: choose a gateway, then a saved card or a new card (tokenised at the gateway).
 * Offline gateways show their instructions instead.
 */
export function PayPanel({ options, amount, currency, action }: PayPanelProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [gateway, setGateway] = useState(
        options.selected_gateway ?? options.gateways[0]?.key ?? '',
    );
    const selected = options.gateways.find((option) => option.key === gateway);
    const methods = options.pay_methods.filter(
        (method) => method.gateway === gateway,
    );
    const [method, setMethod] = useState<string>(
        String(
            methods.find((item) => item.is_default)?.id ??
                methods[0]?.id ??
                NEW_CARD,
        ),
    );
    const card = useCardForm();
    const tokenizeCard = useTokenizeCard();
    const [saveCard, setSaveCard] = useState(true);
    const [processing, setProcessing] = useState(false);

    const effectiveMethod = methods.some((item) => String(item.id) === method)
        ? method
        : NEW_CARD;

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();

        if (!selected || selected.kind === 'offline') {
            return;
        }

        setProcessing(true);

        if (selected.kind === 'redirect') {
            // Success leaves the site; on an error the page reloads with the same method still chosen, so the
            // message appears next to it.
            router.visit(action.url, {
                method: action.method,
                data: { gateway },
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            });

            return;
        }

        let data: Record<string, string | number | boolean> = {
            gateway,
            pay_method_id: Number(effectiveMethod),
        };

        if (effectiveMethod === NEW_CARD) {
            const result = await tokenizeCard(
                selected.vault_url ?? '',
                card.card,
            );
            card.setErrors(result.errors ?? {});

            if (!result.token) {
                setProcessing(false);

                return;
            }

            data = { gateway, token: result.token.token, save_card: saveCard };
        }

        router.visit(action.url, {
            method: action.method,
            data,
            preserveScroll: true,
            onSuccess: () => card.reset(),
            onFinish: () => setProcessing(false),
        });
    };

    if (options.gateways.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Online payment isn't available right now. Contact us to pay this
                invoice.
            </p>
        );
    }

    return (
        <form onSubmit={submit} className="grid gap-4">
            {options.gateways.length > 1 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Payment method
                    </legend>
                    {options.gateways.map((option) => (
                        <Choice
                            key={option.key}
                            name="gateway"
                            value={option.key}
                            checked={gateway === option.key}
                            onChange={setGateway}
                            disabledReason={option.unavailable_reason}
                        >
                            {option.logo ? (
                                <>
                                    <GatewayLogo
                                        name={option.name}
                                        logo={option.logo}
                                        decorative={option.show_name}
                                    />
                                    {option.show_name && option.name}
                                </>
                            ) : (
                                <>
                                    {option.kind === 'redirect' ? (
                                        <Wallet
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    ) : option.kind === 'card' ? (
                                        <CreditCard
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Landmark
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {option.name}
                                </>
                            )}
                            {option.sandbox && (
                                <StatusBadge
                                    status="sandbox"
                                    tone="warning"
                                    label="Sandbox"
                                    className="ml-auto"
                                />
                            )}
                        </Choice>
                    ))}
                </fieldset>
            )}

            {selected?.kind === 'offline' && (
                <div className="rounded-lg border bg-muted/40 p-4 text-sm whitespace-pre-line">
                    {selected.instructions ??
                        'Follow the payment instructions you received. We record the payment when it arrives.'}
                </div>
            )}

            {selected?.kind === 'redirect' && (
                <>
                    {options.gateways.length === 1 && (
                        <div className="flex items-center gap-3">
                            <GatewayLogo
                                name={selected.name}
                                logo={selected.logo}
                                className="font-medium"
                            />
                            {selected.sandbox && (
                                <StatusBadge
                                    status="sandbox"
                                    tone="warning"
                                    label="Sandbox"
                                />
                            )}
                        </div>
                    )}
                    {selected.unavailable_reason ? (
                        <p className="text-sm text-muted-foreground">
                            {selected.unavailable_reason} Choose another way to
                            pay.
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            You’ll be taken to {selected.provider} to complete
                            the payment, then brought back here.
                        </p>
                    )}
                    {selected.conversion && (
                        <p className="rounded-lg border bg-muted/40 p-3 text-sm">
                            {selected.conversion.label}
                        </p>
                    )}
                    <InputError message={errors.payment ?? errors.billing} />
                    <Button
                        type="submit"
                        disabled={processing || !!selected.unavailable_reason}
                        className="w-full sm:w-auto sm:justify-self-end"
                    >
                        {processing && <Spinner />}
                        Continue to {selected.provider}
                    </Button>
                </>
            )}

            {selected?.kind === 'card' && (
                <>
                    {methods.length > 0 && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                Card
                            </legend>
                            {methods.map((item) => (
                                <Choice
                                    key={item.id}
                                    name="pay_method"
                                    value={String(item.id)}
                                    checked={
                                        effectiveMethod === String(item.id)
                                    }
                                    onChange={setMethod}
                                >
                                    <span className="flex-1">{item.label}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.expiry}
                                    </span>
                                </Choice>
                            ))}
                            <Choice
                                name="pay_method"
                                value={NEW_CARD}
                                checked={effectiveMethod === NEW_CARD}
                                onChange={setMethod}
                            >
                                Use a new card
                            </Choice>
                        </fieldset>
                    )}

                    {effectiveMethod === NEW_CARD && (
                        <div className="grid gap-4">
                            <CardFields
                                value={card.card}
                                onChange={card.setCard}
                                errors={card.errors}
                            />
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="save-card"
                                    checked={saveCard}
                                    onCheckedChange={(checked) =>
                                        setSaveCard(checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="save-card"
                                    className="font-normal"
                                >
                                    Save this card for future payments
                                </Label>
                            </div>
                        </div>
                    )}

                    <InputError
                        message={
                            errors.payment ??
                            errors.billing ??
                            errors.token ??
                            errors.pay_method_id
                        }
                    />

                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full sm:w-auto sm:justify-self-end"
                    >
                        {processing && <Spinner />}
                        Pay <Money amount={amount} currency={currency} />
                    </Button>
                </>
            )}
        </form>
    );
}
