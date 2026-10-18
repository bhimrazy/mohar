import { ArrowRight } from 'lucide-react';
import { StatusBadge } from '@/components/app/status-badge';
import {
    formatPercentChange,
    formatRate,
    rateInCurrency,
} from '@/lib/exchange-rates';
import type { RateReview } from '@/lib/exchange-rates';
import { currencyFormat, currencySymbol } from '@/lib/money';

/** "$1 = Rs 151.94 → Rs 168.65 (+11.00%)", full precision on hover. */
export function RateChange({
    review,
    defaultCode,
    className,
}: {
    review: RateReview;
    defaultCode: string;
    className?: string;
}) {
    const one = currencyFormat(defaultCode);
    const unit = one
        ? `${one.prefix}1${one.suffix}`
        : `${currencySymbol(defaultCode)}1`;

    return (
        <span
            className={`flex flex-wrap items-center gap-x-2 gap-y-1 tabular-nums ${className ?? ''}`}
            title={`1 ${defaultCode} = ${formatRate(review.current_rate)} → ${formatRate(review.proposed_rate)} ${review.currency}`}
        >
            <span className="whitespace-nowrap">
                {unit} = {rateInCurrency(review.current_rate, review.currency)}
            </span>
            <ArrowRight
                className="size-4 text-muted-foreground"
                aria-label="to"
            />
            <span className="whitespace-nowrap">
                {rateInCurrency(review.proposed_rate, review.currency)}
            </span>
            <StatusBadge
                status="review"
                tone="warning"
                label={formatPercentChange(review.change_percent)}
            />
        </span>
    );
}
