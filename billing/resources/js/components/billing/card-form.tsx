import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/components/app/field';
import { Input } from '@/components/ui/input';

export type CardDetails = {
    card_number: string;
    exp_month: string;
    exp_year: string;
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
};

type VaultErrors = Partial<Record<keyof CardDetails, string>>;

/**
 * Sends card details straight to the gateway's vault (sandbox: JSON endpoint) and returns a token.
 * The application's own endpoints only ever receive the token.
 */
export function useTokenizeCard() {
    const { post, transform } = useHttp<
        { card_number: string; exp_month: number; exp_year: number },
        CardToken
    >({ card_number: '', exp_month: 0, exp_year: 0 });

    return async (
        vaultUrl: string,
        card: CardDetails,
    ): Promise<{ token?: CardToken; errors?: VaultErrors }> => {
        transform(() => ({
            card_number: card.card_number.replace(/\s+/g, ''),
            exp_month: Number(card.exp_month),
            exp_year: Number(card.exp_year),
        }));

        let errors: VaultErrors = {};

        try {
            const token = await post(vaultUrl, {
                onError: (fieldErrors) => {
                    errors = fieldErrors as VaultErrors;
                },
            });

            return token ? { token } : { errors };
        } catch {
            return { errors };
        }
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
    const set =
        (key: keyof CardDetails) =>
        (event: React.ChangeEvent<HTMLInputElement>) =>
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
        </div>
    );
}

/** State helper for a card form plus its vault errors. */
export function useCardForm() {
    const [card, setCard] = useState<CardDetails>(emptyCard);
    const [errors, setErrors] = useState<VaultErrors>({});

    return {
        card,
        setCard,
        errors,
        setErrors,
        reset: () => setCard(emptyCard),
    };
}
