import { setDateFormat } from '@/lib/date';
import { configureCurrencies } from '@/lib/money';
import type { CurrencyFormat } from '@/lib/money';

/**
 * Applies display settings shared by the server on every response (date format
 * and currency formats) before a page renders, so the first paint
 * and every page after a settings change format values the same way.
 */
export function applySharedFormatting(props: Record<string, unknown>): void {
    setDateFormat(
        typeof props.dateFormat === 'string' ? props.dateFormat : null,
    );
    configureCurrencies(
        Array.isArray(props.currencyFormats)
            ? (props.currencyFormats as CurrencyFormat[])
            : undefined,
    );
}
