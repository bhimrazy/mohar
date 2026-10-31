import { DateTime } from '@/components/app/date';
import type { PricedLine } from '@/types/orders';

/**
 * The dates a pro-rated first amount covers, worded like an invoice line's
 * period. Nothing for a line billed for whole cycles.
 */
export function FirstPeriod({ line }: { line: PricedLine }) {
    if (!line.first_period?.prorated) {
        return null;
    }

    return (
        <span>
            Pro-rated <DateTime value={line.first_period.start} /> –{' '}
            <DateTime value={line.first_period.end} />
        </span>
    );
}
