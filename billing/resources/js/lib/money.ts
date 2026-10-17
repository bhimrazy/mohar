/**
 * Money helpers. The backend always sends integer minor units (cents) plus an
 * ISO 4217 currency code. Never do arithmetic on formatted strings or floats.
 */

export const DEFAULT_LOCALE = 'en-US';

/** Display locale per currency when the caller does not pass one (INR uses lakh grouping). */
const CURRENCY_LOCALES: Record<string, string> = {
    INR: 'en-IN',
    NPR: 'en-IN',
};

export function localeForCurrency(currency: string): string {
    return CURRENCY_LOCALES[currency.toUpperCase()] ?? DEFAULT_LOCALE;
}

const formatters = new Map<string, Intl.NumberFormat>();

function getFormatter(
    currency: string,
    locale: string,
    signDisplay: Intl.NumberFormatOptions['signDisplay'],
): Intl.NumberFormat | null {
    const key = `${locale}|${currency}|${signDisplay}`;
    const cached = formatters.get(key);

    if (cached) {
        return cached;
    }

    try {
        const formatter = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            signDisplay,
        });
        formatters.set(key, formatter);

        return formatter;
    } catch {
        return null;
    }
}

/**
 * Minor units per major unit. ADR 0002: every amount is stored in 1/100 units
 * (zero/three-decimal currencies are out of scope for v1).
 */
export const MINOR_UNIT_SCALE = 100;

/** 1999 → 19.99 */
export function fromMinor(amountMinor: number): number {
    return amountMinor / MINOR_UNIT_SCALE;
}

/** "19.99" | "$1,299.10" | 19.99 → minor units. NaN if unparsable. */
export function toMinor(value: string | number): number {
    const numeric =
        typeof value === 'number'
            ? value
            : Number(value.replace(/[^0-9.-]/g, ''));

    return Math.round(numeric * MINOR_UNIT_SCALE);
}

export type FormatMoneyOptions = {
    /** Prefix positive values with "+" (credit ledgers, adjustments). */
    signed?: boolean;
    /** Append the ISO code, e.g. "$1,200.00 USD" (multi-currency views). */
    showCode?: boolean;
};

/**
 * formatMoney(129900, 'USD') → "$1,299.00"
 * formatMoney(-500, 'EUR', 'de-DE') → "-5,00 €"
 * formatMoney(10000000, 'INR') → "₹1,00,000.00" (locale defaults per currency)
 */
export function formatMoney(
    amountMinor: number,
    currency: string,
    locale: string = localeForCurrency(currency),
    options: FormatMoneyOptions = {},
): string {
    const code = currency.toUpperCase();
    const formatter = getFormatter(
        code,
        locale,
        options.signed ? 'exceptZero' : 'auto',
    );
    const value = fromMinor(amountMinor);
    const formatted = formatter
        ? formatter.format(value)
        : `${value.toFixed(2)} ${code}`;

    // Use a true minus sign (U+2212) so negatives align in tabular columns.
    const normalized = formatted.replace('-', '−');

    return options.showCode && formatter ? `${normalized} ${code}` : normalized;
}
