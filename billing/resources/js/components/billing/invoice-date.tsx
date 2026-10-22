import { DateTime } from '@/components/app/date';

type InvoiceDateProps = {
    /** ISO date ("2026-01-12"). */
    value: string | null | undefined;
    /** "28 Poush 2082 BS", sent only when Settings → Billing shows BS dates. */
    bs?: string | null;
};

/** An invoice issue or due date, with the Bikram Sambat date in brackets when the server sends one. */
export function InvoiceDate({ value, bs }: InvoiceDateProps) {
    return (
        <>
            <DateTime value={value} />
            {value && bs && (
                <span className="whitespace-nowrap text-muted-foreground">
                    {' '}
                    ({bs})
                </span>
            )}
        </>
    );
}
