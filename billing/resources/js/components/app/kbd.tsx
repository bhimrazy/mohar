import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Keyboard shortcut hint, e.g. <Kbd>⌘K</Kbd>. */
export function Kbd({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <kbd
            data-slot="kbd"
            className={cn(
                'pointer-events-none inline-flex h-5 min-w-5 items-center justify-center gap-0.5 rounded-sm border bg-muted px-1 font-sans text-[0.6875rem] font-medium text-muted-foreground select-none',
                className,
            )}
        >
            {children}
        </kbd>
    );
}
