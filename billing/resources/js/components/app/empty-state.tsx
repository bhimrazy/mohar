import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    icon?: LucideIcon;
    title: string;
    description?: ReactNode;
    /** Usually one <Button>; keep it to the single most useful next step. */
    action?: ReactNode;
    /** `bordered` for standalone use; `plain` inside an existing card/table. */
    variant?: 'plain' | 'bordered';
    className?: string;
};

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    variant = 'plain',
    className,
}: EmptyStateProps) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
                variant === 'bordered' && 'rounded-xl border border-dashed',
                className,
            )}
        >
            {Icon && (
                <div className="flex size-10 items-center justify-center rounded-lg border bg-muted/50 text-muted-foreground">
                    <Icon className="size-5" aria-hidden="true" />
                </div>
            )}
            <div className="max-w-sm space-y-1">
                <h3 className="text-sm font-medium">{title}</h3>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action && <div className="mt-1">{action}</div>}
        </div>
    );
}
