import { Lock, LockOpen } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { fromMinor, parseMoneyInput } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CurrencyOption, CycleOption, PriceRow } from './types';

/** Editable cell: the text staff typed, parsed strictly only on submit. */
export type PriceCell = {
    enabled: boolean;
    amount: string;
    setup_fee: string;
    /** Set manually: price conversion from the default currency leaves this cell alone. */
    manual?: boolean;
};

export type PriceMatrixValue = Record<string, PriceCell>;

export type CellErrors = Record<
    string,
    { amount?: string; setup_fee?: string }
>;

/**
 * A submitted row. Amounts are minor units when the text parsed; otherwise the raw text is sent
 * unchanged so the server rejects it with a field error (never a "repaired" number).
 */
export type SubmittedPriceRow = {
    currency: string;
    cycle: string;
    enabled: boolean;
    amount: number | string | null;
    setup_fee: number | string;
    manual: boolean;
};

const cellKey = (currency: string, cycle: string) => `${currency}|${cycle}`;

const toDecimal = (minor: number) => fromMinor(minor).toFixed(2);

const EMPTY_CELL: PriceCell = {
    enabled: false,
    amount: '',
    setup_fee: '',
    manual: false,
};

/** Build the editable matrix from stored rows (missing cells start off and empty). */
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
                      setup_fee:
                          row.setup_fee === 0 ? '' : toDecimal(row.setup_fee),
                      manual: row.manual ?? false,
                  }
                : { ...EMPTY_CELL };
        }
    }

    return matrix;
}

/** Whether any stored row carries a setup fee (decides if the setup column starts visible). */
export function hasSetupFees(rows: PriceRow[]): boolean {
    return rows.some((row) => row.setup_fee !== 0);
}

/**
 * Rows to submit: every offered cell plus cells that are off but still hold a price
 * (kept so turning them back on restores it). Empty cells that are off are removed.
 */
export function rowsFromMatrix(
    matrix: PriceMatrixValue,
    cycles?: string[],
): SubmittedPriceRow[] {
    return Object.entries(matrix).flatMap(([key, cell]) => {
        const [currency, cycle] = key.split('|');
        const amountText = cell.amount.trim();
        const setupText = cell.setup_fee.trim();

        if (
            (cycles && !cycles.includes(cycle)) ||
            (!cell.enabled && amountText === '')
        ) {
            return [];
        }

        return [
            {
                currency,
                cycle,
                enabled: cell.enabled,
                amount:
                    amountText === ''
                        ? null
                        : (parseMoneyInput(amountText) ?? amountText),
                setup_fee:
                    setupText === ''
                        ? 0
                        : (parseMoneyInput(setupText) ?? setupText),
                manual: cell.manual ?? false,
            },
        ];
    });
}

/** Map server errors like `prices.3.amount` back to the matrix cell and input. */
export function matrixErrors(
    errors: Record<string, string | undefined>,
    submitted: SubmittedPriceRow[],
    prefix = 'prices',
): CellErrors {
    const result: CellErrors = {};
    const pattern = new RegExp(`^${prefix}\\.(\\d+)\\.(\\w+)$`);

    for (const [key, message] of Object.entries(errors)) {
        const match = key.match(pattern);
        const row = match ? submitted[Number(match[1])] : undefined;

        if (!row || !message) {
            continue;
        }

        const cell = (result[cellKey(row.currency, row.cycle)] ??= {});

        if (match?.[2] === 'setup_fee') {
            cell.setup_fee = message;
        } else {
            cell.amount = message;
        }
    }

    return result;
}

type PricingMatrixProps = {
    value: PriceMatrixValue;
    onChange: (value: PriceMatrixValue) => void;
    currencies: CurrencyOption[];
    cycles: CycleOption[];
    cellErrors?: CellErrors;
    disabled?: boolean;
    /** Show the setup fee input next to each price. */
    showSetupFee?: boolean;
    idPrefix?: string;
};

