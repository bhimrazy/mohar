import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type SectionCardProps = {
    title?: ReactNode;
    description?: ReactNode;
    /** Small header actions (e.g. an "Edit" ghost button). */
    actions?: ReactNode;
    /** Right-aligned footer, typically form buttons. */
    footer?: ReactNode;
    children: ReactNode;
    /** Remove content padding, e.g. when the content is a table. */
    flush?: boolean;
    className?: string;
    contentClassName?: string;
};

export function SectionCard({
    title,
    description,
    actions,
    footer,
    children,
    flush = false,
    className,
    contentClassName,
}: SectionCardProps) {
    const hasHeader = title || description || actions;

    return (
        <section
            data-slot="section-card"
            className={cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            {hasHeader && (
                <div
                    className={cn(
                        'flex items-start justify-between gap-4 px-4 pt-4 sm:px-(--card-p) sm:pt-5',
                        flush && 'border-b pb-4',
                    )}
                >
                    <div className="min-w-0 space-y-1">
                        {title && (
                            <h2 className="font-display text-base">{title}</h2>
                        )}
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex shrink-0 items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}

            <div
                className={cn(
                    !flush && 'p-4 sm:px-(--card-p) sm:pb-(--card-p)',
                    !flush && hasHeader && 'pt-4 sm:pt-5',
                    contentClassName,
                )}
            >
                {children}
            </div>

            {footer && (
                <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/40 px-4 py-3 sm:px-(--card-p)">
                    {footer}
                </div>
            )}
        </section>
    );
}
