import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DescriptionItem = {
    label: string;
    /** Empty values (null/undefined/'') render as an em dash. */
    value: ReactNode;
    /** Span the full row (addresses, notes). */
    full?: boolean;
};

type DescriptionListProps = {
    items: DescriptionItem[];
    columns?: 1 | 2 | 3;
    className?: string;
};

const columnClasses = {
    1: 'sm:grid-cols-1',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
} as const;

export function DescriptionList({
    items,
    columns = 2,
    className,
}: DescriptionListProps) {
    return (
        <dl
            data-slot="description-list"
            className={cn(
                'grid grid-cols-1 gap-x-6 gap-y-4',
                columnClasses[columns],
                className,
            )}
        >
            {items.map((item) => (
                <div
                    key={item.label}
                    className={cn(
                        'min-w-0 space-y-1',
                        item.full && 'sm:col-span-full',
                    )}
                >
                    <dt className="text-xs font-medium text-muted-foreground">
                        {item.label}
                    </dt>
                    <dd className="text-sm break-words">
                        {item.value === null ||
                        item.value === undefined ||
                        item.value === '' ? (
                            <span className="text-muted-foreground">—</span>
                        ) : (
                            item.value
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
