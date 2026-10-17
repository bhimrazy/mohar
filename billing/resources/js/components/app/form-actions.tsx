import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type FormActionsProps = {
    /** Buttons: secondary first, primary submit last (right-most). */
    children: ReactNode;
    /** Left-aligned helper, e.g. "Unsaved changes". */
    status?: ReactNode;
    /** Stick to the bottom of the viewport on long forms. */
    sticky?: boolean;
    className?: string;
};

export function FormActions({
    children,
    status,
    sticky = false,
    className,
}: FormActionsProps) {
    return (
        <div
            data-slot="form-actions"
            className={cn(
                'flex flex-wrap items-center justify-end gap-2',
                sticky &&
                    'sticky bottom-0 z-10 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 md:-mx-6 md:px-6',
                className,
            )}
        >
            {status && (
                <div className="mr-auto text-sm text-muted-foreground">
                    {status}
                </div>
            )}
            {children}
        </div>
    );
}
