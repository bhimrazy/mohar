/** What the customer told the registry for a domain, as it will be sent (ID numbers shortened). */
export function ItemDetails({
    details,
}: {
    details?: { label: string; value: string }[];
}) {
    if (!details || details.length === 0) {
        return null;
    }

    return (
        <dl className="mt-2 grid gap-0.5 text-xs text-muted-foreground">
            {details.map((detail) => (
                <div key={detail.label} className="flex flex-wrap gap-x-1">
                    <dt>{detail.label}:</dt>
                    <dd className="text-foreground">{detail.value}</dd>
                </div>
            ))}
        </dl>
    );
}