/**
 * Currency × billing cycle price grid. Each cell: an "offered" switch, the price and
 * (optionally) a setup fee. Inputs keep the typed text; parsing happens on submit.
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
    const update = (
        key: string,
        patch: Partial<PriceCell>,
        isDefaultCurrency = true,
    ) => {
        const current = value[key] ?? EMPTY_CELL;
        const next = { ...current, ...patch };

        // Typing a price in another currency means staff chose it: keep it out of auto-conversion.
        if (
            !isDefaultCurrency &&
            patch.manual === undefined &&
            (patch.amount !== undefined || patch.setup_fee !== undefined)
        ) {
            next.manual = true;
        }

        if (
            patch.amount !== undefined &&
            patch.amount.trim() !== '' &&
            !current.enabled &&
            current.amount.trim() === ''
        ) {
            next.enabled = true;
        }

        onChange({ ...value, [key]: next });
    };

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] border-separate border-spacing-0 text-sm">
                <caption className="sr-only">
                    Prices per billing cycle and currency
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            className="w-32 border-b bg-muted/40 px-4 py-2 text-left text-xs font-medium text-muted-foreground"
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
                                    <span className="ml-1 font-normal">
                                        (default)
                                    </span>
                                )}
                                <span className="ml-2 font-normal">
                                    {showSetupFee
                                        ? 'price · setup fee'
                                        : 'price'}
                                </span>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {cycles.map((cycle) => (
                        <tr key={cycle.value} className="align-top">
                            <th
                                scope="row"
                                className="border-b px-4 py-3 text-left font-medium whitespace-nowrap"
                            >
                                {cycle.label}
                            </th>
                            {currencies.map((currency) => {
                                const key = cellKey(currency.code, cycle.value);
                                const cell = value[key] ?? EMPTY_CELL;
                                const id = `${idPrefix}-${currency.code}-${cycle.value}`;
                                const error = cellErrors[key] ?? {};
                                const name = `${currency.code} ${cycle.label.toLowerCase()}`;
                                const prefix =
                                    currency.prefix.trim() || currency.code;

                                return (
                                    <td
                                        key={currency.code}
                                        className={cn(
                                            'border-b px-3 py-2',
                                            !cell.enabled && 'bg-muted/30',
                                        )}
                                    >
                                        <div className="flex items-center gap-2">
                                            <Switch
                                                checked={cell.enabled}
                                                disabled={disabled}
                                                onCheckedChange={(enabled) =>
                                                    update(key, { enabled })
                                                }
                                                aria-label={`Offer ${name}`}
                                            />
                                            <MoneyInput
                                                id={`${id}-amount`}
                                                label={`${name} price`}
                                                prefix={prefix}
                                                value={cell.amount}
                                                muted={!cell.enabled}
                                                disabled={disabled}
                                                error={error.amount}
                                                onChange={(amount) =>
                                                    update(
                                                        key,
                                                        { amount },
                                                        currency.is_default,
                                                    )
                                                }
                                                className="min-w-24 flex-[3]"
                                            />
                                            {showSetupFee && (
                                                <MoneyInput
                                                    id={`${id}-setup`}
                                                    label={`${name} setup fee`}
                                                    prefix="+"
                                                    value={cell.setup_fee}
                                                    muted={!cell.enabled}
                                                    disabled={disabled}
                                                    error={error.setup_fee}
                                                    onChange={(setup_fee) =>
                                                        update(
                                                            key,
                                                            { setup_fee },
                                                            currency.is_default,
                                                        )
                                                    }
                                                    className="min-w-20 flex-[2]"
                                                />
                                            )}
                                            {!currency.is_default && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 shrink-0 text-muted-foreground aria-pressed:text-foreground"
                                                    disabled={disabled}
                                                    aria-pressed={
                                                        cell.manual ?? false
                                                    }
                                                    aria-label={`Set ${name} manually (don't auto-convert)`}
                                                    title={
                                                        cell.manual
                                                            ? "Set manually: price conversion won't change it"
                                                            : 'Converted from the default currency when prices are converted'
                                                    }
                                                    onClick={() =>
                                                        update(key, {
                                                            manual: !cell.manual,
                                                        })
                                                    }
                                                >
                                                    {cell.manual ? (
                                                        <Lock />
                                                    ) : (
                                                        <LockOpen />
                                                    )}
                                                </Button>
                                            )}
                                        </div>
                                        {(error.amount || error.setup_fee) && (
                                            <InputError
                                                id={`${id}-description`}
                                                className="mt-1 text-xs"
                                                message={
                                                    error.amount ??
                                                    error.setup_fee
                                                }
                                            />
                                        )}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
            {currencies.some((currency) => !currency.is_default) && (
                <p className="flex items-center gap-1.5 px-4 py-2 text-xs text-muted-foreground">
                    <Lock className="size-3.5" aria-hidden="true" />
                    Locked prices keep the amount you set when prices are
                    converted from the default currency. Typing a price in
                    another currency locks it; select the lock to unlock.
                </p>
            )}
        </div>
    );
}

type MoneyInputProps = {
    id: string;
    label: string;
    prefix: string;
    value: string;
    muted?: boolean;
    disabled?: boolean;
    error?: string;
    onChange: (value: string) => void;
    className?: string;
};

function MoneyInput({
    id,
    label,
    prefix,
    value,
    muted,
    disabled,
    error,
    onChange,
    className,
}: MoneyInputProps) {
    const descriptionId = `${id.replace(/-(amount|setup)$/, '')}-description`;

    return (
        <div className={cn('relative', className)}>
            <span
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-xs text-muted-foreground"
            >
                {prefix}
            </span>
            <Input
                id={id}
                aria-label={label}
                inputMode="decimal"
                autoComplete="off"
                value={value}
                disabled={disabled}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? descriptionId : undefined}
                onChange={(event) => onChange(event.target.value)}
                className={cn(
                    'h-8 text-right tabular-nums',
                    prefix.length > 1 ? 'pl-8' : 'pl-6',
                    muted && 'text-muted-foreground',
                )}
            />
        </div>
    );
}
