import { fromMinor, parseMoneyInput } from '@/lib/money';

/**
 * Strictly parse a non-negative money input ("1,250.5" → "1250.50") for forms that post decimal
 * strings; null when the input is not a plain amount (e.g. "1e3", "12.345", "-5").
 */
export function decimalAmount(input: string): string | null {
    const minor = parseMoneyInput(input);

    if (minor === null || minor < 0) {
        return null;
    }

    return fromMinor(minor).toFixed(2);
}
