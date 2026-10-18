/**
 * Money helpers. The backend always sends integer minor units (cents) plus an
 * ISO 4217 currency code. Never do arithmetic on formatted strings or floats.
 */

export const DEFAULT_LOCALE = 'en-US';

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

/**
 * Strictly parse what a person typed into a money field → minor units, or null when the input is not a
 * well-formed amount. Accepts an optional leading minus, thousands separators in US (1,299.10) or Indian
 * (1,00,000.50) grouping, and at most two decimals. Rejects letters, symbols, "12,50", "1.2.3", "1.999".
 * Never "repair" input: a rejected value must surface as a validation error, not a different price.
 */
export function parseMoneyInput(input: string): number | null {
    const value = input.trim();

    if (
        !/^-?(\d+|\d{1,3}(,\d{3})+|\d{1,2}(,\d{2})*,\d{3})(\.\d{1,2})?$/.test(
            value,
        )
    ) {
        return null;
    }

    const [whole, fraction = ''] = value.replace(/,/g, '').split('.');
    const negative = whole.startsWith('-');
    const minor =
        Number(whole.replace('-', '')) * MINOR_UNIT_SCALE +
        Number(fraction.padEnd(2, '0'));

    return Number.isSafeInteger(minor) ? (negative ? -minor : minor) : null;
}

/** 19.99 → 1999. Strings go through parseMoneyInput(); invalid input yields NaN (callers must check). */
export function toMinor(value: string | number): number {
    if (typeof value === 'number') {
        return Number.isFinite(value)
            ? Math.round(value * MINOR_UNIT_SCALE)
            : Number.NaN;
    }

    return parseMoneyInput(value) ?? Number.NaN;
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
 */
/**
 * Grouping convention per currency when no locale is given: INR and NPR use Indian lakh/crore grouping
 * (₹12,34,567.89); everything else the default locale.
 */
export function localeForCurrency(currency: string): string {
    return ['INR', 'NPR'].includes(currency.toUpperCase())
        ? 'en-IN'
        : DEFAULT_LOCALE;
}

export function formatMoney(
    amountMinor: number,
    currency: string,
    locale?: string,
    options: FormatMoneyOptions = {},
): string {
    const code = currency.toUpperCase();
    const formatter = getFormatter(
        code,
        locale ?? localeForCurrency(code),
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
