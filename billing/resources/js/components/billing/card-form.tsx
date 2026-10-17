import { useState } from 'react';
import { Field } from '@/components/app/field';
import { Input } from '@/components/ui/input';

export type CardDetails = {
    card_number: string;
    exp_month: string;
    exp_year: string;
    cvc: string;
};

export type CardToken = {
    token: string;
    brand: string;
    last4: string;
};

export const emptyCard: CardDetails = {
    card_number: '',
    exp_month: '',
    exp_year: '',
    cvc: '',
};

type VaultErrors = Partial<Record<keyof CardDetails, string>>;

/**
 * Sends card details straight to the gateway's vault (sandbox: JSON endpoint) and returns a token.
 * The application's own endpoints only ever receive the token.
 */
export async function tokenizeCard(
    vaultUrl: string,
    card: CardDetails,
): Promise<{ token?: CardToken; errors?: VaultErrors }> {
    const response = await fetch(vaultUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            card_number: card.card_number.replace(/\s+/g, ''),
            exp_month: Number(card.exp_month),
            exp_year: Number(card.exp_year),
            cvc: card.cvc,
        }),
    });

    if (response.ok) {
        return { token: (await response.json()) as CardToken };
    }

    const body = (await response.json().catch(() => ({}))) as {
        errors?: Record<string, string[]>;
    };

    return {
        errors: Object.fromEntries(
            Object.entries(body.errors ?? {}).map(([key, messages]) => [
                key,
                messages[0],
            ]),
        ) as VaultErrors,
    };
}

type CardFieldsProps = {
    value: CardDetails;
    onChange: (value: CardDetails) => void;
    errors?: VaultErrors;
    idPrefix?: string;
};

export function CardFields({
    value,
    onChange,
    errors = {},
    idPrefix = 'card',
}: CardFieldsProps) {
    const set = (key: keyof CardDetails) => (event: React.ChangeEvent<HTMLInputElement>) =>
        onChange({ ...value, [key]: event.target.value });

    return (
        <div className="grid gap-4 sm:grid-cols-4">
            <Field
                htmlFor={`${idPrefix}-number`}
                label="Card number"
                error={errors.card_number}
                className="sm:col-span-4"
            >
                <Input
                    id={`${idPrefix}-number`}
                    inputMode="numeric"
                    autoComplete="cc-number"
                    placeholder="4242 4242 4242 4242"
                    value={value.card_number}
                    onChange={set('card_number')}
                    aria-invalid={!!errors.card_number}
                    aria-describedby={`${idPrefix}-number-description`}
                />
            </Field>
            <Field
                htmlFor={`${idPrefix}-month`}
                label="Month"
                error={errors.exp_month}
            >
                <Input
                    id={`${idPrefix}-month`}
                    inputMode="numeric"
                    autoComplete="cc-exp-month"
                    placeholder="MM"
                    maxLength={2}
                    value={value.exp_month}
                    onChange={set('exp_month')}
                    aria-invalid={!!errors.exp_month}
                />
            </Field>
            <Field
                htmlFor={`${idPrefix}-year`}
                label="Year"
                error={errors.exp_year}
            >
                <Input
                    id={`${idPrefix}-year`}
                    inputMode="numeric"
                    autoComplete="cc-exp-year"
                    placeholder="YYYY"
                    maxLength={4}
                    value={value.exp_year}
                    onChange={set('exp_year')}
                    aria-invalid={!!errors.exp_year}
                />
            </Field>
            <Field
                htmlFor={`${idPrefix}-cvc`}
                label="CVC"
                error={errors.cvc}
                className="sm:col-span-2"
            >
                <Input
                    id={`${idPrefix}-cvc`}
                    inputMode="numeric"
                    autoComplete="cc-csc"
                    placeholder="123"
                    maxLength={4}
                    value={value.cvc}
                    onChange={set('cvc')}
                    aria-invalid={!!errors.cvc}
                />
            </Field>
        </div>
    );
}

/** State helper for a card form plus its vault errors. */
export function useCardForm() {
    const [card, setCard] = useState<CardDetails>(emptyCard);
    const [errors, setErrors] = useState<VaultErrors>({});

    return { card, setCard, errors, setErrors, reset: () => setCard(emptyCard) };
}
