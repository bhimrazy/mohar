import type { SelectOption } from '@/types/app';

/** Backend enum option: `{ value, label }`. */
export type EnumOption = SelectOption;

export type CycleOption = {
    value: string;
    label: string;
    months: number | null;
};

export type CurrencyOption = {
    code: string;
    name: string;
    prefix: string;
    is_default: boolean;
};

/** One price row as stored: amounts in integer minor units. */
export type PriceRow = {
    currency: string;
    cycle: string;
    enabled: boolean;
    amount: number;
    setup_fee: number;
};

export type ProductChoice = {
    id: number;
    name: string;
    group: string;
    status?: string;
};

export type KeyValuePair = { key: string; value: string };
