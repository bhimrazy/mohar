import { Fragment } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { fromMinor, toMinor } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CurrencyOption, CycleOption, PriceRow } from './types';

/** Editable cell: decimal strings while typing, converted to minor units on submit. */
export type PriceCell = {
    enabled: boolean;
    amount: string;
    setup_fee: string;
};

export type PriceMatrixValue = Record<string, PriceCell>;

const cellKey = (currency: string, cycle: string) => `${currency}|${cycle}`;

const toDecimal = (minor: number) => fromMinor(minor).toFixed(2);

/** Build the editable matrix from stored rows (missing cells start disabled and empty). */
export function matrixFromRows(
    rows: PriceRow[],
    currencies: CurrencyOption[],
    cycles: CycleOption[],
): PriceMatrixValue {
    const matrix: PriceMatrixValue = {};

    for (const currency of currencies) {
        for (const cycle of cycles) {
            const row = rows.find(
                (candidate) =>
                    candidate.currency === currency.code &&
                    candidate.cycle === cycle.value,
            );

            matrix[cellKey(currency.code, cycle.value)] = row
                ? {
                      enabled: row.enabled,
                      amount: toDecimal(row.amount),
                      setup_fee: toDecimal(row.setup_fee),
                  }
                : { enabled: false, amount: '', setup_fee: '' };
        }
    }

    return matrix;
}

type SubmittedRow = {
    currency: string;
    cycle: string;
    enabled: boolean;
    amount: number | null;
    setup_fee: number;
};

/**
 * Rows to submit: every enabled cell plus disabled cells that still hold a price
 * (kept so re-enabling restores it). Empty disabled cells are removed.
 */
export function rowsFromMatrix(matrix: PriceMatrixValue): SubmittedRow[] {
    return Object.entries(matrix).flatMap(([key, cell]) => {
        const [currency, cycle] = key.split('|');
        const hasAmount = cell.amount.trim() !== '';

        if (!cell.enabled && !hasAmount) {
            return [];
        }

        const amount = hasAmount ? toMinor(cell.amount) : NaN;
        const setup =
            cell.setup_fee.trim() === '' ? 0 : toMinor(cell.setup_fee);

        return [
            {
                currency,
                cycle,
                enabled: cell.enabled,
                amount: Number.isNaN(amount) ? null : amount,
                setup_fee: Number.isNaN(setup) ? 0 : setup,
            },
        ];
    });
}

/** Map server errors like `prices.3.amount` back to matrix cells. */
export function matrixErrors(
    errors: Record<string, string | undefined>,
    submitted: SubmittedRow[],
    prefix = 'prices',
): Record<string, string> {
    const result: Record<string, string> = {};

    for (const [key, message] of Object.entries(errors)) {
        const match = key.match(new RegExp(`^${prefix}\\.(\\d+)\\.`));
        const row = match ? submitted[Number(match[1])] : undefined;

        if (row && message) {
            result[cellKey(row.currency, row.cycle)] = message;
        }
    }

    return result;
}

type PricingMatrixProps = {
    value: PriceMatrixValue;
    onChange: (value: PriceMatrixValue) => void;
    currencies: CurrencyOption[];
    cycles: CycleOption[];
    cellErrors?: Record<string, string>;
    disabled?: boolean;
    /** Hide the setup fee inputs (e.g. not used). */
    showSetupFee?: boolean;
    idPrefix?: string;
};

/**
 * Currency × billing cycle price grid. Each cell has an enable switch, a
 * price and a setup fee (decimal inputs; the backend stores minor units).
 */
