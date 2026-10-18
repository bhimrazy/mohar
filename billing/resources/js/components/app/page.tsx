import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PageProps = {
    children: ReactNode;
    /** `narrow` for create/edit forms, `default` for lists/detail pages. */
    size?: 'default' | 'narrow';
    className?: string;
};

/** Standard page container: consistent gutters and vertical rhythm. */
export function Page({ children, size = 'default', className }: PageProps) {
    return (
        <div
            data-slot="page"
            className={cn(
                'mx-auto flex w-full flex-1 flex-col gap-(--page-gap) px-4 py-6 md:px-6 md:py-8',
                size === 'narrow' ? 'max-w-3xl' : 'max-w-7xl',
                className,
            )}
        >
            {children}
        </div>
    );
}
