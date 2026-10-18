import { setDateFormat } from '@/lib/date';

/**
 * Applies display settings shared by the server on every response (date format,
 * and with #43 the currency formats) before a page renders, so the first paint
 * and every page after a settings change format values the same way.
 */
export function applySharedFormatting(props: Record<string, unknown>): void {
    setDateFormat(
        typeof props.dateFormat === 'string' ? props.dateFormat : null,
    );
}
