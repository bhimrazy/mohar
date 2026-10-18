import { currencyFormat, currencySymbol, groupNumber } from '@/lib/money';

/** A fetched rate held for staff approval (App\Modules\Platform\Models\ExchangeRateReview). */
export type RateReview = {
    id: number;
    currency: string;
    current_rate: string;
    proposed_rate: string;
    change_percent: string;
    source: string;
    effective_at: string;
    created_at: string | null;
};

/**
 * "134.400000000000" → "134.4": exchange rates are exact decimal strings from the server; only trailing zeros
 * are trimmed (no float maths). Full precision, for tooltips and inputs.
 */
export function formatRate(rate: string): string {
    if (!rate.includes('.')) {
        return rate;
    }

    const trimmed = rate.replace(/0+$/, '').replace(/\.$/, '');
    const [whole, fraction] = trimmed.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return fraction ? `${grouped}.${fraction}` : grouped;
}

/**
 * Round a decimal string half up to at most `places` decimals, keeping at least two ("151.94", "94.964898",
 * "0.006582"), without floats.
 */
export function roundRate(rate: string, places = 6): string {
    const negative = rate.startsWith('-');
    const [whole = '0', fraction = ''] = rate.replace(/^[-+]/, '').split('.');
    let digits = (whole + fraction.padEnd(places + 1, '0').slice(0, places))
        .replace(/^0+(?=\d)/, '')
        .split('')
        .map(Number);

    if (Number(fraction.padEnd(places + 1, '0')[places]) >= 5) {
        let index = digits.length - 1;

        while (index >= 0) {
            if (digits[index] === 9) {
                digits[index] = 0;
                index--;
            } else {
                digits[index]++;
                break;
            }
        }

        if (index < 0) {
            digits = [1, ...digits];
        }
    }

    const text = digits.join('').padStart(places + 1, '0');
    let decimals = text.slice(-places).replace(/0+$/, '');

    if (decimals.length < 2) {
        decimals = decimals.padEnd(2, '0');
    }

    return `${negative ? '-' : ''}${text.slice(0, -places)}.${decimals}`;
}

/** "Rs 151.94" — a rate shown in the quoted currency's symbol, at most six decimals. */
export function rateInCurrency(rate: string, currency: string): string {
    const display = currencyFormat(currency);
    const [whole, fraction] = roundRate(rate).split('.');
    const grouped = groupNumber(
        Number(whole) * 100,
        0,
        display?.format ?? '1,234.56',
    );
    const number = `${grouped}.${fraction}`;

    return display
        ? `${display.prefix}${number}${display.suffix}`
        : `${currencySymbol(currency)}${number}`;
}

/** "$1 = Rs 151.94": one unit of the default currency in the quoted currency. */
export function ratePair(rate: string, from: string, to: string): string {
    const one = currencyFormat(from);
    const unit = one
        ? `${one.prefix}1${one.suffix}`
        : `${currencySymbol(from)}1`;

    return `${unit} = ${rateInCurrency(rate, to)}`;
}

/** "11.6071" → "+11.61%", "-3.5" → "−3.50%". */
export function formatPercentChange(percent: string): string {
    const value = Number(percent);
    const sign = value > 0 ? '+' : value < 0 ? '−' : '';

    return `${sign}${Math.abs(value).toFixed(2)}%`;
}

/** Whole days since an ISO timestamp (for "Not updated for 12 days"). */
export function daysSince(
    iso: string | null,
    now: Date = new Date(),
): number | null {
    if (!iso) {
        return null;
    }

    return Math.max(
        0,
        Math.floor((now.getTime() - new Date(iso).getTime()) / 86_400_000),
    );
}
