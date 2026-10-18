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
            currencyDisplay: 'narrowSymbol',
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
    /** Append the ISO code, e.g. "$1,200.00 USD" (rare: where two currencies sit side by side). */
    showCode?: boolean;
};

/**
 * A currency's display settings from Admin → Currencies, shared by the server as the `currencyFormats`
 * page prop. Mirrors App\Data\Currencies\CurrencyDisplay; the rules below mirror
 * App\Support\Currencies\MoneyFormatter so pages, PDFs and mail agree.
 */
export type CurrencyFormat = {
    code: string;
    prefix: string;
    suffix: string;
    decimals: number;
    /** "1234.56" | "1,234.56" | "1.234,56" | "1 234,56" | "1,23,456.78" (Indian lakh grouping). */
    format: string;
};

/** True minus sign (U+2212), so negatives align in tabular columns. */
const MINUS = '\u2212';

const configured = new Map<string, CurrencyFormat>();

/** Load the shared currency display settings (called from app.tsx on every page). */
export function configureCurrencies(
    formats: CurrencyFormat[] | null | undefined,
): void {
    if (!Array.isArray(formats)) {
        return;
    }

    configured.clear();

    for (const format of formats) {
        configured.set(format.code.toUpperCase(), format);
    }
}

export function currencyFormat(currency: string): CurrencyFormat | null {
    return configured.get(currency.toUpperCase()) ?? null;
}

/**
 * The symbol shown with amounts, e.g. "$", "₹", "Rs" (input adornments, compact labels).
 * Falls back to the Intl narrow symbol, then the ISO code.
 */
export function currencySymbol(currency: string): string {
    const display = currencyFormat(currency);

    if (display) {
        return display.prefix.trim() || display.suffix.trim() || display.code;
    }

    const parts = getFormatter(
        currency.toUpperCase(),
        localeForCurrency(currency),
        'auto',
    )?.formatToParts(0);

    return (
        parts?.find((part) => part.type === 'currency')?.value ??
        currency.toUpperCase()
    );
}

/**
 * A form label for an amount entered in a currency: "Amount (Rs, NPR)", or "Amount (CHF)" when the
 * symbol is the code itself.
 */
export function labelWithCurrency(label: string, currency: string): string {
    const code = currency.toUpperCase();
    const symbol = currencySymbol(code);

    return symbol === code
        ? `${label} (${code})`
        : `${label} (${symbol}, ${code})`;
}

/**
 * Grouping convention for currencies that are not configured: INR and NPR use Indian lakh/crore
 * grouping (₹12,34,567.89); everything else the default locale.
 */
export function localeForCurrency(currency: string): string {
    return ['INR', 'NPR'].includes(currency.toUpperCase())
        ? 'en-IN'
        : DEFAULT_LOCALE;
}

function indianGrouping(whole: string): string {
    if (whole.length <= 3) {
        return whole;
    }

    const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');

    return `${rest},${whole.slice(-3)}`;
}

/** Group non-negative minor units (always 1/100, ADR 0002) into a display format. */
export function groupNumber(
    amountMinor: number,
    decimals: number,
    format: string,
): string {
    const [thousands, point] =
        format === '1234.56'
            ? ['', '.']
            : format === '1.234,56'
              ? ['.', ',']
              : format === '1 234,56'
                ? [' ', ',']
                : [',', '.'];
    const whole = Math.trunc(amountMinor / MINOR_UNIT_SCALE).toString();
    const grouped =
        thousands === ''
            ? whole
            : format === '1,23,456.78'
              ? indianGrouping(whole)
              : whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);

    if (decimals <= 0) {
        return grouped;
    }

    return `${grouped}${point}${String(amountMinor % MINOR_UNIT_SCALE).padStart(2, '0')}`;
}

function signOf(amountMinor: number, signed: boolean | undefined): string {
    if (amountMinor < 0) {
        return MINUS;
    }

    return signed && amountMinor > 0 ? '+' : '';
}

function withCode(
    formatted: string,
    code: string,
    options: FormatMoneyOptions,
): string {
    // Never print the ISO code twice (a suffix or locale may already show it).
    return options.showCode && !formatted.includes(code)
        ? `${formatted} ${code}`
        : formatted;
}

