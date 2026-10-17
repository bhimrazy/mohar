import { formatMoney } from '@/lib/money';
import type { FormatMoneyOptions } from '@/lib/money';
import { cn } from '@/lib/utils';

type MoneyProps = FormatMoneyOptions & {
    /** Integer minor units (cents) exactly as sent by the backend. */
    amount: number | null | undefined;
    currency: string;
    locale?: string;
    /** Mute zero values and colour negatives (ledgers, balances). */
    tone?: 'plain' | 'ledger';
    className?: string;
};

export function Money({
    amount,
    currency,
    locale,
    signed,
    showCode,
    tone = 'plain',
    className,
}: MoneyProps) {
    if (amount === null || amount === undefined) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            data-slot="money"
            className={cn(
                'whitespace-nowrap tabular-nums',
                tone === 'ledger' && amount === 0 && 'text-muted-foreground',
                tone === 'ledger' &&
                    amount < 0 &&
                    'text-red-700 dark:text-red-400',
                className,
            )}
        >
            {formatMoney(amount, currency, locale, { signed, showCode })}
        </span>
    );
}