export function PricingMatrix({
    value,
    onChange,
    currencies,
    cycles,
    cellErrors = {},
    disabled = false,
    showSetupFee = true,
    idPrefix = 'price',
}: PricingMatrixProps) {
    const update = (key: string, patch: Partial<PriceCell>) => {
        const current = value[key] ?? {
            enabled: false,
            amount: '',
            setup_fee: '',
        };
        const next = { ...current, ...patch };

        if (
            patch.amount !== undefined &&
            patch.amount !== '' &&
            !current.enabled &&
            current.amount === ''
        ) {
            next.enabled = true;
        }

        onChange({ ...value, [key]: next });
    };

    return (
        <div className="-mx-4 overflow-x-auto sm:mx-0">
            <table className="w-full min-w-[36rem] border-separate border-spacing-0 text-sm">
                <caption className="sr-only">
                    Prices per billing cycle and currency
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            className="w-36 border-b bg-muted/40 px-4 py-2 text-left text-xs font-medium text-muted-foreground"
                        >
                            Billing cycle
                        </th>
                        {currencies.map((currency) => (
                            <th
                                key={currency.code}
                                scope="col"
                                className="border-b bg-muted/40 px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                            >
                                {currency.code}
                                {currency.is_default && (
                                    <span className="ml-1.5 font-normal">
                                        (default)
                                    </span>
                                )}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {cycles.map((cycle) => (
                        <tr key={cycle.value} className="align-top">
                            <th
                                scope="row"
                                className="border-b px-4 py-3 text-left font-medium"
                            >
                                {cycle.label}
                            </th>
                            {currencies.map((currency) => {
                                const key = cellKey(currency.code, cycle.value);
                                const cell = value[key] ?? {
                                    enabled: false,
                                    amount: '',
                                    setup_fee: '',
                                };
                                const id = `${idPrefix}-${currency.code}-${cycle.value}`;
                                const error = cellErrors[key];

                                return (
                                    <td
                                        key={currency.code}
                                        className={cn(
                                            'border-b px-3 py-3',
                                            !cell.enabled && 'bg-muted/30',
                                        )}
                                    >
                                        <div className="grid gap-2">
                                            <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                                <Switch
                                                    checked={cell.enabled}
                                                    disabled={disabled}
                                                    onCheckedChange={(
                                                        enabled,
                                                    ) =>
                                                        update(key, { enabled })
                                                    }
                                                    aria-label={`Offer ${cycle.label.toLowerCase()} in ${currency.code}`}
                                                />
                                                {cell.enabled
                                                    ? 'Offered'
                                                    : 'Off'}
                                            </label>
                                            <PriceInput
                                                id={`${id}-amount`}
                                                label="Price"
                                                prefix={
                                                    currency.prefix ||
                                                    currency.code
                                                }
                                                value={cell.amount}
                                                disabled={disabled}
                                                invalid={!!error}
                                                onChange={(amount) =>
                                                    update(key, { amount })
                                                }
                                            />
                                            {showSetupFee && (
                                                <PriceInput
                                                    id={`${id}-setup`}
                                                    label="Setup"
                                                    prefix={
                                                        currency.prefix ||
                                                        currency.code
                                                    }
                                                    value={cell.setup_fee}
                                                    disabled={disabled}
                                                    onChange={(setup_fee) =>
                                                        update(key, {
                                                            setup_fee,
                                                        })
                                                    }
                                                />
                                            )}
                                            {error && (
                                                <InputError message={error} />
                                            )}
                                        </div>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

type PriceInputProps = {
    id: string;
    label: string;
    prefix: string;
    value: string;
    disabled?: boolean;
    invalid?: boolean;
    onChange: (value: string) => void;
};

function PriceInput({
    id,
    label,
    prefix,
    value,
    disabled,
    invalid,
    onChange,
}: PriceInputProps) {
    return (
        <Fragment>
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <div className="flex items-center gap-2">
                <span className="w-10 shrink-0 text-xs text-muted-foreground">
                    {label}
                </span>
                <div className="relative w-full">
                    <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-xs text-muted-foreground">
                        {prefix}
                    </span>
                    <Input
                        id={id}
                        inputMode="decimal"
                        autoComplete="off"
                        placeholder="0.00"
                        value={value}
                        disabled={disabled}
                        aria-invalid={invalid || undefined}
                        onChange={(event) => onChange(event.target.value)}
                        className="h-8 pl-8 text-right tabular-nums"
                    />
                </div>
            </div>
        </Fragment>
    );
}
