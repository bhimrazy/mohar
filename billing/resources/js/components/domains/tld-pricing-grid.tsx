import { useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import TldController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/TldController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { SectionCard } from '@/components/app/section-card';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { fromMinor, labelWithCurrency, parseMoneyInput } from '@/lib/money';

export type PriceRow = {
    id: number;
    currency: string;
    client_group_id: number | null;
    years: number;
    register: number | null;
    transfer: number | null;
    renew: number | null;
    is_manual?: boolean;
    enabled?: boolean;
};

type GridRow = {
    years: number;
    register: string;
    transfer: string;
    renew: string;
};

type Action = 'register' | 'transfer' | 'renew';

const ACTIONS: Action[] = ['register', 'transfer', 'renew'];
const BASE = 'base';

type TldPricingGridProps = {
    tld: {
        id: number;
        extension: string;
        min_years: number;
        max_years: number;
    };
    prices: PriceRow[];
    currencies: string[];
    /** Prices in other currencies can be converted from this one (Currencies → Converted prices). */
    defaultCurrency: string;
    groups: { id: number; name: string }[];
    canManage: boolean;
};

const display = (amount: number | null) =>
    amount === null ? '' : fromMinor(amount).toFixed(2);

/** Empty → not offered (null); otherwise the strict parser's minor units, or 'invalid'. */
function parseCell(value: string): number | null | 'invalid' {
    if (value.trim() === '') {
        return null;
    }

    return parseMoneyInput(value) ?? 'invalid';
}

function cellError(row: GridRow, action: Action): string | null {
    const parsed = parseCell(row[action]);

    if (parsed === 'invalid') {
        return 'Enter an amount like 1,299.00';
    }

    if (parsed !== null && parsed < 0) {
        return 'Amounts cannot be negative';
    }

    if (action !== 'transfer' && parsed === 0) {
        return 'Use an amount above zero, or leave it empty';
    }

    if (
        action === 'renew' &&
        parsed === null &&
        parseCell(row.register) !== null
    ) {
        return 'Every registrable term needs a renewal price';
    }

    return null;
}

/**
 * Price grid for one TLD × currency × client-group slab. Input is parsed strictly (US or Indian
 * grouping, ≤ 2 decimals); invalid cells block saving and switching away from unsaved edits asks first.
 */
export function TldPricingGrid({
    tld,
    prices,
    currencies,
    defaultCurrency,
    groups,
    canManage,
}: TldPricingGridProps) {
    const [currency, setCurrency] = useState(currencies[0] ?? 'USD');
    const [group, setGroup] = useState<string>(BASE);
    const [pendingSwitch, setPendingSwitch] = useState<{
        currency: string;
        group: string;
    } | null>(null);
    const groupId = group === BASE ? null : Number(group);

    const rowsFor = (
        selectedCurrency: string,
        selectedGroup: number | null,
    ): GridRow[] =>
        Array.from(
            { length: tld.max_years - tld.min_years + 1 },
            (_, index) => {
                const years = tld.min_years + index;
                const existing = prices.find(
                    (price) =>
                        price.currency === selectedCurrency &&
                        price.client_group_id === selectedGroup &&
                        price.years === years,
                );

                return {
                    years,
                    register: display(existing?.register ?? null),
                    transfer: display(existing?.transfer ?? null),
                    renew: display(existing?.renew ?? null),
                };
            },
        );

    const manualFor = (
        selectedCurrency: string,
        selectedGroup: number | null,
    ): boolean =>
        prices.some(
            (price) =>
                price.currency === selectedCurrency &&
                price.client_group_id === selectedGroup &&
                price.is_manual === true,
        );

    const form = useForm({
        rows: rowsFor(currency, groupId),
        manual: manualFor(currency, groupId),
    });
    const convertible = currency !== defaultCurrency;
    const turnedOff = prices.some(
        (price) =>
            price.currency === currency &&
            price.client_group_id === groupId &&
            price.enabled === false,
    );
    const serverErrors = form.errors as Record<string, string | undefined>;
    const invalid = form.data.rows.some((row) =>
        ACTIONS.some((action) => cellError(row, action) !== null),
    );

    const configuredTerms = (code: string, slab: number | null) =>
        prices.filter(
            (price) =>
                price.currency === code &&
                price.client_group_id === slab &&
                price.register !== null,
        ).length;
    const totalTerms = tld.max_years - tld.min_years + 1;

    const applySwitch = (nextCurrency: string, nextGroup: string) => {
        setCurrency(nextCurrency);
        setGroup(nextGroup);
        const nextGroupId = nextGroup === BASE ? null : Number(nextGroup);
        const data = {
            rows: rowsFor(nextCurrency, nextGroupId),
            manual: manualFor(nextCurrency, nextGroupId),
        };
        form.setDefaults(data);
        form.setData(data);
        form.clearErrors();
    };

    const requestSwitch = (nextCurrency: string, nextGroup: string) => {
        if (form.isDirty) {
            setPendingSwitch({ currency: nextCurrency, group: nextGroup });

            return;
        }

        applySwitch(nextCurrency, nextGroup);
    };

    // Typing a price in another currency means staff chose it: keep it out of auto-conversion.
    const setCell = (index: number, action: Action, value: string) =>
        form.setData((data) => ({
            rows: data.rows.map((row, i) =>
                i === index ? { ...row, [action]: value } : row,
            ),
            manual: data.manual || convertible,
        }));

    const normaliseCell = (index: number, action: Action) => {
        const parsed = parseCell(form.data.rows[index][action]);

        if (typeof parsed === 'number') {
            setCell(index, action, display(parsed));
        }
    };

    const first = form.data.rows[0];
    const firstRegister = first ? parseCell(first.register) : null;
    const firstRenew = first ? parseCell(first.renew) : null;
    const canFill =
        typeof firstRegister === 'number' &&
        firstRegister > 0 &&
        typeof firstRenew === 'number' &&
        firstRenew > 0;

    const fillFromFirstYear = () => {
        if (!canFill || !first) {
            return;
        }

        form.setData(
            'rows',
            form.data.rows.map((row, index) =>
                index === 0
                    ? row
                    : {
                          years: row.years,
                          register: display(
                              Math.round(
                                  (firstRegister * row.years) / first.years,
                              ),
                          ),
                          transfer: '',
                          renew: display(
                              Math.round(
                                  (firstRenew * row.years) / first.years,
                              ),
                          ),
                      },
            ),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (invalid) {
            return;
        }

        form.transform((data) => ({
            currency,
            client_group_id: groupId,
            ...(convertible ? { manual: data.manual } : {}),
            prices: data.rows.map((row) => ({
                years: row.years,
                register: parseCell(row.register),
                transfer: parseCell(row.transfer),
                renew: parseCell(row.renew),
            })),
        }));
        form.submit(TldController.prices(tld.id), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Prices"
                description="Leave a term empty when it isn't offered. Client-group slabs override the base price; transfers sell the lowest term only."
                flush
                footer={
                    canManage && (
                        <>
                            {invalid && (
                                <span className="mr-auto inline-flex items-center gap-1.5 text-sm text-destructive">
                                    <AlertTriangle
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Fix the highlighted prices to save.
                                </span>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={fillFromFirstYear}
                                disabled={!canFill}
                            >
                                Fill years from 1-year price
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || !form.isDirty || invalid
                                }
                            >
                                {form.processing && <Spinner />}
                                Save prices
                            </Button>
                        </>
                    )
                }
            >
                <div className="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:px-6">
                    <Select
                        value={currency}
                        onValueChange={(value) => requestSwitch(value, group)}
                    >
                        <SelectTrigger
                            className="sm:w-48"
                            aria-label="Currency"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {currencies.map((code) => (
                                <SelectItem key={code} value={code}>
                                    {code} · {configuredTerms(code, groupId)}/
                                    {totalTerms} terms
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={group}
                        onValueChange={(value) =>
                            requestSwitch(currency, value)
                        }
                    >
                        <SelectTrigger
                            className="sm:w-64"
                            aria-label="Client group"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={BASE}>
                                Base price (all clients)
                            </SelectItem>
                            {groups.map((item) => (
                                <SelectItem
                                    key={item.id}
                                    value={String(item.id)}
                                >
                                    {item.name} ·{' '}
                                    {configuredTerms(currency, item.id)}/
                                    {totalTerms} terms
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {serverErrors.prices && (
                    <p
                        role="alert"
                        className="border-b bg-destructive/10 px-4 py-2 text-sm text-destructive sm:px-6"
                    >
                        {serverErrors.prices}
                    </p>
                )}
                {turnedOff && (
                    <p className="border-b bg-warning-subtle px-4 py-2 text-sm text-warning-text sm:px-6">
                        Some of these prices are turned off because their{' '}
                        {defaultCurrency} price was removed. Save the grid to
                        offer them again.
                    </p>
                )}
                {convertible && (
                    <div className="flex items-center justify-between gap-4 border-b px-4 py-3 sm:px-6">
                        <div className="min-w-0">
                            <label
                                htmlFor="tld-prices-manual"
                                className="text-sm font-medium"
                            >
                                Set manually — don't auto-convert
                            </label>
                            <p className="text-sm text-muted-foreground">
                                When off, converting prices recalculates this
                                grid from the {defaultCurrency} price.
                            </p>
                        </div>
                        <Switch
                            id="tld-prices-manual"
                            checked={form.data.manual}
                            disabled={!canManage}
                            onCheckedChange={(manual) =>
                                form.setData('manual', manual)
                            }
                        />
                    </div>
                )}
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader className="bg-muted/40">
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="h-9 pl-4 text-xs sm:pl-6">
                                    Term
                                </TableHead>
                                {ACTIONS.map((action) => (
                                    <TableHead
                                        key={action}
                                        className="h-9 text-right text-xs capitalize last:pr-4 sm:last:pr-6"
                                    >
                                        {labelWithCurrency(action, currency)}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {form.data.rows.map((row, index) => (
                                <TableRow key={row.years}>
                                    <TableCell className="pl-4 font-medium sm:pl-6">
                                        {row.years} year
                                        {row.years === 1 ? '' : 's'}
                                    </TableCell>
                                    {ACTIONS.map((action) => {
                                        const error =
                                            cellError(row, action) ??
                                            serverErrors[
                                                `prices.${index}.${action}`
                                            ];
                                        const id = `price-${row.years}-${action}`;

                                        return (
                                            <TableCell
                                                key={action}
                                                className="py-1.5 align-top last:pr-4 sm:last:pr-6"
                                            >
                                                <Input
                                                    id={id}
                                                    aria-label={`${action} price for ${row.years} year${row.years === 1 ? '' : 's'}`}
                                                    inputMode="decimal"
                                                    className="ml-auto w-32 text-right tabular-nums"
                                                    value={row[action]}
                                                    disabled={!canManage}
                                                    aria-invalid={!!error}
                                                    aria-describedby={
                                                        error
                                                            ? `${id}-error`
                                                            : undefined
                                                    }
                                                    placeholder="—"
                                                    onChange={(event) =>
                                                        setCell(
                                                            index,
                                                            action,
                                                            event.target.value,
                                                        )
                                                    }
                                                    onBlur={() =>
                                                        normaliseCell(
                                                            index,
                                                            action,
                                                        )
                                                    }
                                                />
                                                {error && (
                                                    <p
                                                        id={`${id}-error`}
                                                        className="mt-1 max-w-32 text-right text-xs text-destructive sm:ml-auto"
                                                    >
                                                        {error}
                                                    </p>
                                                )}
                                            </TableCell>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </SectionCard>

            <ConfirmDialog
                open={pendingSwitch !== null}
                onOpenChange={(open) => !open && setPendingSwitch(null)}
                title="Discard unsaved price changes?"
                description={`Your edits to the ${currency} ${group === BASE ? 'base' : 'group'} prices haven't been saved. Save them first, or discard them to switch.`}
                confirmLabel="Discard changes"
                onConfirm={() => {
                    if (pendingSwitch) {
                        applySwitch(
                            pendingSwitch.currency,
                            pendingSwitch.group,
                        );
                    }

                    setPendingSwitch(null);
                }}
            />
        </form>
    );
}