/**
 * formatMoney(129900, 'USD') → "$1,299.00"
 * formatMoney(12345600, 'NPR') → "Rs 1,23,456.00" (configured prefix and lakh grouping)
 * formatMoney(-120000, 'NPR') → "−Rs 1,200.00"
 * formatMoney(-500, 'EUR', 'de-DE') → "−5,00 €" (an explicit locale formats with Intl)
 *
 * Without a locale the currency's configured display is used; unknown currencies fall back to Intl
 * with the narrow symbol.
 */
export function formatMoney(
    amountMinor: number,
    currency: string,
    locale?: string,
    options: FormatMoneyOptions = {},
): string {
    const code = currency.toUpperCase();
    const display = locale ? null : currencyFormat(code);

    if (display) {
        const formatted = `${signOf(amountMinor, options.signed)}${display.prefix}${groupNumber(Math.abs(amountMinor), display.decimals, display.format)}${display.suffix}`;

        return withCode(formatted, code, options);
    }

    const formatter = getFormatter(
        code,
        locale ?? localeForCurrency(code),
        options.signed ? 'exceptZero' : 'auto',
    );
    const value = fromMinor(amountMinor);
    const formatted = formatter
        ? formatter.format(value)
        : `${value.toFixed(2)} ${code}`;

    // Plain spaces, as the server's MoneyFormatter prints them ("CHF 1,234.50").
    return withCode(
        formatted.replace('-', MINUS).replace(/\u00a0/g, ' '),
        code,
        options,
    );
}

/** Suffixes for compact amounts, largest first: Indian (lakh, crore) and international. */
const INDIAN_UNITS: [number, string][] = [
    [1e7, 'Cr'],
    [1e5, 'L'],
    [1e3, 'K'],
];
const INTERNATIONAL_UNITS: [number, string][] = [
    [1e9, 'B'],
    [1e6, 'M'],
    [1e3, 'K'],
];

/** 11.7 → "11.7", 2.0 → "2": at most one decimal, never locale-dependent. */
function compactNumber(value: number, units: [number, string][]): string {
    for (let index = 0; index < units.length; index++) {
        const [size, suffix] = units[index];

        if (value >= size) {
            const scaled = Math.round((value / size) * 10) / 10;
            const larger = units[index - 1];

            // Rounding can reach the next unit (99,960 → "100K"); show that unit instead ("1L").
            if (larger && scaled * size >= larger[0]) {
                return `${Math.round((value / larger[0]) * 10) / 10}${larger[1]}`;
            }

            return `${scaled}${suffix}`;
        }
    }

    return String(Math.round(value * 10) / 10);
}

/**
 * Compact amounts for chart axes and dense summaries: "$48K", "$1.2M", "₹11.7L", "₹1.2Cr", "Rs 7.5L".
 * Our own suffixes (K/L/Cr for Indian grouping, K/M/B otherwise), never the browser's locale abbreviations
 * (CLDR en-IN writes thousand as "T", which reads as trillion).
 */
/** Compact counts for chart axes: "950", "1.2K", "3.4M" (the product's own units, never Intl's). */
export function formatNumberCompact(value: number): string {
    const number = compactNumber(Math.abs(value), INTERNATIONAL_UNITS);

    return value < 0 ? `${MINUS}${number}` : number;
}

export function formatMoneyCompact(
    amountMinor: number,
    currency: string,
    locale?: string,
): string {
    const code = currency.toUpperCase();
    const display = currencyFormat(code);
    const indian = display
        ? display.format === '1,23,456.78'
        : (locale ?? localeForCurrency(code)) === 'en-IN';
    const number = compactNumber(
        Math.abs(fromMinor(amountMinor)),
        indian ? INDIAN_UNITS : INTERNATIONAL_UNITS,
    );
    const sign = signOf(amountMinor, false);

    return display
        ? `${sign}${display.prefix}${number}${display.suffix}`
        : `${sign}${currencySymbol(code)}${number}`;
}
