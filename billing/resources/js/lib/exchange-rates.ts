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
 * "134.400000" → "134.4", "0.920000" → "0.92": exchange rates are exact decimal strings from the server;
 * only trailing zeros are trimmed (no float maths).
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

/** "11.6071" → "+11.61%", "-3.5" → "−3.50%". */
export function formatPercentChange(percent: string): string {
    const value = Number(percent);
    const sign = value > 0 ? '+' : value < 0 ? '−' : '';

    return `${sign}${Math.abs(value).toFixed(2)}%`;
}
